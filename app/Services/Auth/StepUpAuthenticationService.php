<?php

namespace App\Services\Auth;

use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\Oidc\ValidatedClaims;
use Illuminate\Http\Request;

final class StepUpAuthenticationService
{
    public const VERIFIED_AT = 'auth.step_up.verified_at';

    public const METHOD = 'auth.step_up.method';

    public const AUTHENTICATION_CONTEXT = 'auth.step_up.context';

    public function __construct(private readonly UserAuditRecorder $audit) {}

    public function isSatisfied(Request $request): bool
    {
        if ($request->user() === null || ! $request->hasSession()) {
            return false;
        }

        $verifiedAt = $request->session()->get(self::VERIFIED_AT);
        $method = $request->session()->get(self::METHOD);
        if (! is_int($verifiedAt) && ! ctype_digit((string) $verifiedAt)) {
            return false;
        }
        if (! in_array($method, ['password', 'oidc_mfa'], true)) {
            return false;
        }

        $age = time() - (int) $verifiedAt;

        return $age >= 0 && $age <= (int) config('security.step_up.ttl_seconds', 600);
    }

    public function assertSatisfied(Request $request, string $reason): void
    {
        if ($this->isSatisfied($request)) {
            return;
        }

        $this->audit->bestEffort('security.step_up.required', 'authorization', 'challenge', [
            'request' => $request,
            'reason' => $reason,
            'http_status' => $request->expectsJson() ? 428 : 302,
        ]);

        throw new StepUpRequired;
    }

    public function markPasswordConfirmed(Request $request): void
    {
        $this->mark($request, 'password', 'local_password_reauthentication');
        $request->session()->put('auth.password_confirmed_at', time());
    }

    public function markOidcMfa(Request $request, ValidatedClaims $claims): bool
    {
        $acceptedAmr = array_map('strtolower', (array) config('security.step_up.oidc_mfa_amr', []));
        $acceptedAcr = (array) config('security.step_up.oidc_mfa_acr', []);
        $amr = array_map('strtolower', $claims->amr);
        $strongMethod = array_intersect($amr, $acceptedAmr) !== []
            || ($claims->acr !== null && in_array($claims->acr, $acceptedAcr, true));

        if (! $strongMethod || $claims->authTime === null) {
            return false;
        }

        $age = time() - $claims->authTime;
        if ($age < 0 || $age > (int) config('security.step_up.oidc_auth_time_max_age_seconds', 300)) {
            return false;
        }

        $context = $claims->acr ?? implode(',', array_values(array_intersect($amr, $acceptedAmr)));
        $this->mark($request, 'oidc_mfa', $context);

        return true;
    }

    private function mark(Request $request, string $method, string $context): void
    {
        $request->session()->put([
            self::VERIFIED_AT => time(),
            self::METHOD => $method,
            self::AUTHENTICATION_CONTEXT => $context,
        ]);

        $this->audit->bestEffort('security.step_up.completed', 'authentication', 'success', [
            'request' => $request,
            'auth_method' => $method === 'oidc_mfa' ? 'oidc' : 'password',
            'metadata' => ['step_up_method' => $method],
        ]);
    }
}
