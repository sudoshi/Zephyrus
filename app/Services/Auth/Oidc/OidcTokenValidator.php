<?php

namespace App\Services\Auth\Oidc;

use App\Services\Auth\Oidc\Exceptions\OidcTokenInvalidException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

class OidcTokenValidator
{
    public function __construct(
        private readonly OidcDiscoveryService $discovery,
        private readonly string $audience,
    ) {}

    public function validate(string $idToken, ?string $expectedNonce = null): ValidatedClaims
    {
        $keys = JWK::parseKeySet($this->discovery->jwks());
        JWT::$leeway = 30;

        try {
            $payload = (array) JWT::decode($idToken, $keys);
        } catch (\Throwable $e) {
            throw new OidcTokenInvalidException('signature_invalid', $e->getMessage(), $e);
        }

        if (! isset($payload['exp']) || ! is_numeric($payload['exp'])) {
            throw new OidcTokenInvalidException('missing_claim', "Required claim 'exp' missing or non-numeric");
        }

        $issuer = (string) ($payload['iss'] ?? '');
        if ($issuer !== $this->discovery->issuer()) {
            throw new OidcTokenInvalidException('issuer_mismatch', "Expected '{$this->discovery->issuer()}', got '{$issuer}'");
        }

        $audience = $payload['aud'] ?? null;
        $audienceList = is_array($audience) ? array_map('strval', $audience) : [(string) $audience];
        if (! in_array($this->audience, $audienceList, true)) {
            throw new OidcTokenInvalidException('audience_mismatch', "Token audience does not include '{$this->audience}'");
        }

        if ($expectedNonce !== null) {
            $tokenNonce = (string) ($payload['nonce'] ?? '');
            if (! hash_equals($expectedNonce, $tokenNonce)) {
                throw new OidcTokenInvalidException('nonce_mismatch', 'Token nonce does not match stored nonce');
            }
        }

        foreach (['sub', 'email', 'name'] as $required) {
            if (! isset($payload[$required]) || ! is_string($payload[$required]) || $payload[$required] === '') {
                throw new OidcTokenInvalidException('missing_claim', "Required claim '{$required}' missing or empty");
            }
        }

        $groups = [];
        if (isset($payload['groups']) && is_array($payload['groups'])) {
            foreach ($payload['groups'] as $group) {
                if (is_string($group)) {
                    $groups[] = $group;
                }
            }
        }

        return new ValidatedClaims(
            sub: (string) $payload['sub'],
            email: (string) $payload['email'],
            name: (string) $payload['name'],
            groups: $groups,
        );
    }
}
