<?php

namespace Tests\Unit\Auth\Oidc;

use App\Security\Network\DnsResolver;
use App\Security\Network\OidcUrlPolicy;
use App\Security\Network\UnsafeOidcUrl;
use Tests\TestCase;

final class OidcUrlPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('auth-drivers.oidc_network', [
            'require_https' => true,
            'allowed_hosts' => ['idp.example', 'keys.idp.example', '*.trusted-idp.example'],
            'allowed_ports' => [443],
            'allowed_redirect_uris' => ['https://zephyrus.example/auth/oidc/callback'],
            'require_dns_resolution' => true,
            'allow_private_networks' => false,
        ]);
    }

    public function test_accepts_allowlisted_public_metadata_and_exact_redirect_uri(): void
    {
        // TEST-NET addresses are reserved, so use globally routable resolver fixtures.
        $policy = $this->policy([
            'idp.example' => ['8.8.8.8'],
            'keys.idp.example' => ['1.1.1.1'],
        ]);
        $policy->assertSafeDiscoveryMetadata('https://idp.example/.well-known/openid-configuration', [
            'issuer' => 'https://idp.example/tenant',
            'authorization_endpoint' => 'https://idp.example/authorize',
            'token_endpoint' => 'https://idp.example/token',
            'jwks_uri' => 'https://keys.idp.example/jwks',
        ]);
        $policy->assertAllowedRedirectUri('https://zephyrus.example/auth/oidc/callback');

        $this->addToAssertionCount(2);
    }

    public function test_rejects_unallowlisted_and_private_or_reserved_destinations(): void
    {
        $policy = $this->policy([
            'idp.example' => ['10.0.0.8'],
            'evil.example' => ['8.8.8.8'],
        ]);

        try {
            $policy->assertSafeOutboundUrl('https://evil.example/discovery');
            $this->fail('Unallowlisted host was accepted.');
        } catch (UnsafeOidcUrl $exception) {
            $this->assertSame('oidc_host_not_allowed', $exception->reason);
        }

        try {
            $policy->assertSafeOutboundUrl('https://idp.example/discovery');
            $this->fail('Private destination was accepted.');
        } catch (UnsafeOidcUrl $exception) {
            $this->assertSame('oidc_address_blocked', $exception->reason);
        }
    }

    public function test_private_network_exception_never_allows_link_local_metadata(): void
    {
        config()->set('auth-drivers.oidc_network.allow_private_networks', true);
        $private = $this->policy(['idp.example' => ['10.20.30.40']]);
        $private->assertSafeOutboundUrl('https://idp.example/discovery');
        $this->addToAssertionCount(1);

        $metadata = $this->policy(['idp.example' => ['169.254.169.254']]);
        $this->expectException(UnsafeOidcUrl::class);
        $metadata->assertSafeOutboundUrl('https://idp.example/discovery');
    }

    public function test_rejects_http_ports_credentials_fragments_and_unresolved_hosts(): void
    {
        $policy = $this->policy(['idp.example' => []]);
        $cases = [
            'http://idp.example/discovery' => 'oidc_https_required',
            'https://idp.example:8443/discovery' => 'oidc_port_not_allowed',
            'https://user:pass@idp.example/discovery' => 'oidc_embedded_credentials',
            'https://idp.example/discovery#fragment' => 'oidc_fragment_rejected',
            'https://idp.example/discovery' => 'oidc_dns_unresolved',
        ];

        foreach ($cases as $url => $reason) {
            try {
                $policy->assertSafeOutboundUrl($url);
                $this->fail("Unsafe URL was accepted: {$url}");
            } catch (UnsafeOidcUrl $exception) {
                $this->assertSame($reason, $exception->reason);
            }
        }
    }

    public function test_rejects_issuer_origin_mismatch_and_unapproved_redirect_uri(): void
    {
        $policy = $this->policy([
            'idp.example' => ['8.8.8.8'],
            'keys.idp.example' => ['1.1.1.1'],
        ]);

        try {
            $policy->assertSafeDiscoveryMetadata('https://idp.example/discovery', [
                'issuer' => 'https://keys.idp.example/tenant',
                'authorization_endpoint' => 'https://idp.example/authorize',
                'token_endpoint' => 'https://idp.example/token',
                'jwks_uri' => 'https://keys.idp.example/jwks',
            ]);
            $this->fail('Mismatched issuer origin was accepted.');
        } catch (UnsafeOidcUrl $exception) {
            $this->assertSame('oidc_issuer_origin_mismatch', $exception->reason);
        }

        $this->expectException(UnsafeOidcUrl::class);
        $policy->assertAllowedRedirectUri('https://attacker.example/callback');
    }

    /** @param array<string, list<string>> $records */
    private function policy(array $records): OidcUrlPolicy
    {
        $dns = new class($records) extends DnsResolver
        {
            /** @param array<string, list<string>> $records */
            public function __construct(private readonly array $records) {}

            public function resolve(string $host): array
            {
                return $this->records[$host] ?? [];
            }
        };

        return new OidcUrlPolicy($dns);
    }
}
