<?php

namespace Tests;

use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Security\Secrets\Providers\FileSecretProvider;
use App\Security\Secrets\SecretProviderRegistry;
use App\Services\Auth\AccountSessionService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\InMemorySecretProvider;
use Tests\Support\Scenario\CommittedScenarioState;
use Tests\Support\Scenario\UsesCommittedAncillaryScenario;
use Tests\Support\Timing\QueryEvidence;
use Tests\Support\Timing\TimingEvidence;

abstract class TestCase extends BaseTestCase
{
    /** @var array<string, int|string>|null */
    private ?array $canonicalIntegrationScope = null;

    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        // §3.2.9 instrumentation bridge: count queries from the moment the
        // application exists so trait-driven migrations and seeders inside
        // setUp() are attributed to the setup phase. Inactive (no listener
        // at all) outside CI evidence runs.
        if (TimingEvidence::evidencePath() !== null) {
            DB::listen(function (QueryExecuted $query): void {
                QueryEvidence::record((float) $query->time);
            });
        }
    }

    protected function setUp(): void
    {
        // CI plan S2: a scenario class leaves a COMMITTED demo baseline
        // behind (see UsesCommittedAncillaryScenario). Scenario classes
        // rebuild over it idempotently, but a non-scenario class expects
        // the clean post-migration baseline — force a fresh migration
        // before this test's application boots. Must run before
        // parent::setUp(), which is where RefreshDatabase consults
        // RefreshDatabaseState::$migrated.
        if (CommittedScenarioState::$activeClass !== null
            && ! in_array(UsesCommittedAncillaryScenario::class, class_uses_recursive(static::class), true)) {
            // migrate:fresh only drops search_path tables while this app
            // spans many PG schemas — wipe them all so the re-migration
            // reproduces a freshly provisioned database.
            Support\IsolatedTestDatabase::resetAllSchemas();
            RefreshDatabaseState::$migrated = false;
            CommittedScenarioState::reset();
        }

        parent::setUp();

        if (filter_var(getenv('TEST_NETWORK_GUARD') ?: 'false', FILTER_VALIDATE_BOOL)) {
            Http::preventStrayRequests();
        }

        $this->wireClinicalPayloadTestStore();

        $this->withoutVite();
    }

    /**
     * Test wiring for the encrypted clinical-payload store (in-memory
     * secret providers + local disk). Applied on every test in setUp();
     * also applied by UsesCommittedAncillaryScenario BEFORE the class-
     * scoped scenario build, which runs during parent::setUp() — earlier
     * than this method's setUp() invocation — and writes payloads
     * through CanonicalEventWriter.
     */
    protected function wireClinicalPayloadTestStore(): void
    {
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
    }

    /**
     * Model a request/job boundary inside one test method. Production flushes
     * scoped container instances (e.g. LabAggregateSnapshotFactory) between
     * FPM requests and queue jobs, but the test harness keeps one container
     * per test method — a test that mutates rows and re-reads a scoped
     * aggregate must declare the boundary explicitly or it reads the memo.
     */
    protected function nextRequestScope(): void
    {
        $this->app->forgetScopedInstances();
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
