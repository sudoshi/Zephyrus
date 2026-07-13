<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

final class EdgeSecurityContractTest extends TestCase
{
    public function test_machine_readable_edge_policy_is_fail_closed(): void
    {
        $policy = json_decode(
            (string) file_get_contents(base_path('deploy/security/edge-policy.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('blocking', $policy['waf']['mode']);
        $this->assertSame('OWASP Core Rule Set', $policy['waf']['ruleset']);
        $this->assertContains('security2_module', $policy['required_modules']);
        $this->assertFalse($policy['release_rules']['critical_or_high_findings_allowed']);
        $this->assertFalse($policy['release_rules']['production_phi_allowed_without_live_edge_verification']);
        $this->assertNotEmpty($policy['machine_ingress']);
    }

    public function test_apache_policy_enforces_waf_and_bounded_requests(): void
    {
        $policy = (string) file_get_contents(base_path('deploy/apache/zephyrus-edge-security.conf'));

        $this->assertStringContainsString('SecRuleEngine On', $policy);
        $this->assertStringContainsString('SecRequestBodyLimitAction Reject', $policy);
        $this->assertStringContainsString('TraceEnable Off', $policy);
        $this->assertStringContainsString('OWASP CRS', $policy);
        $this->assertStringContainsString('@gt 1048576', $policy);
        $this->assertStringNotContainsString('<IfModule', $policy);
    }

    public function test_edge_contract_validator_passes_offline(): void
    {
        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('scripts/security/verify-edge-security.php')).' --contract 2>&1',
            $output,
            $status,
        );

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('verified', implode("\n", $output));
    }
}
