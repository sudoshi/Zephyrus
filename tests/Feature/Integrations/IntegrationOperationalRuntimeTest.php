<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Integrations\Healthcare\Services\EpicSmartFhirClient;
use App\Integrations\Healthcare\Services\IntegrationProtocolHealthService;
use App\Integrations\Healthcare\Services\OperationalIntegrationConfigurator;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Jobs\PollEpicFhirResource;
use App\Jobs\ReplayPendingIntegrationEvents;
use App\Jobs\RunIntegrationProtocolHealthCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntegrationOperationalRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private ?string $secretDirectory = null;

    protected function tearDown(): void
    {
        if ($this->secretDirectory) {
            foreach (glob($this->secretDirectory.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($this->secretDirectory);
        }

        parent::tearDown();
    }

    public function test_operational_source_command_is_idempotent_and_truthful_about_activation(): void
    {
        $this->artisan('integrations:configure-operational-sources')
            ->expectsOutputToContain('activation-gated')
            ->assertSuccessful();
        $this->artisan('integrations:configure-operational-sources')->assertSuccessful();

        $this->assertDatabaseHas('integration.sources', [
            'source_key' => 'epic.fhir-r4.sandbox',
            'active_status' => 'testing',
            'phi_allowed' => false,
        ]);
        $this->assertSame(3, DB::table('integration.source_endpoints')->count());
        $this->assertSame(1, DB::table('integration.fhir_client_connections')->count());
        $this->assertDatabaseHas('integration.smart_backend_credentials', [
            'credential_key' => 'epic-smart-backend',
            'status' => 'activation_required',
            'client_id' => null,
        ]);
        $this->assertDatabaseHas('integration.interface_engines', [
            'engine_key' => 'patient-flow-hl7v2-ingress',
            'status' => 'ready',
        ]);
        $this->assertSame(2, DB::table('integration.configuration_audits')->count());
    }

    public function test_fhir_discovery_runs_on_the_integration_queue_and_records_protocol_health(): void
    {
        config([
            'integrations.network.allowed_hosts' => ['fhir.epic.com'],
            'integrations.network.require_dns_resolution' => false,
        ]);
        app(OperationalIntegrationConfigurator::class)->configureEpicSandbox();
        $sourceId = (int) DB::table('integration.sources')->where('source_key', 'epic.fhir-r4.sandbox')->value('source_id');
        Http::fake($this->epicDiscoveryResponses());
        Queue::fake();

        $response = $this->actingAs($this->superuser())
            ->postJson('/api/admin/integrations/sources/'.$sourceId.'/health-check')
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued');

        $runId = (int) $response->json('data.runId');
        Queue::assertPushed(RunIntegrationProtocolHealthCheck::class, function (RunIntegrationProtocolHealthCheck $job) use ($runId): bool {
            return $job->runId === $runId && $job->connection === 'database' && $job->queue === 'integrations';
        });
        $job = new RunIntegrationProtocolHealthCheck($runId, null, (string) Str::uuid());
        $job->handle(app(IntegrationProtocolHealthService::class), app(\App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService::class));

        $this->assertDatabaseHas('raw.ingest_runs', ['ingest_run_id' => $runId, 'status' => 'completed']);
        $this->assertDatabaseHas('integration.sources', ['source_id' => $sourceId, 'protocol_health_status' => 'healthy']);
        $this->assertDatabaseHas('integration.fhir_client_connections', [
            'source_id' => $sourceId,
            'health_status' => 'healthy',
            'status' => 'activation_required',
            'fhir_version' => '4.0.1',
        ]);
        $this->assertDatabaseHas('integration.source_capabilities', [
            'source_id' => $sourceId,
            'resource_type' => 'Encounter',
            'capability_type' => 'fhir_resource',
        ]);
        $this->assertSame(0, DB::table('integration.connector_watermarks')->count(), 'Protocol reachability must not advance a data watermark.');
    }

    public function test_epic_smart_backend_poll_signs_a_jwt_and_persists_versioned_fhir_lineage(): void
    {
        config([
            'integrations.network.allowed_hosts' => ['fhir.epic.com'],
            'integrations.network.require_dns_resolution' => false,
        ]);
        $keyReference = $this->privateKeyReference();
        $source = app(OperationalIntegrationConfigurator::class)->configureEpicSandbox(
            clientId: 'registered-non-production-client',
            privateKeyRef: $keyReference,
            keyId: 'test-key-1',
            activate: true,
        );
        $sourceId = (int) $source['sourceId'];
        Http::fake($this->epicDiscoveryResponses());
        app(IntegrationProtocolHealthService::class)->check($sourceId);

        Http::fake(function (Request $request) {
            if ($request->url() === config('integrations.epic_sandbox.token_url')) {
                return Http::response(['access_token' => 'ephemeral-test-token', 'token_type' => 'Bearer', 'expires_in' => 300]);
            }
            if (str_starts_with($request->url(), config('integrations.epic_sandbox.base_url').'/Encounter')) {
                return Http::response([
                    'resourceType' => 'Bundle',
                    'type' => 'searchset',
                    'entry' => [[
                        'resource' => [
                            'resourceType' => 'Encounter',
                            'id' => 'sandbox-encounter-1',
                            'meta' => ['versionId' => '1', 'lastUpdated' => '2026-07-10T12:00:00Z'],
                            'status' => 'finished',
                        ],
                    ]],
                ], 200, ['Content-Type' => 'application/fhir+json']);
            }

            return Http::response([], 404);
        });
        $runId = DB::table('raw.ingest_runs')->insertGetId([
            'run_uuid' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'connector_key' => 'epic.fhir-r4.encounter',
            'run_type' => 'fhir_poll',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'ingest_run_id');

        $result = app(EpicSmartFhirClient::class)->poll($sourceId, 'Encounter', (int) $runId);

        $this->assertSame(1, $result['resourcesPersisted']);
        $this->assertDatabaseHas('raw.inbound_messages', [
            'source_id' => $sourceId,
            'ingest_run_id' => $runId,
            'message_type' => 'FHIR_R4_Encounter',
            'parse_status' => 'persisted',
        ]);
        $this->assertDatabaseHas('fhir.resource_versions', [
            'source_id' => $sourceId,
            'resource_type' => 'Encounter',
            'fhir_id' => 'sandbox-encounter-1',
            'version_id' => '1',
        ]);
        $this->assertDatabaseHas('integration.provenance_records', ['target_schema' => 'fhir', 'target_table' => 'resource_versions']);
        $this->assertDatabaseHas('integration.connector_watermarks', [
            'source_id' => $sourceId,
            'connector_key' => 'epic.fhir-r4',
            'scope_key' => 'Encounter',
        ]);
        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== config('integrations.epic_sandbox.token_url')) {
                return false;
            }
            $assertion = (string) ($request['client_assertion'] ?? '');

            return ($request['grant_type'] ?? null) === 'client_credentials'
                && substr_count($assertion, '.') === 2
                && ! str_contains($assertion, 'PRIVATE KEY');
        });
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), config('integrations.epic_sandbox.base_url').'/Encounter')
            && $request->hasHeader('Authorization', 'Bearer ephemeral-test-token'));
        $databaseDump = json_encode([
            DB::table('integration.smart_backend_credentials')->get(),
            DB::table('raw.ingest_runs')->get(),
            DB::table('integration.configuration_audits')->get(),
        ]);
        $this->assertStringNotContainsString('ephemeral-test-token', (string) $databaseDump);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', (string) $databaseDump);
    }

    public function test_replay_preview_is_read_only_and_execution_is_bounded_idempotent_and_audited(): void
    {
        $source = app(SourceRegistryService::class)->ensureSource([
            'source_key' => 'replay.test',
            'source_name' => 'Replay Test',
        ]);
        $pendingId = $this->canonicalEvent((int) $source->source_id, 'pending');
        $this->canonicalEvent((int) $source->source_id, 'projected');
        $user = $this->superuser();
        $scope = [
            'source_id' => $source->source_id,
            'from' => now()->subHour()->toIso8601String(),
            'to' => now()->addHour()->toIso8601String(),
            'limit' => 50,
        ];

        $this->actingAs($user)->postJson('/api/admin/integrations/enterprise/replays/preview', $scope)
            ->assertOk()
            ->assertJsonPath('data.eligibleEvents', 1)
            ->assertJsonPath('data.mutation', false);
        $this->assertSame(0, DB::table('integration.event_replay_jobs')->count());

        Queue::fake();
        $first = $this->actingAs($user)->postJson('/api/admin/integrations/enterprise/replays', $scope, ['Idempotency-Key' => 'replay-test-1'])
            ->assertAccepted();
        $replayId = (int) $first->json('data.replayJobId');
        $this->actingAs($user)->postJson('/api/admin/integrations/enterprise/replays', $scope, ['Idempotency-Key' => 'replay-test-1'])
            ->assertOk()->assertJsonPath('data.replayJobId', $replayId)->assertJsonPath('data.created', false);
        $changedScope = array_merge($scope, ['limit' => 49]);
        $this->actingAs($user)->postJson('/api/admin/integrations/enterprise/replays', $changedScope, ['Idempotency-Key' => 'replay-test-1'])
            ->assertConflict();
        $job = new ReplayPendingIntegrationEvents($replayId, $user->id, (string) Str::uuid());
        $this->assertSame('database', $job->connection);
        $this->assertSame('integrations', $job->queue);
        $job->handle(app(\App\Integrations\Healthcare\Services\ProjectionDispatcher::class), app(\App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService::class));

        $this->assertDatabaseHas('integration.event_replay_jobs', [
            'event_replay_job_id' => $replayId,
            'status' => 'completed',
            'events_replayed' => 1,
            'events_failed' => 0,
        ]);
        $this->assertDatabaseHas('integration.canonical_events', [
            'canonical_event_id' => $pendingId,
            'projection_status' => 'projected',
        ]);
        $this->assertSame(1, DB::table('prod.operational_events')->count());
        $this->assertDatabaseHas('integration.configuration_audits', [
            'entity_type' => 'canonical_event_replay',
            'action' => 'completed',
        ]);
    }

    public function test_failed_fhir_poll_dead_letters_and_a_successful_retry_resolves_it(): void
    {
        config([
            'integrations.network.allowed_hosts' => ['fhir.epic.com'],
            'integrations.network.require_dns_resolution' => false,
        ]);
        $source = app(OperationalIntegrationConfigurator::class)->configureEpicSandbox(
            clientId: 'registered-non-production-client',
            privateKeyRef: $this->privateKeyReference(),
            activate: true,
        );
        $sourceId = (int) $source['sourceId'];
        Http::fake($this->epicDiscoveryResponses());
        app(IntegrationProtocolHealthService::class)->check($sourceId);
        $runId = DB::table('raw.ingest_runs')->insertGetId([
            'run_uuid' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'connector_key' => 'epic.fhir-r4.encounter',
            'run_type' => 'fhir_poll',
            'status' => 'queued',
            'started_at' => now(),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'ingest_run_id');
        $job = new PollEpicFhirResource((int) $runId, 'Encounter', null, (string) Str::uuid());
        $tokenAttempts = 0;
        Http::fake(function (Request $request) use (&$tokenAttempts) {
            if ($request->url() === config('integrations.epic_sandbox.token_url')) {
                $tokenAttempts++;

                return $tokenAttempts === 1
                    ? Http::response(['error' => 'invalid_client'], 401)
                    : Http::response(['access_token' => 'retry-token', 'expires_in' => 300]);
            }

            return Http::response(['resourceType' => 'Bundle', 'type' => 'searchset', 'entry' => []], 200, ['Content-Type' => 'application/fhir+json']);
        });

        $failure = null;
        try {
            $job->handle(app(EpicSmartFhirClient::class), app(\App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService::class));
            $this->fail('The invalid SMART client should fail the poll.');
        } catch (IntegrationProtocolException $exception) {
            $failure = $exception;
            $this->assertSame('smart_token_http_401', $exception->errorCode);
        }
        $this->assertNotNull($failure);
        $this->assertDatabaseHas('raw.ingest_runs', ['ingest_run_id' => $runId, 'status' => 'retrying']);
        $job->failed($failure);
        $this->assertDatabaseHas('raw.ingest_runs', ['ingest_run_id' => $runId, 'status' => 'failed']);
        $this->assertDatabaseHas('raw.dead_letters', [
            'ingest_run_id' => $runId,
            'failure_stage' => 'fhir_poll',
            'reason_code' => 'smart_token_http_401',
            'status' => 'open',
        ]);

        $job->handle(app(EpicSmartFhirClient::class), app(\App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService::class));

        $this->assertDatabaseHas('raw.ingest_runs', ['ingest_run_id' => $runId, 'status' => 'completed']);
        $this->assertDatabaseHas('raw.dead_letters', [
            'ingest_run_id' => $runId,
            'status' => 'resolved',
        ]);
    }

    public function test_hl7_source_activation_requires_governance_and_issues_only_a_bounded_machine_token(): void
    {
        $configurator = app(OperationalIntegrationConfigurator::class);
        $configurator->configureHl7Boundary();
        try {
            $configurator->configureHl7Source([
                'source_key' => 'epic.adt.production',
                'source_name' => 'Epic ADT Production',
                'vendor' => 'Epic',
                'environment' => 'production',
                'contract_status' => 'unknown',
                'baa_status' => 'unknown',
                'phi_allowed' => false,
                'go_live_status' => 'not_started',
                'activate' => true,
            ]);
            $this->fail('Ungoverned HL7 activation should be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('activation', $exception->errors());
        }

        $source = $configurator->configureHl7Source([
            'source_key' => 'epic.adt.production',
            'source_name' => 'Epic ADT Production',
            'vendor' => 'Epic',
            'environment' => 'production',
            'contract_status' => 'executed',
            'baa_status' => 'executed',
            'phi_allowed' => true,
            'go_live_status' => 'live',
            'activate' => true,
        ]);
        $machine = User::factory()->create(['role' => 'integration', 'is_active' => true]);
        $this->artisan('integrations:issue-hl7-token', ['user' => $machine->id, '--expires' => 7])->assertSuccessful();

        $token = $machine->tokens()->firstOrFail();
        $this->assertSame(['integration:patient-flow:ingest'], $token->abilities);
        $this->assertTrue($token->expires_at->isBetween(now()->addDays(6), now()->addDays(8)));
        $health = app(IntegrationProtocolHealthService::class)->check((int) $source['sourceId']);
        $this->assertSame('healthy', $health['status']);
        $this->assertSame('ready', $health['machineIdentityStatus']);
        $this->assertDatabaseHas('integration.configuration_audits', [
            'entity_type' => 'machine_credential',
            'entity_id' => $token->getKey(),
            'action' => 'issued',
        ]);
        $auditDump = DB::table('integration.configuration_audits')->get()->toJson();
        $this->assertStringNotContainsString((string) $token->token, $auditDump);
    }

    public function test_new_operational_controls_keep_the_strict_superuser_boundary(): void
    {
        $source = app(SourceRegistryService::class)->ensureSource(['source_key' => 'auth.test']);
        $user = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);

        $this->actingAs($user)->postJson('/api/admin/integrations/sources/'.$source->source_id.'/health-check')->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/integrations/sources/'.$source->source_id.'/fhir/poll', ['resource_type' => 'Encounter'])->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/integrations/enterprise/replays/preview', [])->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/integrations/enterprise/replays', [])->assertForbidden();
    }

    /** @return array<string, \Illuminate\Http\Client\Response> */
    private function epicDiscoveryResponses(): array
    {
        return [
            config('integrations.epic_sandbox.smart_configuration_url') => Http::response([
                'token_endpoint' => config('integrations.epic_sandbox.token_url'),
                'capabilities' => ['client-confidential-asymmetric'],
            ]),
            config('integrations.epic_sandbox.base_url').'/metadata' => Http::response([
                'resourceType' => 'CapabilityStatement',
                'fhirVersion' => '4.0.1',
                'software' => ['name' => 'Epic', 'version' => 'sandbox'],
                'format' => ['json'],
                'rest' => [['resource' => [['type' => 'Encounter'], ['type' => 'Location']]]],
            ], 200, ['Content-Type' => 'application/fhir+json']),
        ];
    }

    private function privateKeyReference(): string
    {
        $this->secretDirectory = storage_path('framework/testing-integration-secrets-'.Str::random(8));
        mkdir($this->secretDirectory, 0700, true);
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($resource);
        $this->assertTrue(openssl_pkey_export($resource, $pem));
        $path = $this->secretDirectory.'/epic-private-key.pem';
        file_put_contents($path, $pem);
        chmod($path, 0640);
        config(['integrations.secret_file_root' => $this->secretDirectory]);

        return 'file://'.$path;
    }

    private function canonicalEvent(int $sourceId, string $projectionStatus): int
    {
        $eventId = (string) Str::uuid();

        return DB::table('integration.canonical_events')->insertGetId([
            'event_id' => $eventId,
            'source_id' => $sourceId,
            'event_type' => 'BedStatusChanged',
            'entity_type' => 'bed',
            'entity_ref' => 'bed-999999',
            'occurred_at' => now(),
            'received_at' => now(),
            'payload' => json_encode(['bed_id' => 999999, 'status' => 'available']),
            'payload_hash' => hash('sha256', $eventId),
            'idempotency_key' => 'test:'.$eventId,
            'projection_status' => $projectionStatus,
            'projected_at' => $projectionStatus === 'projected' ? now() : null,
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'canonical_event_id');
    }

    private function superuser(): User
    {
        return User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
    }
}
