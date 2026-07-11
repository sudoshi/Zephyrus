<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;

class AuditAuthenticationEvent
{
    private const REQUEST_EVENTS_ATTRIBUTE = '_zephyrus_audited_auth_events';

    public function __construct(private readonly UserAuditRecorder $recorder) {}

    public function handle(Login|Failed|Logout|Lockout|Registered|PasswordReset $event): void
    {
        $request = $event instanceof Lockout ? $event->request : $this->request();
        [$action, $outcome, $reason] = $this->classification($event);

        if ($this->alreadyHandled($request, $action.':'.$outcome.':'.($reason ?? 'none'))) {
            return;
        }

        $actor = property_exists($event, 'user') && $event->user instanceof User
            ? $event->user
            : null;

        $principal = null;
        if ($event instanceof Failed) {
            $candidate = $event->credentials['username'] ?? $event->credentials['email'] ?? null;
            $principal = is_scalar($candidate) ? (string) $candidate : null;
        } elseif ($event instanceof Lockout) {
            $candidate = $event->request->input('username', $event->request->input('email'));
            $principal = is_scalar($candidate) ? (string) $candidate : null;
        }

        $context = [
            'request' => $request,
            'actor' => $actor,
            'principal' => $principal,
            'reason' => $reason,
            'auth_method' => $this->authMethod($event, $request),
            'source_surface' => 'web',
            'metadata' => [],
        ];

        if ($event instanceof Login) {
            $context['metadata'] = [
                'guard' => (string) $event->guard,
                'remembered' => (bool) $event->remember,
            ];
        } elseif ($event instanceof Failed || $event instanceof Logout) {
            $context['metadata'] = ['guard' => (string) $event->guard];
        }

        $this->recorder->bestEffort($action, 'authentication', $outcome, $context);
    }

    /** @return array{string, string, ?string} */
    private function classification(Login|Failed|Logout|Lockout|Registered|PasswordReset $event): array
    {
        return match (true) {
            $event instanceof Login => ['auth.login', 'success', null],
            $event instanceof Failed => ['auth.login', 'failure', 'invalid_credentials'],
            $event instanceof Logout => ['auth.logout', 'success', null],
            $event instanceof Lockout => ['auth.login', 'denied', 'rate_limited'],
            $event instanceof Registered => ['auth.registration', 'success', null],
            $event instanceof PasswordReset => ['auth.password_reset', 'success', null],
        };
    }

    private function authMethod(
        Login|Failed|Logout|Lockout|Registered|PasswordReset $event,
        ?Request $request,
    ): string {
        $explicit = $request?->attributes->get(UserAuditRecorder::AUTH_METHOD_ATTRIBUTE);
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return $event instanceof Logout ? 'session' : 'password';
    }

    private function alreadyHandled(?Request $request, string $key): bool
    {
        if ($request === null) {
            return false;
        }

        $handled = $request->attributes->get(self::REQUEST_EVENTS_ATTRIBUTE, []);
        if (! is_array($handled)) {
            $handled = [];
        }
        if (in_array($key, $handled, true)) {
            return true;
        }

        $handled[] = $key;
        $request->attributes->set(self::REQUEST_EVENTS_ATTRIBUTE, $handled);

        return false;
    }

    private function request(): ?Request
    {
        return app()->bound('request') && app('request') instanceof Request ? app('request') : null;
    }
}
