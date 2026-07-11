<?php

namespace App\Http\Controllers\Auth;

use App\Auth\AuthDriverRegistry;
use App\Http\Controllers\Controller;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\Oidc\Exceptions\OidcAccessDeniedException;
use App\Services\Auth\Oidc\Exceptions\OidcException;
use App\Services\Auth\Oidc\Exceptions\OidcTokenInvalidException;
use App\Services\Auth\Oidc\OidcDiscoveryService;
use App\Services\Auth\Oidc\OidcHandshakeStore;
use App\Services\Auth\Oidc\OidcProviderConfig;
use App\Services\Auth\Oidc\OidcTokenValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class OidcController extends Controller
{
    public function redirect(
        OidcHandshakeStore $store,
        OidcDiscoveryService $discovery,
        OidcProviderConfig $config,
    ): Response {
        $this->ensureEnabled($config);

        try {
            $authorize = $discovery->authorizationEndpoint();
        } catch (OidcException $e) {
            return $this->fail('discovery_failed', $e);
        }

        $nonce = Str::random(32);
        $verifier = $this->codeVerifier();
        $state = $store->putState(['nonce' => $nonce, 'code_verifier' => $verifier]);

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

        return redirect()->away($authorize.'?'.http_build_query($params));
    }

    public function callback(
        Request $request,
        OidcHandshakeStore $store,
        OidcDiscoveryService $discovery,
        OidcTokenValidator $validator,
        AuthDriverRegistry $registry,
        OidcProviderConfig $config,
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
            $tokenResponse = Http::asForm()->post($discovery->tokenEndpoint(), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $config->redirectUri(),
                'client_id' => $config->clientId(),
                'client_secret' => $config->clientSecret(),
                'code_verifier' => $meta['code_verifier'],
            ]);
        } catch (\Throwable $e) {
            return $this->fail('token_exchange_failed', $e);
        }

        if ($tokenResponse->failed()) {
            return $this->fail('token_exchange_failed', null);
        }

        $idToken = (string) ($tokenResponse->json('id_token') ?? '');
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

        // Mirror AuthenticatedSessionController::store session setup (web guard).
        $request->attributes->set(UserAuditRecorder::AUTH_METHOD_ATTRIBUTE, 'oidc');
        $request->attributes->set(UserAuditRecorder::SOURCE_SURFACE_ATTRIBUTE, 'web');
        Auth::guard('web')->login($user, remember: false);
        $request->session()->regenerate();
        $request->session()->put('username', $user->username);
        if ($user->workflow_preference === null) {
            $user->update(['workflow_preference' => 'superuser']);
        }
        $request->session()->put('user_id', $user->id);

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
