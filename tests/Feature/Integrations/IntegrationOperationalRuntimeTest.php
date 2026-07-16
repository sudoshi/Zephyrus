<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Integrations\Healthcare\Exceptions\IntegrationThrottledException;
use App\Integrations\Healthcare\Services\EpicSmartFhirClient;
use App\Integrations\Healthcare\Services\FhirConformanceObservationService;
use App\Integrations\Healthcare\Services\FhirResourceProfileService;
use App\Integrations\Healthcare\Services\IntegrationProtocolHealthService;
use App\Integrations\Healthcare\Services\NetworkRouteService;
use App\Integrations\Healthcare\Services\OperationalIntegrationConfigurator;
use App\Integrations\Healthcare\Services\SmartBackendFhirClient;
use App\Integrations\Healthcare\Services\SourceConfigurationVersionService;
use App\Integrations\Healthcare\Services\SourceOnboardingService;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Jobs\DispatchScheduledFhirPolls;
use App\Jobs\PollEpicFhirResource;
use App\Jobs\PollFhirResource;
use App\Jobs\ReplayPendingIntegrationEvents;
use App\Jobs\RunIntegrationProtocolHealthCheck;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Observability\Exporters\InMemoryMetricExporter;
use App\Security\Network\DnsResolver;
use App\Security\Network\IntegrationUrlPolicy;
use App\Services\Auth\StepUpAuthenticationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
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
        CarbonImmutable::setTestNow();

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
        $this->assertSame(['Encounter', 'Location'], DB::table('integration.fhir_resource_profiles')
            ->orderBy('resource_type')
            ->pluck('resource_type')
            ->all());
        $this->assertSame(['configured'], DB::table('integration.fhir_resource_profiles')
            ->distinct()
            ->pluck('profile_status')
            ->all());
        $this->assertSame(2, DB::table('integration.fhir_resource_profile_events')->count());
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
        $this->assertSame(['enabled'], DB::table('integration.fhir_resource_profiles')
            ->where('source_id', $sourceId)
            ->distinct()
            ->pluck('profile_status')
            ->all());
        $this->assertSame(4, DB::table('integration.fhir_resource_profile_events')
            ->where('source_id', $sourceId)
            ->count());
        $this->assertSame(0, DB::table('integration.connector_watermarks')->count(), 'Protocol reachability must not advance a data watermark.');
    }

    public function test_epic_smart_backend_poll_signs_a_jwt_and_persists_versioned_fhir_lineage(): void
    {
        config()->set('observability.enabled', true);
        config()->set('observability.exporter', 'memory');
        $telemetry = app(InMemoryMetricExporter::class);
        $telemetry->flush();
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
            'connector_key' => 'fhir.r4.encounter',
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
            'connector_key' => 'fhir.r4',
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
        $span = collect($telemetry->spans())
            ->first(fn ($record) => $record->name === 'zephyrus.integration.fhir.poll');
        $this->assertNotNull($span);
        $this->assertSame('ok', $span->status);
        $this->assertSame($sourceId, $span->attributes->toArray()['zephyrus.source.id']);
        $this->assertSame(1, $span->attributes->toArray()['fhir.resources.persisted']);
        $this->assertStringNotContainsString('RECOGNIZABLE-FHIR-BODY-9911', json_encode($span->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_fhir_search_uses_an_inclusive_cursor_strict_handling_and_safe_operation_outcomes(): void
    {
        $sourceId = $this->readyEpicSource();
        $runId = $this->fhirRun($sourceId, (string) Str::uuid());
        $cursor = '2026-07-10T12:00:00Z';
        DB::table('integration.connector_watermarks')->insert([
            'source_id' => $sourceId,
            'connector_key' => 'fhir.r4',
            'scope_type' => 'resource_type',
            'scope_key' => 'Encounter',
            'watermark_kind' => 'last_updated',
            'watermark_value' => $cursor,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $strictSearchSeen = false;
        Http::swap(new HttpFactory);
        Http::fake(function (Request $request) use (&$strictSearchSeen, $cursor) {
            if ($request->url() === config('integrations.epic_sandbox.token_url')) {
                return Http::response(['access_token' => 'strict-search-token', 'expires_in' => 300]);
            }
            if (str_starts_with($request->url(), config('integrations.epic_sandbox.base_url').'/Encounter')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $strictSearchSeen = $request->hasHeader('Prefer', 'handling=strict')
                    && ($query['_lastUpdated'] ?? null) === 'ge'.$cursor
                    && ($query['_sort'] ?? null) === '_lastUpdated';

                return Http::response([
                    'resourceType' => 'Bundle',
                    'type' => 'searchset',
                    'entry' => [
                        [
                            'search' => ['mode' => 'include'],
                            'resource' => ['resourceType' => 'Patient', 'id' => 'included-patient'],
                        ],
                        [
                            'search' => ['mode' => 'outcome'],
                            'resource' => [
                                'resourceType' => 'OperationOutcome',
                                'issue' => [[
                                    'severity' => 'warning',
                                    'code' => 'processing',
                                    'diagnostics' => 'RECOGNIZABLE-OUTCOME-DIAGNOSTIC-9911',
                                ]],
                            ],
                        ],
                        [
                            'search' => ['mode' => 'match'],
                            'resource' => [
                                'resourceType' => 'Encounter',
                                'id' => 'inclusive-boundary-encounter',
                                'meta' => ['versionId' => '1', 'lastUpdated' => $cursor],
                                'status' => 'finished',
                            ],
                        ],
                    ],
                ], 200, ['Content-Type' => 'application/fhir+json']);
            }

            return Http::response([], 404);
        });

        $result = app(SmartBackendFhirClient::class)->poll($sourceId, 'Encounter', $runId);

        $this->assertTrue($strictSearchSeen);
        $this->assertSame('search', $result['pollingInteraction']);
        $this->assertSame(1, $result['resourcesReceived']);
        $this->assertSame(1, $result['resourcesPersisted']);
        $this->assertSame(2, $result['resourcesSkipped']);
        $this->assertSame(1, $result['operationOutcomes']);
        $this->assertSame(1, DB::table('raw.inbound_messages')->where('source_id', $sourceId)->count());
        $this->assertStringNotContainsString('RECOGNIZABLE-OUTCOME-DIAGNOSTIC-9911', (string) json_encode([
            DB::table('raw.inbound_messages')->where('source_id', $sourceId)->get(),
            DB::table('integration.source_runtime_pressure_events')->where('source_id', $sourceId)->get(),
        ], JSON_THROW_ON_ERROR));

        Http::swap(new HttpFactory);
        Http::fake(function (Request $request) {
            if ($request->url() === config('integrations.epic_sandbox.token_url')) {
                return Http::response(['access_token' => 'outcome-error-token', 'expires_in' => 300]);
            }

            return Http::response([
                'resourceType' => 'Bundle',
                'type' => 'searchset',
                'entry' => [[
                    'search' => ['mode' => 'outcome'],
                    'resource' => [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [[
                            'severity' => 'error',
                            'code' => 'invalid',
                            'diagnostics' => 'RECOGNIZABLE-FATAL-DIAGNOSTIC-7744',
                        ]],
                    ],
                ]],
            ], 200, ['Content-Type' => 'application/fhir+json']);
        });
        try {
            app(SmartBackendFhirClient::class)->poll($sourceId, 'Encounter', $this->fhirRun($sourceId, (string) Str::uuid()));
            $this->fail('An error OperationOutcome must fail the search without exposing its diagnostics.');
        } catch (IntegrationProtocolException $exception) {
            $this->assertSame('fhir_operation_outcome_error', $exception->errorCode);
            $this->assertStringNotContainsString('RECOGNIZABLE-FATAL-DIAGNOSTIC-7744', $exception->getMessage());
        }
        $this->assertSame(
            CarbonImmutable::parse($cursor)->toIso8601String(),
            CarbonImmutable::parse((string) DB::table('integration.connector_watermarks')
                ->where('source_id', $sourceId)
                ->where('scope_key', 'Encounter')
                ->where('watermark_kind', 'last_updated')
                ->value('watermark_value'))->toIso8601String(),
        );
    }

    public function test_fhir_type_history_persists_encrypted_versions_and_deletion_tombstones(): void
    {
        $sourceId = $this->readyEpicSource();
        $profile = app(FhirResourceProfileService::class)->configure(
            $sourceId,
            'Encounter',
            ['polling_interaction' => 'history'],
            null,
            'Use type history so version changes and deletions are captured.',
            (string) Str::uuid(),
        );
        $this->assertSame('history', $profile->polling_interaction);
        $this->assertSame('enabled', $profile->profile_status);

        $runId = $this->fhirRun($sourceId, (string) Str::uuid());
        $strictHistorySeen = false;
        Http::swap(new HttpFactory);
        Http::fake(function (Request $request) use (&$strictHistorySeen) {
            if ($request->url() === config('integrations.epic_sandbox.token_url')) {
                return Http::response(['access_token' => 'history-token', 'expires_in' => 300]);
            }
            if (str_starts_with($request->url(), config('integrations.epic_sandbox.base_url').'/Encounter/_history')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $strictHistorySeen = $request->hasHeader('Prefer', 'handling=strict')
                    && ($query['_count'] ?? null) === '100'
                    && ! isset($query['_since']);

                return Http::response([
                    'resourceType' => 'Bundle',
                    'type' => 'history',
                    'entry' => [
                        [
                            'resource' => [
                                'resourceType' => 'Encounter',
                                'id' => 'history-encounter',
                                'meta' => ['versionId' => '2', 'lastUpdated' => '2026-07-10T12:00:00Z'],
                                'status' => 'finished',
                                'identifier' => [['value' => 'RECOGNIZABLE-HISTORY-BODY-5511']],
                            ],
                            'request' => ['method' => 'PUT', 'url' => 'Encounter/history-encounter'],
                            'response' => ['status' => '200', 'etag' => 'W/"2"', 'lastModified' => '2026-07-10T12:00:00Z'],
                        ],
                        [
                            'request' => ['method' => 'DELETE', 'url' => 'Encounter/deleted-encounter'],
                            'response' => ['status' => '204', 'etag' => 'W/"4"', 'lastModified' => '2026-07-10T12:05:00Z'],
                        ],
                    ],
                ], 200, ['Content-Type' => 'application/fhir+json']);
            }

            return Http::response([], 404);
        });

        $result = app(SmartBackendFhirClient::class)->poll($sourceId, 'Encounter', $runId);

        $this->assertTrue($strictHistorySeen);
        $this->assertSame('history', $result['pollingInteraction']);
        $this->assertSame(2, $result['resourcesReceived']);
        $this->assertSame(2, $result['resourcesPersisted']);
        $this->assertSame(1, $result['resourcesDeleted']);
        $this->assertDatabaseHas('fhir.resource_versions', [
            'source_id' => $sourceId,
            'fhir_id' => 'history-encounter',
            'version_id' => '2',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('fhir.resource_versions', [
            'source_id' => $sourceId,
            'fhir_id' => 'deleted-encounter',
            'version_id' => '4',
        ]);
        $activeVersion = DB::table('fhir.resource_versions')->where('fhir_id', 'history-encounter')->firstOrFail();
        $tombstone = DB::table('fhir.resource_versions')->where('fhir_id', 'deleted-encounter')->firstOrFail();
        $this->assertNotNull($activeVersion->payload_object_id);
        $this->assertNotNull($tombstone->deleted_at);
        $this->assertNull($tombstone->payload_object_id);
        $this->assertSame('{}', $tombstone->resource_data);
        $this->assertDatabaseHas('raw.inbound_messages', [
            'source_id' => $sourceId,
            'message_type' => 'FHIR_R4_Encounter_DELETE',
            'payload_object_id' => null,
        ]);
        $this->assertSame('2026-07-10T12:05:00+00:00', CarbonImmutable::parse((string) DB::table('integration.connector_watermarks')
            ->where('source_id', $sourceId)
            ->where('scope_key', 'Encounter')
            ->where('watermark_kind', 'history_since')
            ->value('watermark_value'))->toIso8601String());
        $this->assertStringNotContainsString('RECOGNIZABLE-HISTORY-BODY-5511', (string) json_encode([
            DB::table('raw.inbound_messages')->where('source_id', $sourceId)->get(),
            DB::table('fhir.resource_versions')->where('source_id', $sourceId)->get(),
        ], JSON_THROW_ON_ERROR));

        $inclusiveSinceSeen = false;
        Http::swap(new HttpFactory);
        Http::fake(function (Request $request) use (&$inclusiveSinceSeen) {
            if ($request->url() === config('integrations.epic_sandbox.token_url')) {
                return Http::response(['access_token' => 'history-cursor-token', 'expires_in' => 300]);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $inclusiveSinceSeen = isset($query['_since'])
                && CarbonImmutable::parse((string) $query['_since'])->equalTo(CarbonImmutable::parse('2026-07-10T12:05:00Z'));

            return Http::response(
                ['resourceType' => 'Bundle', 'type' => 'history', 'entry' => []],
                200,
                ['Content-Type' => 'application/fhir+json'],
            );
        });
        $secondResult = app(SmartBackendFhirClient::class)->poll(
            $sourceId,
            'Encounter',
            $this->fhirRun($sourceId, (string) Str::uuid()),
        );
        $this->assertTrue($inclusiveSinceSeen);
        $this->assertFalse($secondResult['watermarkAdvanced']);
        $this->assertSame(2, DB::table('fhir.resource_versions')->where('source_id', $sourceId)->count());
    }

    public function test_fhir_history_profile_fails_closed_without_type_history_capability(): void
    {
        config([
            'integrations.network.allowed_hosts' => [$this->epicHost()],
            'integrations.network.require_dns_resolution' => false,
        ]);
        $source = app(OperationalIntegrationConfigurator::class)->configureEpicSandbox(
            clientId: 'search-only-client',
            privateKeyRef: $this->privateKeyReference(),
            activate: true,
            facilityKey: $this->facilityKey,
        );
        $sourceId = (int) $source['sourceId'];
        Http::fake($this->epicDiscoveryResponses(historyResourceTypes: []));
        app(IntegrationProtocolHealthService::class)->check($sourceId);

        $profile = app(FhirResourceProfileService::class)->configure(
            $sourceId,
            'Encounter',
            ['polling_interaction' => 'history'],
            null,
            'Reject type history until the server advertises that interaction.',
            (string) Str::uuid(),
        );

        $this->assertSame('history', $profile->polling_interaction);
        $this->assertSame('configured', $profile->profile_status);
        try {
            app(FhirResourceProfileService::class)->requirePollable($sourceId, 'Encounter');
            $this->fail('History polling must remain blocked without history-type conformance evidence.');
        } catch (IntegrationProtocolException $exception) {
            $this->assertSame('fhir_polling_interaction_not_supported', $exception->errorCode);
        }
    }

    public function test_generic_smart_fhir_core_governs_dynamic_resource_profiles_and_vendor_policy(): void
    {
        $sourceId = $this->readyEpicSource();
        $profiles = app(FhirResourceProfileService::class);
        $user = $this->superuser();
        $this->selectScope($user, $sourceId)
            ->putJson('/api/admin/integrations/sources/'.$sourceId.'/fhir/resource-profiles/Observation', [
                'canonical_profile_url' => 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-observation-clinical-result',
                'canonical_profile_version' => '7.0.0',
                'poll_enabled' => true,
                'cadence_minutes' => 5,
                'page_size' => 100,
                'page_limit' => 10,
                'resource_limit' => 1000,
                'reason' => 'Configure the approved Observation polling profile.',
            ])
            ->assertOk()
            ->assertJsonPath('data.resourceType', 'Observation')
            ->assertJsonPath('data.pollingInteraction', 'search')
            ->assertJsonPath('data.status', 'configured')
            ->assertJsonPath('data.versionNumber', 1);
        $observation = DB::table('integration.fhir_resource_profiles')
            ->where('source_id', $sourceId)
            ->where('resource_type', 'Observation')
            ->firstOrFail();

        $this->assertDatabaseHas('integration.fhir_resource_profiles', [
            'source_id' => $sourceId,
            'resource_type' => 'Observation',
            'profile_status' => 'configured',
            'version_number' => 1,
        ]);
        $this->assertQueryRejected(function () use ($observation): void {
            DB::table('integration.fhir_resource_profiles')
                ->where('fhir_resource_profile_id', $observation->fhir_resource_profile_id)
                ->update([
                    'profile_status' => 'enabled',
                    'version_number' => 2,
                    'reason_code' => 'unsafe_direct_enable',
                    'updated_at' => now(),
                ]);
        }, 'without discovered capability');

        DB::table('integration.source_capabilities')->insert([
            'source_id' => $sourceId,
            'resource_type' => 'Observation',
            'capability_type' => 'fhir_resource',
            'operation' => 'read_search',
            'supported' => true,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertQueryRejected(function () use ($observation): void {
            DB::table('integration.fhir_resource_profiles')
                ->where('fhir_resource_profile_id', $observation->fhir_resource_profile_id)
                ->update([
                    'profile_status' => 'enabled',
                    'version_number' => 2,
                    'reason_code' => 'unsafe_direct_enable',
                    'updated_at' => now(),
                ]);
        }, 'without SMART system read scope');
        try {
            $profiles->requirePollable($sourceId, 'Observation');
            $this->fail('A configured resource without an authorized SMART scope must not be pollable.');
        } catch (IntegrationProtocolException $exception) {
            $this->assertSame('smart_scope_not_authorized_for_resource', $exception->errorCode);
        }

        DB::table('integration.smart_backend_credentials')
            ->where('source_id', $sourceId)
            ->update([
                'scope_payload' => json_encode([
                    'system/Encounter.rs',
                    'system/Location.rs',
                    'system/Observation.rs',
                ], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        $currentVersionId = (int) DB::table('integration.sources')
            ->where('source_id', $sourceId)
            ->value('current_configuration_version_id');
        app(SourceConfigurationVersionService::class)->reviseAndApply(
            $sourceId,
            ['vendor' => 'Oracle Health'],
            $currentVersionId,
            null,
            'Verify the generic SMART/FHIR core against a non-Epic vendor policy.',
            (string) Str::uuid(),
        );
        Http::swap(new HttpFactory);
        Http::fake($this->epicDiscoveryResponses('Oracle Health', ['Encounter', 'Location', 'Observation']));
        $health = app(IntegrationProtocolHealthService::class)->check($sourceId);

        $this->assertSame('Oracle Health', $health['vendor']);
        $this->assertDatabaseHas('integration.fhir_resource_profiles', [
            'source_id' => $sourceId,
            'resource_type' => 'Observation',
            'profile_status' => 'enabled',
            'version_number' => 2,
        ]);
        $this->assertContains('Observation', $profiles->pollableResourceTypes($sourceId));

        $this->selectScope($user, $sourceId)
            ->getJson('/api/admin/integrations/control-plane')
            ->assertOk()
            ->assertJsonPath('data.fhirConnections.0.resourceProfiles.2.resourceType', 'Observation')
            ->assertJsonPath('data.fhirConnections.0.resourceProfiles.2.status', 'enabled');
        Queue::fake();
        $queued = $this->selectScope($user, $sourceId)
            ->postJson('/api/admin/integrations/sources/'.$sourceId.'/fhir/poll', [
                'resource_type' => 'Observation',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.resourceType', 'Observation');
        $runId = (int) $queued->json('data.runId');
        Queue::assertPushed(PollFhirResource::class, fn (PollFhirResource $job): bool => $job->runId === $runId
            && $job->resourceType === 'Observation');

        Http::fake(function (Request $request) {
            if ($request->url() === config('integrations.epic_sandbox.token_url')) {
                return Http::response(['access_token' => 'generic-smart-token', 'expires_in' => 300]);
            }

            return Http::response(
                ['resourceType' => 'Bundle', 'type' => 'searchset', 'entry' => []],
                200,
                ['Content-Type' => 'application/fhir+json'],
            );
        });
        $result = app(SmartBackendFhirClient::class)->poll($sourceId, 'Observation', $runId);
        $this->assertSame('Observation', $result['resourceType']);
        $this->assertSame(0, $result['resourcesReceived']);
        DB::table('raw.ingest_runs')->where('ingest_run_id', $runId)->update([
            'status' => 'completed',
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
        Queue::fake();
        (new DispatchScheduledFhirPolls)->handle(app(\App\Integrations\Healthcare\Services\EnterpriseConnectorControlService::class));
        Queue::assertPushed(PollFhirResource::class, 3);
        foreach (['Encounter', 'Location', 'Observation'] as $scheduledResourceType) {
            Queue::assertPushed(
                PollFhirResource::class,
                fn (PollFhirResource $job): bool => $job->resourceType === $scheduledResourceType,
            );
        }

        DB::table('integration.source_capabilities')
            ->where('source_id', $sourceId)
            ->where('resource_type', 'Observation')
            ->delete();
        $profiles->reconcileCapabilities($sourceId, ['Encounter', 'Location']);
        $this->assertDatabaseHas('integration.fhir_resource_profiles', [
            'source_id' => $sourceId,
            'resource_type' => 'Observation',
            'profile_status' => 'suspended',
            'version_number' => 3,
        ]);
        $this->assertSame(3, DB::table('integration.fhir_resource_profile_events')
            ->where('fhir_resource_profile_id', $observation->fhir_resource_profile_id)
            ->count());

        $eventId = DB::table('integration.fhir_resource_profile_events')
            ->where('fhir_resource_profile_id', $observation->fhir_resource_profile_id)
            ->value('fhir_resource_profile_event_id');
        $this->assertQueryRejected(
            fn () => DB::table('integration.fhir_resource_profile_events')
                ->where('fhir_resource_profile_event_id', $eventId)
                ->update(['reason_code' => 'tampered']),
            'append-only',
        );
        $this->assertQueryRejected(
            fn () => DB::table('integration.fhir_resource_profiles')
                ->where('fhir_resource_profile_id', $observation->fhir_resource_profile_id)
                ->delete(),
            'must be retired',
        );
        $this->assertQueryRejected(
            fn () => DB::table('integration.fhir_resource_profiles')
                ->where('fhir_resource_profile_id', $observation->fhir_resource_profile_id)
                ->update([
                    'canonical_profile_url' => 'https://profiles.example/ZPHI-FHIR-PROFILE-9911',
                    'version_number' => 4,
                    'reason_code' => 'profile_metadata_changed',
                    'updated_at' => now(),
                ]),
            'clinical content',
        );
        $this->selectScope($user, $sourceId)
            ->deleteJson('/api/admin/integrations/sources/'.$sourceId.'/fhir/resource-profiles/'.$observation->fhir_resource_profile_id, [
                'reason' => 'Retire the superseded Observation polling profile after capability withdrawal.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'retired')
            ->assertJsonPath('data.pollEnabled', false)
            ->assertJsonPath('data.versionNumber', 4);
        $this->assertDatabaseHas('integration.fhir_resource_profiles', [
            'fhir_resource_profile_id' => $observation->fhir_resource_profile_id,
            'profile_status' => 'retired',
            'poll_enabled' => false,
            'change_reason' => 'Retire the superseded Observation polling profile after capability withdrawal.',
        ]);
        $this->assertSame(4, DB::table('integration.fhir_resource_profile_events')
            ->where('fhir_resource_profile_id', $observation->fhir_resource_profile_id)
            ->count());
    }

    public function test_replay_preview_is_read_only_and_execution_is_bounded_idempotent_and_audited(): void
    {
        config()->set('observability.enabled', true);
        config()->set('observability.exporter', 'memory');
        $telemetry = app(InMemoryMetricExporter::class);
        $telemetry->flush();
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
        $projectedEventUuid = (string) DB::table('integration.canonical_events')
            ->where('canonical_event_id', $pendingId)
            ->value('event_id');
        $span = collect($telemetry->spans())
            ->first(fn ($record) => $record->name === 'zephyrus.integration.rtdc.project');
        $this->assertNotNull($span);
        $this->assertSame('ok', $span->status);
        $this->assertSame($projectedEventUuid, $span->attributes->toArray()['zephyrus.event.uuid']);
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
            'connector_key' => 'fhir.r4.encounter',
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

    public function test_fhir_429_honors_retry_after_blocks_early_reentry_and_records_attempt_evidence(): void
    {
        $now = CarbonImmutable::parse('2026-07-15T14:00:00Z');
        CarbonImmutable::setTestNow($now);
        $sourceId = $this->readyEpicSource();
        $runId = $this->fhirRun($sourceId, (string) Str::uuid());
        $job = new PollEpicFhirResource($runId, 'Encounter', null, (string) Str::uuid());
        $recovered = false;

        Http::fake(function (Request $request) use (&$recovered) {
            if ($request->url() === config('integrations.epic_sandbox.token_url')) {
                return Http::response(['access_token' => $recovered ? 'recovery-token' : 'throttle-test-token', 'expires_in' => 300]);
            }

            return $recovered
                ? Http::response(
                    ['resourceType' => 'Bundle', 'type' => 'searchset', 'entry' => []],
                    200,
                    ['Content-Type' => 'application/fhir+json'],
                )
                : Http::response(
                    ['resourceType' => 'OperationOutcome'],
                    429,
                    ['Retry-After' => '120', 'Content-Type' => 'application/fhir+json'],
                );
        });

        try {
            $job->handle(
                app(EpicSmartFhirClient::class),
                app(\App\Integrations\Healthcare\Services\IntegrationConfigurationAuditService::class),
            );
            $this->fail('A throttled FHIR search must not be reported as completed.');
        } catch (IntegrationProtocolException $exception) {
            $this->assertSame('fhir_http_429', $exception->errorCode);
        }

        $this->assertDatabaseHas('integration.source_runtime_pressure_events', [
            'source_id' => $sourceId,
            'connector_key' => 'fhir.r4.encounter',
            'pressure_kind' => 'rate_limit',
            'pressure_state' => 'throttled',
            'http_status' => 429,
            'retry_after_seconds' => 120,
        ]);
        $this->assertDatabaseHas('integration.source_runtime_execution_events', [
            'ingest_run_id' => $runId,
            'event_type' => 'throttled',
            'attempt_number' => 1,
            'retry_after_seconds' => 120,
        ]);
        $this->assertDatabaseHas('integration.source_runtime_execution_events', [
            'ingest_run_id' => $runId,
            'event_type' => 'retry_scheduled',
            'attempt_number' => 1,
            'retry_after_seconds' => 120,
        ]);
        $requestsAfterThrottle = count(Http::recorded());

        try {
            app(EpicSmartFhirClient::class)->poll($sourceId, 'Encounter', $runId);
            $this->fail('The active Retry-After window must block an early partner call.');
        } catch (IntegrationThrottledException $exception) {
            $this->assertSame('partner_rate_limit_active', $exception->errorCode);
            $this->assertSame(120, $exception->retryAfterSeconds);
        }
        $this->assertCount($requestsAfterThrottle, Http::recorded());

        CarbonImmutable::setTestNow($now->addSeconds(121));
        $recovered = true;
        $result = app(EpicSmartFhirClient::class)->poll($sourceId, 'Encounter', $runId);
        $this->assertSame(0, $result['resourcesReceived']);
        $this->assertDatabaseHas('integration.source_runtime_pressure_events', [
            'source_id' => $sourceId,
            'connector_key' => 'fhir.r4.encounter',
            'pressure_kind' => 'rate_limit',
            'pressure_state' => 'normal',
            'reason_code' => 'partner_call_succeeded_rate_limit_cleared',
        ]);
    }

    public function test_fhir_circuit_opens_without_a_third_partner_call_then_half_open_probe_closes_it(): void
    {
        config([
            'integrations.observability.circuit_breaker_trip_failures' => 2,
            'integrations.observability.circuit_breaker_open_seconds' => 120,
        ]);
        $now = CarbonImmutable::parse('2026-07-15T15:00:00Z');
        CarbonImmutable::setTestNow($now);
        $sourceId = $this->readyEpicSource();
        $runId = $this->fhirRun($sourceId, (string) Str::uuid());
        $recovered = false;
        Http::fake(function (Request $request) use (&$recovered) {
            if ($request->url() === config('integrations.epic_sandbox.token_url')) {
                return $recovered
                    ? Http::response(['access_token' => 'half-open-recovery-token', 'expires_in' => 300])
                    : Http::response([], 503);
            }

            return Http::response(
                ['resourceType' => 'Bundle', 'type' => 'searchset', 'entry' => []],
                200,
                ['Content-Type' => 'application/fhir+json'],
            );
        });

        foreach ([1, 2] as $failure) {
            try {
                app(EpicSmartFhirClient::class)->poll($sourceId, 'Encounter', $runId);
                $this->fail("Transient partner failure {$failure} should fail the poll.");
            } catch (IntegrationProtocolException $exception) {
                $this->assertSame('smart_token_http_503', $exception->errorCode);
            }
        }
        $this->assertDatabaseHas('integration.source_runtime_pressure_events', [
            'source_id' => $sourceId,
            'connector_key' => 'fhir.r4.encounter',
            'pressure_kind' => 'circuit_breaker',
            'pressure_state' => 'open',
            'consecutive_failures' => 2,
            'retry_after_seconds' => 120,
        ]);
        $requestsAtOpen = count(Http::recorded());

        try {
            app(EpicSmartFhirClient::class)->poll($sourceId, 'Encounter', $runId);
            $this->fail('An open circuit must reject before a partner call.');
        } catch (IntegrationProtocolException $exception) {
            $this->assertSame('source_circuit_open', $exception->errorCode);
        }
        $this->assertCount($requestsAtOpen, Http::recorded());

        CarbonImmutable::setTestNow($now->addSeconds(121));
        $recovered = true;
        app(EpicSmartFhirClient::class)->poll($sourceId, 'Encounter', $runId);

        $transitions = DB::table('integration.source_runtime_pressure_events')
            ->where('source_id', $sourceId)
            ->where('connector_key', 'fhir.r4.encounter')
            ->where('pressure_kind', 'circuit_breaker')
            ->orderBy('source_runtime_pressure_event_id')
            ->pluck('pressure_state')
            ->all();
        $this->assertSame(['normal', 'open', 'half_open', 'normal'], $transitions);
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

        $this->actingAs($user)->getJson('/api/admin/integrations/sources/'.$source->source_id.'/fhir/conformance')->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/integrations/sources/'.$source->source_id.'/health-check')->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/integrations/sources/'.$source->source_id.'/fhir/poll', ['resource_type' => 'Encounter'])->assertForbidden();
        $this->actingAs($user)->putJson('/api/admin/integrations/sources/'.$source->source_id.'/fhir/resource-profiles/Observation', [])->assertForbidden();
        $this->actingAs($user)->deleteJson('/api/admin/integrations/sources/'.$source->source_id.'/fhir/resource-profiles/1', [])->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/integrations/enterprise/replays/preview', [])->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/integrations/enterprise/replays', [])->assertForbidden();
    }

    public function test_fhir_discovery_is_full_append_only_evidence_with_a_source_scoped_projection(): void
    {
        config([
            'integrations.network.allowed_hosts' => [$this->epicHost()],
            'integrations.network.require_dns_resolution' => false,
        ]);
        $source = app(OperationalIntegrationConfigurator::class)->configureEpicSandbox(facilityKey: $this->facilityKey);
        $sourceId = (int) $source['sourceId'];
        Http::fake($this->epicDiscoveryResponses());

        $firstHealth = app(IntegrationProtocolHealthService::class)->check($sourceId);
        $connection = DB::table('integration.fhir_client_connections')->where('source_id', $sourceId)->first();
        $firstObservationId = (int) $connection->current_conformance_observation_id;
        $firstObservation = DB::table('integration.fhir_conformance_observations')
            ->where('fhir_conformance_observation_id', $firstObservationId)
            ->first();

        $this->assertSame($firstObservationId, $firstHealth['conformanceObservationId']);
        $this->assertSame('passed', $firstObservation->observation_status);
        $this->assertSame(2, (int) $firstObservation->resource_count);
        $this->assertSame(2, (int) $firstObservation->searchable_resource_count);
        $this->assertTrue((bool) $firstObservation->supports_batch);
        $this->assertTrue((bool) $firstObservation->supports_transaction);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $firstObservation->capability_document_sha256);
        $this->assertSame(['client_credentials'], json_decode($firstObservation->smart_grant_type_payload, true, flags: JSON_THROW_ON_ERROR));

        $encounter = DB::table('integration.fhir_conformance_resource_observations')
            ->where('fhir_conformance_observation_id', $firstObservationId)
            ->where('resource_type', 'Encounter')
            ->first();
        $this->assertSame('http://hl7.org/fhir/StructureDefinition/Encounter', $encounter->base_profile_url);
        $this->assertSame(1, (int) $encounter->search_parameter_count);
        $this->assertSame(1, (int) $encounter->operation_count);

        $response = $this->selectScope($this->superuser(), $sourceId)
            ->getJson('/api/admin/integrations/sources/'.$sourceId.'/fhir/conformance')
            ->assertOk()
            ->assertJsonPath('data.status', 'passed')
            ->assertJsonPath('data.observationId', $firstObservationId)
            ->assertJsonPath('data.fhirVersion', '4.0.1')
            ->assertJsonPath('data.smart.tokenOrigin', 'https://'.strtolower($this->epicHost()))
            ->assertJsonPath('data.resources.0.resourceType', 'Encounter')
            ->assertJsonPath('data.resources.0.searchParameters.0.name', '_id');
        $this->assertStringNotContainsString((string) config('integrations.epic_sandbox.token_url'), $response->getContent());
        $otherSource = app(SourceRegistryService::class)->ensureSource([
            'source_key' => 'fhir.conformance.other-source',
            'source_name' => 'Other FHIR Source',
            'tenant_key' => 'RUNTIME_TEST_IDN',
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'system_class' => 'ehr',
            'interface_type' => 'fhir_r4',
        ]);
        $this->getJson('/api/admin/integrations/sources/'.$otherSource->source_id.'/fhir/conformance')
            ->assertConflict();

        Http::swap(new HttpFactory);
        Http::fake($this->epicDiscoveryResponses(
            resourceTypes: ['Encounter', 'Location', 'Subscription'],
            searchableResourceTypes: ['Encounter', 'Location'],
        ));
        $secondHealth = app(IntegrationProtocolHealthService::class)->check($sourceId);
        $secondObservationId = (int) $secondHealth['conformanceObservationId'];
        $this->assertNotSame($firstObservationId, $secondObservationId);
        $this->assertDatabaseHas('integration.fhir_conformance_observations', [
            'fhir_conformance_observation_id' => $secondObservationId,
            'previous_observation_id' => $firstObservationId,
            'supports_subscriptions' => true,
            'resource_count' => 3,
            'searchable_resource_count' => 2,
        ]);
        $this->assertDatabaseMissing('integration.source_capabilities', [
            'source_id' => $sourceId,
            'resource_type' => 'Subscription',
            'capability_type' => 'fhir_resource',
        ]);
        $this->assertSame($secondObservationId, (int) DB::table('integration.fhir_client_connections')
            ->where('fhir_client_connection_id', $connection->fhir_client_connection_id)
            ->value('current_conformance_observation_id'));

        $this->assertQueryRejected(function () use ($firstObservationId): void {
            DB::table('integration.fhir_conformance_observations')
                ->where('fhir_conformance_observation_id', $firstObservationId)
                ->update(['observation_status' => 'passed_with_warnings']);
        }, 'append-only');
        $this->assertQueryRejected(function () use ($firstObservationId): void {
            DB::table('integration.fhir_conformance_resource_observations')
                ->where('fhir_conformance_observation_id', $firstObservationId)
                ->delete();
        }, 'append-only');
        $this->assertQueryRejected(function () use ($connection, $firstObservationId): void {
            DB::table('integration.fhir_client_connections')
                ->where('fhir_client_connection_id', $connection->fhir_client_connection_id)
                ->update(['current_conformance_observation_id' => $firstObservationId]);
        }, 'must advance one observation');
        $this->assertQueryRejected(function () use ($connection): void {
            DB::table('integration.fhir_client_connections')
                ->where('fhir_client_connection_id', $connection->fhir_client_connection_id)
                ->update(['current_conformance_observation_id' => null]);
        }, 'cannot be cleared');
        $this->assertQueryRejected(function () use ($sourceId, $secondObservationId): void {
            DB::table('integration.fhir_conformance_resource_observations')->insert([
                'fhir_conformance_observation_id' => $secondObservationId,
                'source_id' => $sourceId,
                'resource_type' => 'DiagnosticReport',
                'base_profile_url' => 'https://profiles.test/ZPHI-PATIENT-CONFORMANCE-9911',
                'created_at' => now(),
            ]);
        }, 'clinical content is prohibited');
    }

    public function test_fhir_discovery_rejects_token_endpoint_drift_without_advancing_evidence(): void
    {
        config([
            'integrations.network.allowed_hosts' => [$this->epicHost()],
            'integrations.network.require_dns_resolution' => false,
        ]);
        $source = app(OperationalIntegrationConfigurator::class)->configureEpicSandbox(facilityKey: $this->facilityKey);
        $sourceId = (int) $source['sourceId'];
        Http::fake($this->epicDiscoveryResponses(tokenUrl: rtrim((string) config('integrations.epic_sandbox.token_url'), '/').'/drifted'));

        try {
            app(IntegrationProtocolHealthService::class)->check($sourceId);
            $this->fail('Discovery must reject a token endpoint that differs from configured authority.');
        } catch (IntegrationProtocolException $exception) {
            $this->assertSame('smart_token_endpoint_drift', $exception->errorCode);
        }

        $this->assertSame(0, DB::table('integration.fhir_conformance_observations')->count());
        $this->assertNull(DB::table('integration.fhir_client_connections')
            ->where('source_id', $sourceId)
            ->value('current_conformance_observation_id'));
        $this->assertDatabaseHas('integration.sources', [
            'source_id' => $sourceId,
            'protocol_health_status' => 'failed',
            'protocol_health_error' => 'smart_token_endpoint_drift',
        ]);
    }

    public function test_fhir_conformance_capture_is_bounded_and_enforces_interactive_smart_pkce(): void
    {
        $source = app(OperationalIntegrationConfigurator::class)->configureEpicSandbox(facilityKey: $this->facilityKey);
        $sourceId = (int) $source['sourceId'];
        $connectionId = (int) DB::table('integration.fhir_client_connections')
            ->where('source_id', $sourceId)
            ->value('fhir_client_connection_id');
        $service = app(FhirConformanceObservationService::class);
        $smart = [
            'token_endpoint' => config('integrations.epic_sandbox.token_url'),
            'grant_types_supported' => ['client_credentials'],
            'token_endpoint_auth_methods_supported' => ['private_key_jwt'],
            'capabilities' => ['client-confidential-asymmetric'],
        ];
        $statement = [
            'resourceType' => 'CapabilityStatement',
            'status' => 'active',
            'date' => '2026-07-15T12:00:00Z',
            'kind' => 'instance',
            'fhirVersion' => '4.0.1',
            'format' => ['json'],
            'rest' => [[
                'mode' => 'server',
                'resource' => array_fill(0, 501, ['type' => 'Patient']),
            ]],
        ];

        try {
            $service->capture($sourceId, $connectionId, $statement, $smart);
            $this->fail('A CapabilityStatement above the resource bound must be rejected.');
        } catch (IntegrationProtocolException $exception) {
            $this->assertSame('fhir_capability_limit_exceeded', $exception->errorCode);
        }

        $statement['rest'][0]['resource'] = [];
        $interactiveSmart = [
            ...$smart,
            'authorization_endpoint' => 'https://'.$this->epicHost().'/oauth2/authorize',
            'grant_types_supported' => ['authorization_code', 'client_credentials'],
            'capabilities' => ['client-confidential-asymmetric', 'launch-standalone'],
            'code_challenge_methods_supported' => ['plain'],
        ];
        try {
            $service->capture($sourceId, $connectionId, $statement, $interactiveSmart);
            $this->fail('Interactive SMART discovery without exclusive S256 PKCE must be rejected.');
        } catch (IntegrationProtocolException $exception) {
            $this->assertSame('smart_pkce_s256_required', $exception->errorCode);
        }

        unset($statement['status']);
        try {
            $service->capture($sourceId, $connectionId, $statement, $smart);
            $this->fail('Required CapabilityStatement metadata must not be silently defaulted.');
        } catch (IntegrationProtocolException $exception) {
            $this->assertSame('fhir_capability_status_missing', $exception->errorCode);
        }

        $this->assertSame(0, DB::table('integration.fhir_conformance_observations')->count());
    }

    /** @return array<string, \Illuminate\Http\Client\Response> */
    private function epicDiscoveryResponses(
        string $softwareName = 'Epic',
        array $resourceTypes = ['Encounter', 'Location'],
        ?string $tokenUrl = null,
        ?array $searchableResourceTypes = null,
        ?array $historyResourceTypes = null,
    ): array {
        $searchableResourceTypes ??= $resourceTypes;
        $historyResourceTypes ??= $searchableResourceTypes;

        return [
            config('integrations.epic_sandbox.smart_configuration_url') => Http::response([
                'token_endpoint' => $tokenUrl ?? config('integrations.epic_sandbox.token_url'),
                'grant_types_supported' => ['client_credentials'],
                'token_endpoint_auth_methods_supported' => ['private_key_jwt'],
                'token_endpoint_auth_signing_alg_values_supported' => ['RS384'],
                'scopes_supported' => array_map(
                    fn (string $resourceType): string => 'system/'.$resourceType.'.rs',
                    $resourceTypes,
                ),
                'capabilities' => ['client-confidential-asymmetric'],
            ]),
            config('integrations.epic_sandbox.base_url').'/metadata' => Http::response([
                'resourceType' => 'CapabilityStatement',
                'status' => 'active',
                'date' => '2026-07-15T12:00:00Z',
                'kind' => 'instance',
                'fhirVersion' => '4.0.1',
                'software' => ['name' => $softwareName, 'version' => 'sandbox'],
                'implementation' => ['url' => config('integrations.epic_sandbox.base_url')],
                'format' => ['json'],
                'implementationGuide' => ['http://hl7.org/fhir/us/core/ImplementationGuide/hl7.fhir.us.core'],
                'rest' => [[
                    'mode' => 'server',
                    'security' => ['service' => [['coding' => [[
                        'system' => 'http://terminology.hl7.org/CodeSystem/restful-security-service',
                        'code' => 'SMART-on-FHIR',
                    ]]]]],
                    'interaction' => [['code' => 'batch'], ['code' => 'transaction']],
                    'resource' => array_map(
                        fn (string $resourceType): array => [
                            'type' => $resourceType,
                            'profile' => 'http://hl7.org/fhir/StructureDefinition/'.$resourceType,
                            'interaction' => array_values(array_filter([
                                ['code' => 'read'],
                                in_array($resourceType, $searchableResourceTypes, true)
                                    ? ['code' => 'search-type']
                                    : null,
                                in_array($resourceType, $historyResourceTypes, true)
                                    ? ['code' => 'history-type']
                                    : null,
                            ])),
                            'versioning' => 'versioned',
                            'readHistory' => true,
                            'searchInclude' => [$resourceType.':subject'],
                            'searchParam' => [[
                                'name' => '_id',
                                'definition' => 'http://hl7.org/fhir/SearchParameter/Resource-id',
                                'type' => 'token',
                            ]],
                            'operation' => [[
                                'name' => 'meta',
                                'definition' => 'http://hl7.org/fhir/OperationDefinition/Resource-meta',
                            ]],
                        ],
                        $resourceTypes,
                    ),
                ]],
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

    private function readyEpicSource(): int
    {
        config([
            'integrations.network.allowed_hosts' => [$this->epicHost()],
            'integrations.network.require_dns_resolution' => false,
        ]);
        $source = app(OperationalIntegrationConfigurator::class)->configureEpicSandbox(
            clientId: 'registered-runtime-pressure-client',
            privateKeyRef: $this->privateKeyReference(),
            activate: true,
            facilityKey: $this->facilityKey,
        );
        $sourceId = (int) $source['sourceId'];
        Http::fake($this->epicDiscoveryResponses());
        app(IntegrationProtocolHealthService::class)->check($sourceId);

        return $sourceId;
    }

    private function fhirRun(int $sourceId, string $correlationId, string $resourceType = 'Encounter'): int
    {
        return (int) DB::table('raw.ingest_runs')->insertGetId([
            'run_uuid' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'connector_key' => 'fhir.r4.'.strtolower($resourceType),
            'run_type' => 'fhir_poll',
            'status' => 'queued',
            'started_at' => now(),
            'metadata' => json_encode(['correlation_id' => $correlationId], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'ingest_run_id');
    }

    private function assertQueryRejected(callable $mutation, string $messageFragment): void
    {
        try {
            DB::transaction($mutation);
            $this->fail("Expected database mutation to be rejected with {$messageFragment}.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($messageFragment, $exception->getMessage());
        }
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
