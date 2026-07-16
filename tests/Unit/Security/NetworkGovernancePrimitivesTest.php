<?php

namespace Tests\Unit\Security;

use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Security\Network\CidrMatcher;
use App\Security\Network\GuardedConnection;
use Tests\TestCase;

final class NetworkGovernancePrimitivesTest extends TestCase
{
    public function test_cidr_matcher_supports_exact_ipv4_and_ipv6_prefixes(): void
    {
        $matcher = new CidrMatcher;

        $this->assertTrue($matcher->valid('10.20.0.0/16'));
        $this->assertTrue($matcher->contains('10.20.0.0/16', '10.20.255.254'));
        $this->assertFalse($matcher->contains('10.20.0.0/16', '10.21.0.1'));
        $this->assertTrue($matcher->valid('2001:db8:abcd::/48'));
        $this->assertTrue($matcher->contains('2001:db8:abcd::/48', '2001:db8:abcd::99'));
        $this->assertFalse($matcher->contains('2001:db8:abcd::/48', '2001:db8:abce::1'));
        $this->assertFalse($matcher->valid('10.0.0.0/33'));
        $this->assertFalse($matcher->contains('10.0.0.0/8', '2001:db8::1'));
    }

    public function test_guarded_connection_deterministically_pins_a_resolved_address_and_disables_redirects(): void
    {
        $connection = new GuardedConnection(
            'https',
            'fhir.vendor.example',
            443,
            ['2001:4860:4860::8888', '8.8.8.8', '1.1.1.1'],
            42,
        );

        $options = $connection->httpOptions();

        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(
            ['fhir.vendor.example:443:1.1.1.1'],
            $options['curl'][CURLOPT_RESOLVE],
        );
    }

    public function test_guarded_connection_refuses_unresolved_targets(): void
    {
        $this->expectException(IntegrationProtocolException::class);
        $this->expectExceptionMessage('network_dns_resolution_required');

        (new GuardedConnection('https', 'unresolved.example', 443, [], null))->httpOptions();
    }

    public function test_guarded_connection_pins_the_proxy_and_uses_in_memory_mtls_material(): void
    {
        $connection = new GuardedConnection(
            'https',
            'fhir.vendor.example',
            443,
            ['8.8.8.8'],
            42,
            'https://proxy.vendor.example:8443',
            ['1.1.1.1'],
            'client-certificate-pem',
            'client-private-key-pem',
        );

        $options = $connection->httpOptions();

        $this->assertSame('https://proxy.vendor.example:8443', $options['proxy']);
        $this->assertSame([
            'fhir.vendor.example:443:8.8.8.8',
            'proxy.vendor.example:8443:1.1.1.1',
        ], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('client-certificate-pem', $options['curl'][CURLOPT_SSLCERT_BLOB]);
        $this->assertSame('client-private-key-pem', $options['curl'][CURLOPT_SSLKEY_BLOB]);
    }
}
