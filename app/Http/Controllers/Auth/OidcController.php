<?php

namespace App\Http\Controllers\Auth;

use App\Auth\AuthDriverRegistry;
use App\Http\Controllers\Controller;
use App\Security\Network\OidcUrlPolicy;
use App\Security\Network\UnsafeOidcUrl;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\AccountSessionService;
use App\Services\Auth\Oidc\Exceptions\OidcAccessDeniedException;
use App\Services\Auth\Oidc\Exceptions\OidcException;
use App\Services\Auth\Oidc\Exceptions\OidcTokenInvalidException;
use App\Services\Auth\Oidc\OidcDiscoveryService;
use App\Services\Auth\Oidc\OidcHandshakeStore;
use App\Services\Auth\Oidc\OidcHttpClient;
use App\Services\Auth\Oidc\OidcProviderConfig;
use App\Services\Auth\Oidc\OidcTokenValidator;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class OidcController extends Controller
{
    public function redirect(
        Request $request,
        OidcHandshakeStore $store,
        OidcDiscoveryService $discovery,
        OidcProviderConfig $config,
        OidcUrlPolicy $urlPolicy,
    ): Response {
        $this->ensureEnabled($config);

        try {
            $urlPolicy->assertAllowedRedirectUri($config->redirectUri());
            $authorize = $discovery->authorizationEndpoint();
        } catch (UnsafeOidcUrl $exception) {
            return $this->fail($exception->reason, $exception);
        } catch (OidcException $exception) {
            return $this->fail($exception->reason, $exception);
        }

        $nonce = Str::random(32);
        $verifier = $this->codeVerifier();
        $purpose = $request->routeIs('auth.oidc.step-up') ? 'step_up' : 'login';
        if ($purpose === 'step_up' && $request->user() === null) {
            abort(401);
        }
        $state = $store->putState([
            'nonce' => $nonce,
            'code_verifier' => $verifier,
            'purpose' => $purpose,
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);

        $params = [
            'response_type' => 'code',
            'client_id' => $config->clientId(),
            'redirect_uri' => $config->redirectUri(),
            'scope' => implode(' ', $config->scopes()),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->codeChallenge($verifier),
            'code_challenge_method' => 'S256',
        ];
        if ($purpose === 'step_up') {
            $params['prompt'] = 'login';
            $params['max_age'] = '0';
        }

        $separator = str_contains($authorize, '?') ? '&' : '?';

        return redirect()->away($authorize.$separator.http_build_query($params));
    }

    public function callback(
        Request $request,
        OidcHandshakeStore $store,
        OidcDiscoveryService $discovery,
        OidcTokenValidator $validator,
        AuthDriverRegistry $registry,
        OidcProviderConfig $config,
        OidcHttpClient $http,
        OidcUrlPolicy $urlPolicy,
        AccountSessionService $sessions,
        StepUpAuthenticationService $stepUp,
    ): RedirectResponse {
        $this->ensureEnabled($config);

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        if ($state === '' || $code === '') {
            $this->auditFailure('missing_parameters', $request);
            abort(400, 'missing_parameters');
        }

        $meta = $store->consumeState($state);
        if ($meta === null) {
            $this->auditFailure('unknown_state', $request);
            abort(400, 'unknown_state');
        }

        try {
            $urlPolicy->assertAllowedRedirectUri($config->redirectUri());
            $tokenResponse = $http->postFormJson($discovery->tokenEndpoint(), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $config->redirectUri(),
                'client_id' => $config->clientId(),
                'client_secret' => $config->clientSecret(),
                'code_verifier' => $meta['code_verifier'],
            ], 'token');
        } catch (UnsafeOidcUrl $exception) {
            return $this->fail($exception->reason, $exception);
        } catch (OidcException $exception) {
            return $this->fail($exception->reason, $exception);
        }

        $idToken = (string) ($tokenResponse['id_token'] ?? '');
        if ($idToken === '') {
            return $this->fail('missing_id_token', null);
        }

        try {
            $claims = $validator->validate($idToken, $meta['nonce']);
        } catch (OidcTokenInvalidException $e) {
            return $this->fail($e->reason, $e);
        }

        try {
            $result = $registry->driver('authentik-oidc')->authenticate(['claims' => $claims]);
        } catch (OidcAccessDeniedException $e) {
            return $this->fail($e->reason, $e);
        }

        $user = $result->user;

        if (($meta['purpose'] ?? 'login') === 'step_up') {
            $expectedUserId = (int) ($meta['user_id'] ?? 0);
            if ($request->user() === null || $expectedUserId < 1
                || (int) $request->user()->getAuthIdentifier() !== $expectedUserId
                || (int) $user->getAuthIdentifier() !== $expectedUserId) {
                $this->auditFailure('step_up_identity_mismatch', $request);

                return redirect()->route('password.confirm')
                    ->with('status', 'Enterprise reauthentication must use the same Zephyrus identity.');
            }

            if (! $stepUp->markOidcMfa($request, $claims)) {
                $this->auditFailure('step_up_mfa_required', $request);

                return redirect()->route('password.confirm')
                    ->with('status', 'The identity provider did not return recent MFA evidence.');
            }

            return redirect()->intended(route('dashboard'));
        }

        // Mirror AuthenticatedSessionController::store session setup (web guard).
        $request->attributes->set(UserAuditRecorder::AUTH_METHOD_ATTRIBUTE, 'oidc');
        $request->attributes->set(UserAuditRecorder::SOURCE_SURFACE_ATTRIBUTE, 'web');
        Auth::guard('web')->login($user, remember: false);
        $request->session()->regenerate();
        if ($user->workflow_preference === null) {
            $user->update(['workflow_preference' => 'superuser']);
        }
        $sessions->establish($request, $user);
        $stepUp->markOidcMfa($request, $claims);

        return redirect()->intended(route('dashboard'));
    }

    private function ensureEnabled(OidcProviderConfig $config): void
    {
        if (! $config->isPubliclyAvailable()) {
            abort(404);
        }
    }

    private function fail(string $reason, ?\Throwable $e): RedirectResponse
    {
        $this->auditFailure($reason, request());

        if ($e !== null) {
            Log::warning('OIDC failure', ['reason' => $reason, 'exception' => $e::class, 'message' => $e->getMessage()]);
        }

        return redirect()->route('login')->with('status', 'Single sign-on failed. Please try again or use your password.');
    }

    private function auditFailure(string $reason, Request $request): void
    {
        app(UserAuditRecorder::class)->bestEffort('auth.login', 'authentication', 'failure', [
            'request' => $request,
            'reason' => $reason,
            'auth_method' => 'oidc',
            'source_surface' => 'web',
        ]);
    }

    private function codeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    private function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
