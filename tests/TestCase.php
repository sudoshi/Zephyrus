<?php

namespace Tests;

use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Security\Secrets\Providers\FileSecretProvider;
use App\Security\Secrets\SecretProviderRegistry;
use App\Services\Auth\AccountSessionService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\InMemorySecretProvider;

abstract class TestCase extends BaseTestCase
{
    /** @var array<string, int|string>|null */
    private ?array $canonicalIntegrationScope = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (filter_var(getenv('TEST_NETWORK_GUARD') ?: 'false', FILTER_VALIDATE_BOOL)) {
            Http::preventStrayRequests();
        }

        $this->app->singleton(SecretProviderRegistry::class, fn ($app) => new SecretProviderRegistry([
            $app->make(FileSecretProvider::class),
            new InMemorySecretProvider('vault'),
            new InMemorySecretProvider('aws-secretsmanager'),
            new InMemorySecretProvider('gcp-secretmanager'),
            new InMemorySecretProvider('azure-keyvault'),
        ]));

        config([
            'clinical-payloads.enabled' => true,
            'clinical-payloads.disk' => 'clinical-payloads',
            'clinical-payloads.key_reference' => 'vault://testing/clinical-payload-kek',
            'clinical-payloads.allow_local_in_production' => false,
            'filesystems.disks.clinical-payloads' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/clinical-payloads/'.getmypid()),
                'serve' => false,
                'visibility' => 'private',
                'throw' => true,
                'report' => false,
            ],
        ]);

        $this->withoutVite();
    }

    public function actingAs(Authenticatable $user, $guard = null)
    {
        if ($user instanceof Model && $user->exists) {
            $user->refresh();
        }

        parent::actingAs($user, $guard);

        return $this->withSession([
            AccountSessionService::SESSION_VERSION_KEY => (int) ($user->auth_session_version ?? 0),
        ]);
    }

    /** @return array{organization_id: int, facility_id: int, tenant_key: string, facility_key: string} */
    protected function canonicalIntegrationSourceScope(): array
    {
        if ($this->canonicalIntegrationScope !== null) {
            return $this->canonicalIntegrationScope;
        }

        $organization = Organization::query()->firstOrCreate(
            ['organization_key' => 'TEST_INTEGRATION_IDN'],
            ['name' => 'Test Integration IDN', 'kind' => 'idn'],
        );
        $facility = Facility::query()->firstOrCreate(
            ['facility_key' => 'TEST_INTEGRATION_FACILITY'],
            [
                'organization_id' => $organization->organization_id,
                'facility_name' => 'Test Integration Facility',
                'idn_role' => 'community_hospital',
                'review_status' => 'client_verified',
                'is_active' => true,
            ],
        );

        return $this->canonicalIntegrationScope = [
            'organization_id' => (int) $organization->organization_id,
            'facility_id' => (int) $facility->facility_id,
            'tenant_key' => (string) $organization->organization_key,
            'facility_key' => (string) $facility->facility_key,
        ];
    }
}
