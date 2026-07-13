<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Integrations\Healthcare\Services\EpicSmartFhirClient;
use App\Integrations\Healthcare\Services\IntegrationProtocolHealthService;
use App\Integrations\Healthcare\Services\NetworkRouteService;
use App\Integrations\Healthcare\Services\OperationalIntegrationConfigurator;
use App\Integrations\Healthcare\Services\SourceOnboardingService;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Jobs\PollEpicFhirResource;
use App\Jobs\ReplayPendingIntegrationEvents;
use App\Jobs\RunIntegrationProtocolHealthCheck;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Security\Network\DnsResolver;
use App\Security\Network\IntegrationUrlPolicy;
use App\Services\Auth\StepUpAuthenticationService;
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

    private int $organizationId;

    private int $facilityId;

    private string $facilityKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('deployment:seed-registry')->assertExitCode(0);
        $organization = Organization::create([
            'organization_key' => 'RUNTIME_TEST_IDN',
            'name' => 'Runtime Test IDN',
            'kind' => 'idn',
        ]);
        $facility = Facility::create([
            'organization_id' => $organization->organization_id,
            'facility_key' => 'RUNTIME_TEST_FACILITY',
            'facility_name' => 'Runtime Test Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
        $this->organizationId = (int) $organization->organization_id;
        $this->facilityId = (int) $facility->facility_id;
        $this->facilityKey = (string) $facility->facility_key;
        $this->fakeDns([
            $this->epicHost() => ['8.8.8.8'],
            'hl7-gateway.test' => ['8.8.4.4'],
        ]);
    }

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
            'integrations.network.allowed_hosts' => [$this->epicHost()],
            'integrations.network.require_dns_resolution' => false,
        ]);
        app(OperationalIntegrationConfigurator::class)->configureEpicSandbox(facilityKey: $this->facilityKey);
        $sourceId = (int) DB::table('integration.sources')->where('source_key', 'epic.fhir-r4.sandbox')->value('source_id');
        Http::fake($this->epicDiscoveryResponses());
        Queue::fake();

        $user = $this->superuser();
        $response = $this->selectScope($user, $sourceId)
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
            'integrations.network.allowed_hosts' => [$this->epicHost()],
            'integrations.network.require_dns_resolution' => false,
        ]);
        $keyReference = $this->privateKeyReference();
        $source = app(OperationalIntegrationConfigurator::class)->configureEpicSandbox(
            clientId: 'registered-non-production-client',
            privateKeyRef: $keyReference,
            keyId: 'test-key-1',
            activate: true,
            facilityKey: $this->facilityKey,
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
                            'identifier' => [['value' => 'RECOGNIZABLE-FHIR-BODY-9911']],
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
        $rawPayload = DB::table('raw.inbound_messages')->where('source_id', $sourceId)->where('message_type', 'FHIR_R4_Encounter')->firstOrFail();
        $fhirPayload = DB::table('fhir.resource_versions')->where('source_id', $sourceId)->where('fhir_id', 'sandbox-encounter-1')->firstOrFail();
        $this->assertNull($rawPayload->payload);
        $this->assertSame('{}', $fhirPayload->resource_data);
        $this->assertSame((int) $rawPayload->payload_object_id, (int) $fhirPayload->payload_object_id);
        $this->assertStringNotContainsString('RECOGNIZABLE-FHIR-BODY-9911', json_encode([
            $rawPayload,
            $fhirPayload,
            DB::table('integration.provenance_records')->where('source_id', $sourceId)->get(),
        ], JSON_THROW_ON_ERROR));
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
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'tenant_key' => 'RUNTIME_TEST_IDN',
            'facility_key' => $this->facilityKey,
        ]);
        $pendingId = $this->canonicalEvent((int) $source->source_id, 'pending');
        $this->canonicalEvent((int) $source->source_id, 'projected');
        $user = $this->superuser();
        $approver = $this->superuser();
        $scope = [
            'source_id' => (int) $source->source_id,
            'from' => now()->subHour()->toIso8601String(),
            'to' => now()->addHour()->toIso8601String(),
            'limit' => 50,
        ];

        $this->selectScope($user, (int) $source->source_id)->postJson('/api/admin/integrations/enterprise/replays/preview', $scope)
            ->assertOk()
            ->assertJsonPath('data.eligibleEvents', 1)
            ->assertJsonPath('data.mutation', false);
        $this->assertSame(0, DB::table('integration.event_replay_jobs')->count());

        Queue::fake();
        $stepUp = [
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'password',
        ];
        $changeUuid = $this->selectScope($user, (int) $source->source_id)->withSession($stepUp)
            ->postJson('/api/admin/integrations/enterprise/replays/requests', [
                ...$scope,
                'reason' => 'The bounded replay preview and recovery plan were reviewed.',
            ])->assertCreated()->json('data.changeRequestUuid');
        $this->selectScope($approver, (int) $source->source_id)->withSession($stepUp)
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/decision", [
                'decision' => 'approved',
                'reason' => 'Independent reviewer confirmed the replay scope and event count.',
            ])->assertOk();

        $execution = [...$scope, 'change_request_uuid' => $changeUuid];
        $first = $this->selectScope($user, (int) $source->source_id)->withSession($stepUp)
            ->postJson('/api/admin/integrations/enterprise/replays', $execution, ['Idempotency-Key' => 'replay-test-1'])
            ->assertAccepted();
        $replayId = (int) $first->json('data.replayJobId');
        $this->selectScope($user, (int) $source->source_id)->withSession($stepUp)
            ->postJson('/api/admin/integrations/enterprise/replays', $execution, ['Idempotency-Key' => 'replay-test-1'])
            ->assertConflict()->assertJsonPath('error.code', 'change_already_executed');
        $changedScope = array_merge($execution, ['limit' => 49]);
        $this->selectScope($user, (int) $source->source_id)->withSession($stepUp)
            ->postJson('/api/admin/integrations/enterprise/replays', $changedScope, ['Idempotency-Key' => 'replay-test-2'])
            ->assertConflict()->assertJsonPath('error.code', 'approved_payload_mismatch');
        $job = new ReplayPendingIntegrationEvents($replayId, $user->id, (string) Str::uuid());
        $this->assertSame('database', $job->connection);
        $this->assertSame('integrations', $job->queue);
        $job->handle(app(\App\Integrations\Healthcare\Services\RtdcProjectionHandler::class), app(\App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService::class));

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
            'integrations.network.allowed_hosts' => [$this->epicHost()],
            'integrations.network.require_dns_resolution' => false,
        ]);
        $source = app(OperationalIntegrationConfigurator::class)->configureEpicSandbox(
            clientId: 'registered-non-production-client',
            privateKeyRef: $this->privateKeyReference(),
            activate: true,
            facilityKey: $this->facilityKey,
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
                'facility_key' => $this->facilityKey,
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
            'facility_key' => $this->facilityKey,
            'environment' => 'production',
            'contract_status' => 'executed',
            'baa_status' => 'executed',
            'phi_allowed' => true,
            'go_live_status' => 'testing',
            'activate' => false,
        ]);
        $this->completeHl7Readiness((int) $source['sourceId']);
        $author = $this->superuser();
        $approver = $this->superuser();
        $stepUp = [
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'password',
        ];
        $changeUuid = $this->selectScope($author, (int) $source['sourceId'])->withSession($stepUp)
            ->postJson("/api/admin/integrations/sources/{$source['sourceId']}/activation-requests", [
                'reason' => 'Production HL7 contract, BAA, ingress, cutover, and rollback evidence passed review.',
            ])->assertCreated()->json('data.changeRequestUuid');
        $this->selectScope($approver, (int) $source['sourceId'])->withSession($stepUp)
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/decision", [
                'decision' => 'approved',
                'reason' => 'Independent reviewer verified the exact source version and production evidence.',
            ])->assertOk();
        $this->selectScope($author, (int) $source['sourceId'])->withSession($stepUp)
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/execute-source-activation")
            ->assertOk()
            ->assertJsonPath('data.lifecycleState', 'live');
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

    private function epicHost(): string
    {
        return (string) parse_url((string) config('integrations.epic_sandbox.base_url'), PHP_URL_HOST);
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

    private function completeHl7Readiness(int $sourceId): void
    {
        $endpointId = (int) DB::table('integration.source_endpoints')->insertGetId([
            'source_id' => $sourceId,
            'endpoint_type' => 'interface_engine_ingress',
            'url' => 'https://hl7-gateway.test/interfaces/adt',
            'auth_type' => 'managed_gateway',
            'tls_mode' => 'mutual_tls',
            'is_active' => true,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_endpoint_id');
        app(NetworkRouteService::class)->create($sourceId, [
            'route_key' => 'interface-engine-mllp-tls',
            'source_endpoint_id' => $endpointId,
            'transport' => 'public_internet',
            'hostname' => 'hl7-gateway.test',
            'port' => 443,
            'dns_policy' => 'public_only',
            'allowed_ip_cidrs' => [],
            'egress_policy_key' => 'integration-https-egress',
            'mtls_required' => false,
            'change_reason' => 'Authorize the exact managed HL7 gateway route for readiness testing.',
        ], null);
        $onboarding = app(SourceOnboardingService::class);
        $current = $onboarding->latest($sourceId);
        $onboarding->revise($sourceId, [
            'system_version' => 'May 2026',
            'protocol_profile' => 'HL7 v2.5.1 ADT A01-A13 gateway profile',
            'owner_name' => 'Interface Engineering',
            'steward_name' => 'Clinical Data Governance',
            'network_route_key' => 'interface-engine-mllp-tls',
            'data_classification' => 'restricted_phi',
            'permitted_purpose' => 'Operate the production ADT patient-flow interface.',
            'phi_permission_basis' => 'Executed BAA and treatment operations authorization.',
            'retention_policy_key' => 'adt-interface-7y',
            'retention_days' => 2555,
            'credential_strategy' => 'managed_interface_engine',
            'conformance_status' => 'passed',
            'support_entitlement' => 'critical',
            'vendor_support_identifier' => 'EPIC-HL7-SUPPORT',
            'maintenance_timezone' => 'America/New_York',
            'contacts' => [
                ['role' => 'owner', 'name' => 'Interface Engineering', 'email' => 'interfaces@example.test'],
                ['role' => 'steward', 'name' => 'Clinical Data Governance', 'email' => 'governance@example.test'],
                ['role' => 'escalation', 'name' => 'Operations Command', 'email' => 'command@example.test'],
            ],
            'maintenance_windows' => [
                ['weekday' => 0, 'start_local' => '02:00', 'duration_minutes' => 60, 'purpose' => 'Interface maintenance'],
            ],
            'slo_definition' => [
                'availability_percent' => 99.99,
                'freshness_minutes' => 1,
                'completeness_percent' => 99.9,
                'latency_ms' => 1000,
                'error_rate_percent' => 0.1,
                'acknowledgement_seconds' => 10,
                'reconciliation_variance_percent' => 0.1,
            ],
        ], (int) $current->source_onboarding_version_id, null, 'Complete the production HL7 onboarding readiness profile.');

        foreach ([
            'contract' => 'verified', 'baa' => 'verified', 'dua' => 'not_required',
            'conformance_report' => 'verified', 'vendor_approval' => 'verified',
            'customer_uat' => 'verified', 'test_results' => 'verified',
            'security_review' => 'verified', 'change_ticket' => 'verified',
            'cutover_plan' => 'verified', 'rollback_plan' => 'verified',
        ] as $type => $status) {
            $onboarding->addEvidence($sourceId, [
                'evidence_type' => $type,
                'evidence_status' => $status,
                'display_label' => str_replace('_', ' ', ucfirst($type)).' HL7 evidence',
                'reference_uri' => 'https://evidence.test/hl7/'.$type,
                'artifact_sha256' => str_repeat('e', 64),
                'issued_at' => now()->subDay(),
                'expires_at' => now()->addYear(),
                'reason' => 'Record reviewed production HL7 activation evidence.',
            ], null);
        }

        // INT-LIFECYCLE: the tightened activation gate requires the governed
        // conformance/contract facets to be passed/active independently of
        // onboarding evidence.
        $facets = app(\App\Integrations\Healthcare\Services\SourceStatusFacetService::class);
        $facets->recordConformance($sourceId, 'passed', 'hl7v2-adt', '2.5', 'Vendor conformance verified for HL7 activation.', null);
        $contractEvidenceId = (int) \Illuminate\Support\Facades\DB::table('integration.source_evidence_records')
            ->where('source_id', $sourceId)
            ->where('evidence_type', 'contract')
            ->where('evidence_status', 'verified')
            ->orderByDesc('source_evidence_record_id')
            ->value('source_evidence_record_id');
        $facets->recordContract($sourceId, 'active', $contractEvidenceId, 'Executed HL7 contract entitlement present.', null);
    }

    /** @param array<string, list<string>> $answers */
    private function fakeDns(array $answers): void
    {
        $this->app->instance(DnsResolver::class, new class($answers) extends DnsResolver
        {
            /** @param array<string, list<string>> $answers */
            public function __construct(private readonly array $answers) {}

            public function resolve(string $host): array
            {
                return $this->answers[$host] ?? [];
            }
        });
        $this->app->forgetInstance(IntegrationUrlPolicy::class);
    }

    private function selectScope(User $user, int $sourceId): self
    {
        $this->actingAs($user)->put('/admin/active-scope', [
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'source_id' => $sourceId,
            'return_path' => '/integrations',
        ])->assertRedirect();

        return $this;
    }
}
