<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TestResourceIsolationTest extends TestCase
{
    public function test_runtime_configuration_contains_no_production_resource_targets(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertMatchesRegularExpression('/^zephyrus_test_[a-f0-9]{12}$/', (string) config('database.connections.pgsql.database'));
        $this->assertSame('storage/framework/testing/secrets', config('integrations.secret_file_root'));
        $this->assertSame('idp.test', parse_url((string) config('services.oidc.discovery_url'), PHP_URL_HOST));
        $this->assertSame('fhir.test', parse_url((string) config('integrations.epic_sandbox.base_url'), PHP_URL_HOST));
    }

    public function test_unfaked_laravel_http_requests_fail_before_network_io(): void
    {
        try {
            Http::get('https://should-never-resolve.example');
            $this->fail('An unfaked outbound request escaped the PHPUnit network guard.');
        } catch (RequestException|RuntimeException $exception) {
            $this->assertStringContainsString('without a matching fake', $exception->getMessage());
        }
    }
}
