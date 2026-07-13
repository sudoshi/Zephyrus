<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Healthcare\DTO\ReplayRequest;
use App\Integrations\Healthcare\DTO\WebhookEnvelope;
use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Integrations\Healthcare\Synthetic\SyntheticHealthcareConnector;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use Database\Seeders\IntegrationConnectorTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyntheticHealthcareConnectorTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    private int $facilityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('deployment:seed-registry')->assertExitCode(0);
        $organization = Organization::create([
            'organization_key' => 'SYNTHETIC_TEST_IDN',
            'name' => 'Synthetic Test IDN',
            'kind' => 'idn',
        ]);
        $facility = Facility::create([
            'organization_id' => $organization->organization_id,
            'facility_key' => 'SYNTHETIC_TEST_FACILITY',
            'facility_name' => 'Synthetic Test Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
        $this->organizationId = (int) $organization->organization_id;
        $this->facilityId = (int) $facility->facility_id;
        config()->set('integrations.synthetic.facility_key', $facility->facility_key);
    }

    public function test_integration_foundation_tables_exist(): void
    {
        foreach ([
            'integration.sources',
            'integration.source_capabilities',
            'integration.source_endpoints',
            'integration.source_credentials',
            'raw.ingest_runs',
            'raw.inbound_messages',
            'raw.dead_letters',
            'integration.connector_watermarks',
            'integration.canonical_events',
            'integration.provenance_records',
            'integration.event_projection_offsets',
            'integration.event_projection_errors',
            'integration.event_replay_jobs',
            'fhir.resource_versions',
            'fhir.resource_links',
            'integration.identity_links',
            'integration.patient_merge_events',
            'integration.terminology_maps',
            'integration.interface_engines',
            'integration.fhir_client_connections',
            'integration.smart_backend_credentials',
            'integration.connector_playbooks',
            'integration.coexistence_adapters',
            'ops.writeback_drafts',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} should exist");
        }
    }

    public function test_synthetic_connector_writes_raw_canonical_and_projected_records(): void
    {
        [$unitId, $bedId] = $this->seedCapacityFixture();

        $run = app(SyntheticHealthcareConnector::class)->handleWebhook(new WebhookEnvelope([
            'messages' => [[
                'message_type' => 'synthetic.EncounterStarted',
                'event_type' => 'EncounterStarted',
                'external_id' => 'msg-enc-start-1',
                'patient_ref' => 'synthetic-patient-1',
                'unit_id' => $unitId,
                'bed_id' => $bedId,
                'acuity_tier' => 3,
                'occurred_at' => now()->toISOString(),
            ]],
        ]));

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->messages_received);
        $this->assertSame(1, $run->messages_succeeded);
        $this->assertDatabaseHas('integration.sources', [
            'source_key' => 'synthetic.command_center',
            'active_status' => 'active',
        ]);
        $this->assertDatabaseHas('raw.inbound_messages', [
            'external_id' => 'msg-enc-start-1',
            'parse_status' => 'projected',
        ]);
        $this->assertDatabaseHas('integration.canonical_events', [
            'event_type' => 'EncounterStarted',
            'entity_ref' => 'synthetic-patient-1',
            'projection_status' => 'projected',
        ]);
        $this->assertDatabaseHas('prod.operational_events', [
            'type' => 'EncounterStarted',
            'encounter_ref' => 'synthetic-patient-1',
        ]);
        $this->assertDatabaseHas('prod.encounters', [
            'patient_ref' => 'synthetic-patient-1',
            'unit_id' => $unitId,
            'bed_id' => $bedId,
            'status' => 'active',
        ]);
        $this->assertSame('occupied', DB::table('prod.beds')->where('bed_id', $bedId)->value('status'));
        $this->assertDatabaseHas('integration.provenance_records', [
            'target_schema' => 'prod',
            'target_table' => 'operational_events',
        ]);
        $this->assertDatabaseHas('integration.connector_watermarks', [
            'connector_key' => 'synthetic.healthcare',
            'scope_type' => 'webhook',
        ]);
        $message = DB::table('raw.inbound_messages')->where('external_id', 'msg-enc-start-1')->firstOrFail();
        $canonical = DB::table('integration.canonical_events')->where('entity_ref', 'synthetic-patient-1')->firstOrFail();
        $this->assertNull($message->payload);
        $this->assertNull($message->normalized_payload);
        $this->assertNotNull($message->payload_object_id);
        $this->assertNotNull($message->normalized_payload_object_id);
        $this->assertSame('{}', $canonical->payload);
        $this->assertNotNull($canonical->payload_object_id);
    }

    public function test_synthetic_connector_is_idempotent_for_duplicate_external_message(): void
    {
        [$unitId, $bedId] = $this->seedCapacityFixture();
        $connector = app(SyntheticHealthcareConnector::class);
        $payload = [
            'message_type' => 'synthetic.EncounterStarted',
            'event_type' => 'EncounterStarted',
            'external_id' => 'msg-duplicate-1',
            'patient_ref' => 'synthetic-patient-dup',
            'unit_id' => $unitId,
            'bed_id' => $bedId,
            'acuity_tier' => 2,
            'occurred_at' => now()->toISOString(),
        ];

        $connector->handleWebhook(new WebhookEnvelope(['messages' => [$payload]]));
        $second = $connector->handleWebhook(new WebhookEnvelope(['messages' => [$payload]]));

        $this->assertSame('completed', $second->status);
        $this->assertSame(1, $second->messages_skipped);
        $this->assertSame(1, DB::table('raw.inbound_messages')->where('external_id', 'msg-duplicate-1')->count());
        $this->assertSame(1, DB::table('integration.canonical_events')->where('entity_ref', 'synthetic-patient-dup')->count());
        $this->assertSame(1, DB::table('prod.operational_events')->where('encounter_ref', 'synthetic-patient-dup')->count());
    }

    public function test_synthetic_connector_dead_letters_invalid_messages(): void
    {
        $run = app(SyntheticHealthcareConnector::class)->handleWebhook(new WebhookEnvelope([
            'messages' => [[
                'message_type' => 'synthetic.EncounterStarted',
                'event_type' => 'EncounterStarted',
                'external_id' => 'msg-invalid-1',
                'patient_ref' => 'synthetic-patient-invalid',
                'acuity_tier' => 2,
                'occurred_at' => now()->toISOString(),
            ]],
        ]));

        $this->assertSame('failed', $run->status);
        $this->assertSame(1, $run->messages_failed);
        $this->assertDatabaseHas('raw.inbound_messages', [
            'external_id' => 'msg-invalid-1',
            'parse_status' => 'failed',
        ]);
        $this->assertDatabaseHas('raw.dead_letters', [
            'failure_stage' => 'mapping',
            'reason_code' => 'message_mapping_failed',
            'status' => 'open',
        ]);
        $this->assertDatabaseMissing('prod.operational_events', [
            'encounter_ref' => 'synthetic-patient-invalid',
        ]);
        $this->assertStringNotContainsString(
            'synthetic-patient-invalid',
            DB::table('raw.dead_letters')->get()->toJson(),
        );
    }

    public function test_synthetic_replay_projects_canonical_events_without_duplicates(): void
    {
        [$unitId, $bedId] = $this->seedCapacityFixture();
        $connector = app(SyntheticHealthcareConnector::class);

        $connector->handleWebhook(new WebhookEnvelope([
            'messages' => [[
                'message_type' => 'synthetic.EncounterStarted',
                'event_type' => 'EncounterStarted',
                'external_id' => 'msg-replay-1',
                'patient_ref' => 'synthetic-patient-replay',
                'unit_id' => $unitId,
                'bed_id' => $bedId,
                'acuity_tier' => 4,
                'occurred_at' => now()->toISOString(),
            ]],
        ]));

        $canonicalEventId = (int) DB::table('integration.canonical_events')
            ->where('entity_ref', 'synthetic-patient-replay')
            ->value('canonical_event_id');

        $run = $connector->replay(new ReplayRequest(canonicalEventIds: [$canonicalEventId], force: true));

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->messages_succeeded);
        $this->assertSame(1, DB::table('prod.operational_events')->where('encounter_ref', 'synthetic-patient-replay')->count());
    }

    public function test_integration_health_endpoint_reports_status(): void
    {
        $user = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        app(SyntheticHealthcareConnector::class)->healthCheck();
        app(SyntheticHealthcareConnector::class)->poll(new \App\Integrations\Healthcare\DTO\PollRequest(messages: []));

        $this->actingAs($user)->getJson('/api/admin/integrations/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonPath('data.counts.sources', 1)
            ->assertJsonPath('data.sources.0.sourceKey', 'synthetic.command_center')
            ->assertJsonPath('data.sources.0.healthStatus', 'healthy');
    }

    public function test_enterprise_connector_summary_reads_explicit_templates(): void
    {
        $this->seed(IntegrationConnectorTemplateSeeder::class);
        $user = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);

        $response = $this->actingAs($user)
            ->getJson('/api/admin/integrations/enterprise')
            ->assertOk()
            ->assertJsonPath('data.counts.connectorPlaybooks', 3)
            ->assertJsonPath('data.counts.coexistenceAdapters', 3)
            ->assertJsonPath('data.counts.interfaceEngines', 1)
            ->assertJsonPath('data.playbooks.0.status', 'template');

        $this->assertContains('epic', array_column($response->json('data.playbooks'), 'vendorKey'));
        $this->assertContains('teletracking_coexistence', array_column($response->json('data.coexistenceAdapters'), 'adapterKey'));
        $this->assertDatabaseHas('integration.interface_engines', [
            'engine_key' => 'interface-engine-boundary',
            'engine_type' => 'hl7v2_mllp_gateway',
            'status' => 'template',
        ]);
    }

    public function test_fhir_discovery_queues_a_real_check_and_ignores_fabricated_capabilities(): void
    {
        $user = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $source = app(SourceRegistryService::class)->ensureSource([
            'source_key' => 'epic.fhir.sandbox',
            ...$this->sourceScope(),
            'vendor' => 'Epic',
            'interface_type' => 'fhir_r4',
            'active_status' => 'testing',
        ]);
        Queue::fake();

        $this->selectScope($user, (int) $source->source_id)
            ->postJson('/api/admin/integrations/enterprise/fhir/capability-discovery', [
                'source_id' => $source->source_id,
                'fhir_version' => '4.0.1',
                'client_id' => 'zephyrus-system-client',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued');

        $this->assertSame(0, DB::table('integration.fhir_client_connections')->count());
        $this->assertSame(0, DB::table('integration.smart_backend_credentials')->count());
        $this->assertSame(0, DB::table('integration.source_capabilities')->count());
        $this->assertSame(1, DB::table('integration.sources')->count());
        $this->assertDatabaseHas('raw.ingest_runs', ['source_id' => $source->source_id, 'run_type' => 'protocol_health', 'status' => 'queued']);
    }

    public function test_writeback_draft_creates_pending_ops_approval_gate(): void
    {
        $user = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $source = app(SourceRegistryService::class)->ensureSource([
            'source_key' => 'epic.fhir.sandbox',
            'source_name' => 'Epic FHIR Sandbox',
            'vendor' => 'Epic',
            'interface_type' => 'fhir_r4',
            ...$this->sourceScope(),
        ]);

        $response = $this->selectScope($user, (int) $source->source_id)
            ->postJson('/api/admin/integrations/enterprise/writeback-drafts', [
                'source_id' => $source->source_id,
                'vendor' => 'Epic',
                'target_system' => 'epic',
                'resource_type' => 'Task',
                'draft_type' => 'fhir_task',
                'resource_payload' => [
                    'resourceType' => 'Task',
                    'status' => 'requested',
                    'intent' => 'order',
                    'description' => 'RECOGNIZABLE-WRITEBACK-PATIENT-4411',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.resourceType', 'Task')
            ->assertJsonPath('data.targetSystem', 'epic')
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.approvalStatus', 'pending');

        $this->assertDatabaseHas('ops.writeback_drafts', [
            'writeback_draft_id' => $response->json('data.writebackDraftId'),
            'resource_type' => 'Task',
            'target_system' => 'epic',
            'status' => 'pending_approval',
        ]);
        $this->assertDatabaseHas('ops.actions', [
            'action_id' => $response->json('data.actionId'),
            'action_type' => 'approve_writeback_draft',
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('ops.approvals', [
            'approval_id' => $response->json('data.approvalId'),
            'status' => 'pending',
        ]);
        $draft = DB::table('ops.writeback_drafts')
            ->where('writeback_draft_id', $response->json('data.writebackDraftId'))
            ->firstOrFail();
        $this->assertSame('{}', $draft->resource_payload);
        $this->assertNotNull($draft->payload_object_id);
        $this->assertSame(
            'RECOGNIZABLE-WRITEBACK-PATIENT-4411',
            app(\App\Integrations\Healthcare\Services\EnterpriseConnectorControlService::class)
                ->writebackPayload((int) $draft->writeback_draft_id, (int) $source->source_id)['description'],
        );
        $this->assertStringNotContainsString(
            'RECOGNIZABLE-WRITEBACK-PATIENT-4411',
            json_encode($draft, JSON_THROW_ON_ERROR),
        );
    }

    private function seedCapacityFixture(): array
    {
        $unitId = (int) DB::table('prod.units')->insertGetId([
            'name' => 'Synthetic Unit',
            'abbreviation' => 'SYN',
            'type' => 'med_surg',
            'staffed_bed_count' => 12,
            'ratio_floor' => 4,
            'access_standard_minutes' => 120,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'unit_id');

        $bedId = (int) DB::table('prod.beds')->insertGetId([
            'unit_id' => $unitId,
            'label' => 'SYN-01',
            'status' => 'available',
            'bed_type' => 'standard',
            'isolation_capable' => false,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'bed_id');

        return [$unitId, $bedId];
    }

    /** @return array<string, int|string> */
    private function sourceScope(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'tenant_key' => 'SYNTHETIC_TEST_IDN',
            'facility_key' => 'SYNTHETIC_TEST_FACILITY',
        ];
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
