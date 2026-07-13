<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironmentGuard;

class TestEnvironmentGuardTest extends TestCase
{
    public function test_safe_isolated_environment_has_no_violations(): void
    {
        $this->assertSame([], TestEnvironmentGuard::violations($this->safeEnvironment(), '/workspace/zephyrus'));
    }

    public function test_production_database_secret_and_network_values_are_all_rejected(): void
    {
        $environment = array_merge($this->safeEnvironment(), [
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:production-secret',
            'APP_URL' => 'https://zephyrus.acumenus.net',
            'DB_HOST' => 'db.internal.example',
            'DB_DATABASE' => 'zephyrus',
            'TEST_DB_ISOLATION' => 'false',
            'TEST_NETWORK_GUARD' => 'false',
            'INTEGRATION_SECRET_FILE_ROOT' => '/etc/zephyrus/secrets',
            'OIDC_DISCOVERY_URL' => 'https://auth.acumenus.net/.well-known/openid-configuration',
            'OIDC_ALLOWED_REDIRECT_URIS' => 'https://zephyrus.acumenus.net/auth/oidc/callback',
            'INTEGRATION_ALLOWED_HOSTS' => 'fhir.epic.com',
        ]);

        $violations = TestEnvironmentGuard::violations($environment, '/workspace/zephyrus');

        $this->assertGreaterThanOrEqual(10, count($violations));
        $this->assertStringContainsString('DB_DATABASE', implode(' ', $violations));
        $this->assertStringContainsString('INTEGRATION_SECRET_FILE_ROOT', implode(' ', $violations));
        $this->assertStringContainsString('OIDC_DISCOVERY_URL', implode(' ', $violations));
        $this->assertStringContainsString('OIDC_ALLOWED_REDIRECT_URIS', implode(' ', $violations));
    }

    public function test_secret_path_traversal_cannot_escape_the_test_root(): void
    {
        $environment = $this->safeEnvironment();
        $environment['INTEGRATION_SECRET_FILE_ROOT'] = 'storage/framework/testing/secrets/../../../../../etc';

        $this->assertContains(
            'INTEGRATION_SECRET_FILE_ROOT must stay under storage/framework/testing/secrets',
            TestEnvironmentGuard::violations($environment, '/workspace/zephyrus'),
        );
    }

    /** @return array<string, string> */
    private function safeEnvironment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=',
            'APP_URL' => 'http://zephyrus.test',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_DATABASE' => 'zephyrus_test',
            'TEST_DB_ISOLATION' => 'true',
            'TEST_NETWORK_GUARD' => 'true',
            'INTEGRATION_SECRET_FILE_ROOT' => 'storage/framework/testing/secrets',
            'OIDC_DISCOVERY_URL' => 'https://idp.test/.well-known/openid-configuration',
            'OIDC_REDIRECT_URI' => 'https://zephyrus.test/auth/oidc/callback',
            'OIDC_ALLOWED_REDIRECT_URIS' => 'https://zephyrus.test/auth/oidc/callback',
            'OIDC_ALLOWED_HOSTS' => 'idp.test',
            'INTEGRATION_ALLOWED_HOSTS' => 'fhir.test',
            'EPIC_FHIR_BASE_URL' => 'https://fhir.test/r4',
            'EPIC_SMART_CONFIGURATION_URL' => 'https://fhir.test/.well-known/smart-configuration',
            'EPIC_FHIR_TOKEN_URL' => 'https://fhir.test/oauth2/token',
            'EDDY_BASE_URL' => 'http://eddy.test',
            'ARENA_BASE_URL' => 'http://arena.test',
        ];
    }
}
