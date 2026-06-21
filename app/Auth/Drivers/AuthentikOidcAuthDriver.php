<?php

namespace App\Auth\Drivers;

use App\Contracts\AuthDriverInterface;
use App\Services\Auth\Oidc\Exceptions\OidcAccessDeniedException;
use App\Services\Auth\Oidc\OidcProviderConfig;
use App\Services\Auth\Oidc\OidcReconciliationService;
use App\Services\Auth\Oidc\ValidatedClaims;
use Throwable;

class AuthentikOidcAuthDriver implements AuthDriverInterface
{
    public function __construct(
        private readonly OidcReconciliationService $reconciler,
        private readonly OidcProviderConfig $config,
    ) {}

    public function name(): string
    {
        return 'authentik-oidc';
    }

    public function isAvailable(): bool
    {
        return $this->config->isPubliclyAvailable();
    }

    public function authenticate(array $credentials): AuthDriverResult
    {
        $claims = $credentials['claims'] ?? null;
        if (! $claims instanceof ValidatedClaims) {
            throw new AuthDriverException(
                'Malformed credentials: expected ValidatedClaims under "claims" key',
                AuthDriverException::CODE_MALFORMED_CREDENTIALS,
                $this->name(),
            );
        }

        try {
            $result = $this->reconciler->reconcile($claims);
        } catch (OidcAccessDeniedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AuthDriverException(
                'OIDC reconciliation failed',
                AuthDriverException::CODE_INVALID_CREDENTIALS,
                $this->name(),
                $e,
            );
        }

        return new AuthDriverResult(
            user: $result['user'],
            driverName: $this->name(),
            mustChangePassword: false,
            providerSubject: $claims->sub,
            providerClaims: [
                'email' => $claims->email,
                'name' => $claims->name,
                'groups' => $claims->groups,
                'reason' => $result['reason'],
            ],
        );
    }
}
