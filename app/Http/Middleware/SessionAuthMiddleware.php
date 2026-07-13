<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\AccountSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SessionAuthMiddleware
{
    public function __construct(private readonly AccountSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            return $next($request);
        }

        if ($this->autoLoginIsForbidden()) {
            return redirect()->guest(route('login'));
        }

        $username = trim((string) config('demo.auto_login_username'));
        $user = $username === ''
            ? null
            : User::query()->where('username', $username)->first();

        if (! $user || ! $user->is_active || $user->must_change_password) {
            Log::warning('Configured demo auto-login account is unavailable.', [
                'username_configured' => $username !== '',
                'account_found' => (bool) $user,
                'account_active' => (bool) ($user?->is_active ?? false),
                'password_change_required' => (bool) ($user?->must_change_password ?? false),
            ]);

            return redirect()->guest(route('login'));
        }

        $request->attributes->set(UserAuditRecorder::AUTH_METHOD_ATTRIBUTE, 'demo');
        $request->attributes->set(UserAuditRecorder::SOURCE_SURFACE_ATTRIBUTE, 'web');

        Auth::login($user);
        $request->session()->put('workflow', $user->workflow_preference ?: 'superuser');
        $this->sessions->establish($request, $user);

        return $next($request);
    }

    private function autoLoginIsForbidden(): bool
    {
        return app()->environment('production')
            || ! (bool) config('demo.auto_login_enabled', false);
    }
}
