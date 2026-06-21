<?php

namespace Tests\Unit\Auth\Oidc;

use App\Services\Auth\Oidc\Exceptions\OidcTokenInvalidException;
use App\Services\Auth\Oidc\OidcDiscoveryService;
use App\Services\Auth\Oidc\OidcTokenValidator;
use Firebase\JWT\JWT;
use Tests\TestCase;

final class OidcTokenValidatorTest extends TestCase
{
    /** @return array{0: string, 1: array, 2: string} */
    private function keypair(): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privatePem);
        $details = openssl_pkey_get_details($res);
        $publicPem = $details['key'];
        $n = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
        $e = rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=');
        $jwks = ['keys' => [['kty' => 'RSA', 'kid' => 'test-kid', 'use' => 'sig', 'alg' => 'RS256', 'n' => $n, 'e' => $e]]];

        return [$privatePem, $jwks, $publicPem];
    }

    private function discovery(array $jwks): OidcDiscoveryService
    {
        return new class('x', $jwks) extends OidcDiscoveryService
        {
            public function __construct(string $url, private array $jwksData)
            {
                parent::__construct($url);
            }

            public function issuer(): string
            {
                return 'https://idp';
            }

            public function jwks(): array
            {
                return $this->jwksData;
            }
        };
    }

    private function mint(string $privatePem, array $overrides = []): string
    {
        $claims = array_merge([
            'iss' => 'https://idp', 'aud' => 'client-123', 'sub' => 'sub-1',
            'email' => 'u@example.com', 'name' => 'User One', 'nonce' => 'n1',
            'exp' => time() + 300, 'iat' => time(), 'groups' => ['Zephyrus Users'],
        ], $overrides);

        return JWT::encode($claims, $privatePem, 'RS256', 'test-kid');
    }

    public function test_accepts_a_valid_token_and_extracts_claims(): void
    {
        [$priv, $jwks] = $this->keypair();
        $claims = (new OidcTokenValidator($this->discovery($jwks), 'client-123'))->validate($this->mint($priv), 'n1');

        $this->assertSame('sub-1', $claims->sub);
        $this->assertSame('u@example.com', $claims->email);
        $this->assertContains('Zephyrus Users', $claims->groups);
    }

    public function test_rejects_wrong_audience(): void
    {
        [$priv, $jwks] = $this->keypair();
        $this->expectException(OidcTokenInvalidException::class);
        (new OidcTokenValidator($this->discovery($jwks), 'client-123'))->validate($this->mint($priv, ['aud' => 'someone-else']), 'n1');
    }

    public function test_rejects_nonce_mismatch(): void
    {
        [$priv, $jwks] = $this->keypair();
        $this->expectException(OidcTokenInvalidException::class);
        (new OidcTokenValidator($this->discovery($jwks), 'client-123'))->validate($this->mint($priv), 'different-nonce');
    }

    public function test_rejects_expired_token(): void
    {
        [$priv, $jwks] = $this->keypair();
        $this->expectException(OidcTokenInvalidException::class);
        (new OidcTokenValidator($this->discovery($jwks), 'client-123'))->validate($this->mint($priv, ['exp' => time() - 3600]), 'n1');
    }

    public function test_rejects_tampered_signature(): void
    {
        [$priv, $jwks] = $this->keypair();
        $this->expectException(OidcTokenInvalidException::class);
        (new OidcTokenValidator($this->discovery($jwks), 'client-123'))->validate($this->mint($priv).'x', 'n1');
    }

    public function test_rejects_token_signed_by_a_different_key(): void
    {
        [$attackerPriv] = $this->keypair();   // attacker's own key
        [, $legitJwks] = $this->keypair();    // unrelated legit JWKS
        $this->expectException(OidcTokenInvalidException::class);
        (new OidcTokenValidator($this->discovery($legitJwks), 'client-123'))->validate($this->mint($attackerPriv), 'n1');
    }

    public function test_rejects_alg_none_token(): void
    {
        [, $jwks] = $this->keypair();
        $seg = fn (array $a): string => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
        $token = $seg(['typ' => 'JWT', 'alg' => 'none', 'kid' => 'test-kid']).'.'.$seg([
            'iss' => 'https://idp', 'aud' => 'client-123', 'sub' => 's', 'email' => 'e@x', 'name' => 'N', 'exp' => time() + 300,
        ]).'.';
        $this->expectException(OidcTokenInvalidException::class);
        (new OidcTokenValidator($this->discovery($jwks), 'client-123'))->validate($token, null);
    }

    public function test_rejects_hs256_algorithm_confusion(): void
    {
        [, $jwks, $publicPem] = $this->keypair();
        $forged = JWT::encode([
            'iss' => 'https://idp', 'aud' => 'client-123', 'sub' => 's', 'email' => 'e@x', 'name' => 'N',
            'exp' => time() + 300, 'nonce' => 'n1',
        ], $publicPem, 'HS256', 'test-kid');
        $this->expectException(OidcTokenInvalidException::class);
        (new OidcTokenValidator($this->discovery($jwks), 'client-123'))->validate($forged, 'n1');
    }

    public function test_rejects_token_without_exp(): void
    {
        [$priv, $jwks] = $this->keypair();
        $token = JWT::encode([
            'iss' => 'https://idp', 'aud' => 'client-123', 'sub' => 's', 'email' => 'e@x', 'name' => 'N', 'nonce' => 'n1',
        ], $priv, 'RS256', 'test-kid');
        $this->expectException(OidcTokenInvalidException::class);
        (new OidcTokenValidator($this->discovery($jwks), 'client-123'))->validate($token, 'n1');
    }

    public function test_rejects_issuer_mismatch(): void
    {
        [$priv, $jwks] = $this->keypair();
        $this->expectException(OidcTokenInvalidException::class);
        (new OidcTokenValidator($this->discovery($jwks), 'client-123'))->validate($this->mint($priv, ['iss' => 'https://evil']), 'n1');
    }

    public function test_rejects_missing_required_claim(): void
    {
        [$priv, $jwks] = $this->keypair();
        $token = JWT::encode([
            'iss' => 'https://idp', 'aud' => 'client-123', 'sub' => 's', 'name' => 'N', 'nonce' => 'n1', 'exp' => time() + 300,
        ], $priv, 'RS256', 'test-kid');
        $this->expectException(OidcTokenInvalidException::class);
        (new OidcTokenValidator($this->discovery($jwks), 'client-123'))->validate($token, 'n1');
    }
}
