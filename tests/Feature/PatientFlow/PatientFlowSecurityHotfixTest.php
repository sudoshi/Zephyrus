<?php

namespace Tests\Feature\PatientFlow;

use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Integration\Source;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\Transport\TransportRequest;
use App\Models\Unit;
use App\Models\User;
use App\Observability\Exporters\InMemoryMetricExporter;
use App\Services\PatientFlow\FlowEventNormalizer;
use App\Services\PatientFlow\FlowEventRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientFlowSecurityHotfixTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_scope_filters_and_redacts_every_patient_read_surface(): void
    {
        [$unitA, $unitB] = $this->createTwoUnits();
        $eventA = $this->storeFlowEvent('PATIENT-A-RAW', 'MSG-A', 'UNITA', 'ROOM-A', 'BED-A');
        $eventB = $this->storeFlowEvent('PATIENT-B-RAW', 'MSG-B', 'UNITB', 'ROOM-B', 'BED-B');

        $user = User::factory()->create(['role' => 'charge_nurse']);
        $user->units()->attach($unitA->unit_id, ['role' => 'charge_nurse', 'is_primary' => true]);

        $eventsResponse = $this->actingAs($user)
            ->getJson('/api/patient-flow/events?persona=charge_nurse')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $events = $eventsResponse->json();
        $this->assertCount(1, $events);
        $this->assertSame($eventA['event_id'], $events[0]['event_id']);
        $this->assertStringStartsWith('ptok_', $events[0]['patient_id']);
        $this->assertSame($events[0]['patient_id'], $events[0]['patient_context_ref']);
        $this->assertStringStartsWith('etok_', $events[0]['encounter_id']);
        $this->assertSame((int) $unitA->unit_id, $events[0]['unit_id']);
        $this->assertArrayNotHasKey('raw_message_hash', $events[0]);

        $encodedEvents = $eventsResponse->getContent();
        foreach ([$eventA['patient_id'], $eventA['patient_display_id'], $eventA['encounter_id'], $eventB['patient_id'], $eventB['encounter_id']] as $internalIdentifier) {
            $this->assertStringNotContainsString($internalIdentifier, $encodedEvents);
        }

        $tracks = $this->actingAs($user)
            ->getJson('/api/patient-flow/tracks?persona=charge_nurse')
            ->assertOk()
            ->json();
        $this->assertCount(1, $tracks);
        $this->assertStringStartsWith('ptok_', (string) array_key_first($tracks));

        $state = $this->actingAs($user)
            ->getJson('/api/patient-flow/state?persona=charge_nurse')
            ->assertOk()
            ->assertJsonPath('activePatients', 1)
            ->json();
        $this->assertStringStartsWith('ptok_', $state['patients'][0]['patient_id']);
        $this->assertStringNotContainsString($eventA['patient_id'], json_encode($state, JSON_THROW_ON_ERROR));

        $this->actingAs($user)
            ->getJson('/api/patient-flow/events?persona=charge_nurse&scope=unit:'.$unitB->unit_id)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'flow_lens_forbidden');

        $this->actingAs($user)
            ->getJson('/api/patient-flow/events?persona=charge_nurse&patient='.$eventA['patient_id'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_patient_context_ref');
    }

    public function test_fhir_and_sse_share_the_unit_authorization_and_redaction_boundary(): void
    {
        [$unitA] = $this->createTwoUnits();
        $eventA = $this->storeFlowEvent('PATIENT-A-RAW', 'MSG-A', 'UNITA', 'ROOM-A', 'BED-A');
        $eventB = $this->storeFlowEvent('PATIENT-B-RAW', 'MSG-B', 'UNITB', 'ROOM-B', 'BED-B');
        $user = User::factory()->create(['role' => 'charge_nurse']);
        $user->units()->attach($unitA->unit_id, ['role' => 'charge_nurse', 'is_primary' => true]);

        $fhir = $this->actingAs($user)
            ->getJson('/api/patient-flow/fhir/bundle?persona=charge_nurse&event_id='.urlencode($eventA['event_id']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->json();

        $fhirJson = json_encode($fhir, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('ptok_', $fhirJson);
        $this->assertStringContainsString('etok_', $fhirJson);
        $this->assertStringNotContainsString($eventA['patient_id'], $fhirJson);
        $this->assertStringNotContainsString($eventA['encounter_id'], $fhirJson);

        $this->actingAs($user)
            ->getJson('/api/patient-flow/fhir/bundle?persona=charge_nurse&event_id='.urlencode($eventB['event_id']))
            ->assertNotFound()
            ->assertJsonPath('error', 'event_id not found');

        $stream = $this->actingAs($user)
            ->get('/api/patient-flow/stream/adt?persona=charge_nurse&replay=10&interval=0.05')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $streamed = $stream->streamedContent();
        $this->assertStringContainsString($eventA['event_id'], $streamed);
        $this->assertStringContainsString('ptok_', $streamed);
        $this->assertStringNotContainsString($eventA['patient_id'], $streamed);
        $this->assertStringNotContainsString($eventB['event_id'], $streamed);
        $this->assertStringNotContainsString($eventB['patient_id'], $streamed);
    }

    public function test_task_scope_is_limited_to_active_transport_work(): void
    {
        $this->createTwoUnits();
        $activeEvent = $this->storeFlowEvent('TASK-PATIENT-A', 'TASK-MSG-A', 'UNITA', 'ROOM-A', 'BED-A', 'A03');
        $terminalEvent = $this->storeFlowEvent('TASK-PATIENT-B', 'TASK-MSG-B', 'UNITB', 'ROOM-B', 'BED-B', 'A03');

        $activeRequest = $this->transportRequest($activeEvent['patient_id'], 'assigned');
        $this->transportRequest($terminalEvent['patient_id'], 'completed');
        $user = User::factory()->create(['role' => 'transport']);

        $events = $this->actingAs($user)
            ->getJson('/api/patient-flow/events?persona=transport')
            ->assertOk()
            ->json();
        $this->assertCount(1, $events);
        $this->assertSame($activeEvent['event_id'], $events[0]['event_id']);
        $this->assertStringStartsWith('ptok_', $events[0]['patient_context_ref']);

        $this->actingAs($user)
            ->getJson('/api/patient-flow/fhir/bundle?persona=transport&event_id='.urlencode($terminalEvent['event_id']))
            ->assertNotFound();

        $activeRequest->update(['status' => 'completed', 'completed_at' => now()]);
        $this->actingAs($user)
            ->getJson('/api/patient-flow/events?persona=transport')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_browser_sessions_cannot_reach_the_machine_ingress_route(): void
    {
        $raw = $this->rawAdt('MACHINE-PATIENT', 'MACHINE-MSG', 'UNITA', 'ROOM-A', 'BED-A');

        $this->postJson('/api/integrations/v1/patient-flow/hl7v2', ['raw_hl7' => $raw])
            ->assertUnauthorized();

        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)
            ->postJson('/api/integrations/v1/patient-flow/hl7v2', ['raw_hl7' => $raw])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'machine_token_required');

        $this->actingAs($user)
            ->postJson('/api/patient-flow/ingest/hl7v2', ['raw_hl7' => $raw])
            ->assertNotFound();
    }

    public function test_machine_ingress_requires_the_explicit_non_wildcard_ability(): void
    {
        $user = User::factory()->create(['role' => 'integration']);
        $raw = $this->rawAdt('MACHINE-PATIENT', 'MACHINE-MSG', 'UNITA', 'ROOM-A', 'BED-A');

        $wildcard = $user->createToken('human-admin', ['*'])->plainTextToken;
        $this->withToken($wildcard)
            ->postJson('/api/integrations/v1/patient-flow/hl7v2', ['raw_hl7' => $raw], [
                'X-Integration-Source' => 'ehr.hl7v2',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'machine_ability_required');

        $wrong = $user->createToken('integration-reader', ['integration:read'])->plainTextToken;
        $this->withToken($wrong)
            ->postJson('/api/integrations/v1/patient-flow/hl7v2', ['raw_hl7' => $raw], [
                'X-Integration-Source' => 'ehr.hl7v2',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'machine_ability_required');

    }

    public function test_machine_ingress_rejects_an_inactive_machine_identity(): void
    {
        $inactive = User::factory()->create(['role' => 'integration', 'is_active' => false]);
        $token = $inactive->createToken(
            'integration:patient-flow',
            ['integration:patient-flow:ingest'],
        )->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/integrations/v1/patient-flow/hl7v2', [
                'raw_hl7' => $this->rawAdt('MACHINE-PATIENT', 'MACHINE-MSG', 'UNITA', 'ROOM-A', 'BED-A'),
            ], [
                'X-Integration-Source' => 'ehr.hl7v2',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'machine_identity_inactive');
    }

    public function test_machine_ingress_writes_canonical_lineage_and_is_idempotent(): void
    {
        config()->set('observability.enabled', true);
        config()->set('observability.exporter', 'memory');
        $telemetry = app(InMemoryMetricExporter::class);
        $telemetry->flush();
        $this->createTwoUnits();
        $source = $this->integrationSource();
        $user = User::factory()->create(['role' => 'integration']);
        $token = $user->createToken('integration:patient-flow', ['integration:patient-flow:ingest'])->plainTextToken;
        $raw = $this->rawAdt('MACHINE-PATIENT', 'MACHINE-MSG', 'UNITA', 'ROOM-A', 'BED-A');
        $headers = [
            'X-Integration-Source' => $source->source_key,
            'Idempotency-Key' => 'delivery-123',
        ];

        $first = $this->withToken($token)
            ->postJson('/api/integrations/v1/patient-flow/hl7v2', ['raw_hl7' => $raw], $headers)
            ->assertAccepted()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('duplicate', false)
            ->assertJsonMissingPath('event')
            ->json();

        foreach (['run_id', 'message_id', 'canonical_event_id'] as $field) {
            $this->assertTrue(Str::isUuid($first[$field]), "{$field} must be opaque UUID");
        }
        $this->assertStringNotContainsString('MACHINE-PATIENT', json_encode($first, JSON_THROW_ON_ERROR));

        $message = DB::table('raw.inbound_messages')->where('message_uuid', $first['message_id'])->first();
        $canonical = DB::table('integration.canonical_events')->where('event_id', $first['canonical_event_id'])->first();
        $flowEvent = DB::table('flow_core.flow_events')->where('inbound_message_id', $message->inbound_message_id)->first();
        $this->assertNotNull($message);
        $this->assertNotNull($canonical);
        $this->assertNotNull($flowEvent);
        $this->assertNull($message->payload);
        $this->assertNull($message->normalized_payload);
        $this->assertNotNull($message->payload_object_id);
        $this->assertNotNull($message->normalized_payload_object_id);
        $this->assertSame('{}', $canonical->payload);
        $this->assertNotNull($canonical->payload_object_id);
        $this->assertSame((int) $source->source_id, (int) $flowEvent->source_id);
        $this->assertSame((int) $message->inbound_message_id, (int) $flowEvent->inbound_message_id);
        $this->assertSame((int) $canonical->canonical_event_id, (int) $flowEvent->canonical_event_id);
        $this->assertDatabaseHas('integration.provenance_records', [
            'source_id' => $source->source_id,
            'inbound_message_id' => $message->inbound_message_id,
            'canonical_event_id' => $canonical->canonical_event_id,
            'target_schema' => 'flow_core',
            'target_table' => 'flow_events',
            'target_pk' => $flowEvent->flow_event_id,
        ]);

        $retry = $this->withToken($token)
            ->postJson('/api/integrations/v1/patient-flow/hl7v2', ['raw_hl7' => $raw], $headers)
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->json();
        $this->assertSame($first['run_id'], $retry['run_id']);
        $this->assertSame($first['message_id'], $retry['message_id']);
        $this->assertSame($first['canonical_event_id'], $retry['canonical_event_id']);

        $this->assertSame(1, DB::table('raw.inbound_messages')->where('source_id', $source->source_id)->count());
        $this->assertStringNotContainsString('OTHER-PATIENT', DB::table('raw.dead_letters')->get()->toJson());
        $this->assertSame(1, DB::table('integration.canonical_events')->where('source_id', $source->source_id)->count());
        $this->assertSame(1, DB::table('flow_core.flow_events')->where('source_id', $source->source_id)->count());
        $this->assertSame(1, DB::table('integration.provenance_records')->where('source_id', $source->source_id)->count());
        $this->assertSame(2, DB::table('raw.ingest_runs')->where('source_id', $source->source_id)->count());

        $rootSpans = collect($telemetry->spans())
            ->filter(fn ($span) => $span->name === 'zephyrus.integration.hl7.receipt_to_projection');
        $canonicalSpans = collect($telemetry->spans())
            ->filter(fn ($span) => $span->name === 'zephyrus.integration.canonical.write');
        $this->assertCount(2, $rootSpans);
        $this->assertCount(1, $canonicalSpans);
        $projectedSpan = $rootSpans->first(fn ($span) => $span->attributes->toArray()['zephyrus.outcome'] === 'projected');
        $canonicalSpan = $canonicalSpans->first();
        $this->assertSame($first['canonical_event_id'], $projectedSpan->attributes->toArray()['zephyrus.event.uuid']);
        $this->assertSame(
            $projectedSpan->attributes->toArray()['zephyrus.correlation.uuid'],
            $canonicalSpan->attributes->toArray()['zephyrus.correlation.uuid'],
        );
        $this->assertStringNotContainsString('MACHINE-PATIENT', json_encode(
            array_map(fn ($span) => $span->toArray(), $telemetry->spans()),
            JSON_THROW_ON_ERROR,
        ));

        $this->withToken($token)
            ->postJson('/api/integrations/v1/patient-flow/hl7v2', [
                'raw_hl7' => str_replace('MACHINE-PATIENT', 'OTHER-PATIENT', $raw),
            ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_key_conflict');
        $this->assertSame(1, DB::table('raw.inbound_messages')->where('source_id', $source->source_id)->count());
    }

    public function test_machine_ingress_rejects_sources_that_are_not_active_hl7_and_phi_approved(): void
    {
        $source = $this->integrationSource([
            'source_key' => 'ehr.disabled',
            'active_status' => 'inactive',
            'phi_allowed' => false,
        ]);
        $user = User::factory()->create(['role' => 'integration']);
        $token = $user->createToken('integration:patient-flow', ['integration:patient-flow:ingest'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/integrations/v1/patient-flow/hl7v2', [
                'raw_hl7' => $this->rawAdt('MACHINE-PATIENT', 'MACHINE-MSG', 'UNITA', 'ROOM-A', 'BED-A'),
            ], [
                'X-Integration-Source' => $source->source_key,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'integration_source_forbidden');

        $this->assertSame(0, DB::table('raw.ingest_runs')->where('source_id', $source->source_id)->count());
    }

    public function test_machine_ingress_retains_rejected_raw_messages_without_projecting_them(): void
    {
        $source = $this->integrationSource();
        $user = User::factory()->create(['role' => 'integration']);
        $token = $user->createToken('integration:patient-flow', ['integration:patient-flow:ingest'])->plainTextToken;
        $raw = implode("\r", [
            'MSH|^~\\&|EHR|AMC|FLOW|AMC|20260710120000||ORU^R01|NOT-ADT|P|2.5.1',
            'PID|||REJECTED-PATIENT^^^AMC^MR||FLOW^REJECTED',
            '',
        ]);

        $response = $this->withToken($token)
            ->postJson('/api/integrations/v1/patient-flow/hl7v2', ['raw_hl7' => $raw], [
                'X-Integration-Source' => $source->source_key,
                'Idempotency-Key' => 'rejected-delivery-1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'unsupported_hl7_message');

        $this->assertStringNotContainsString('REJECTED-PATIENT', $response->getContent());
        $this->assertDatabaseHas('raw.inbound_messages', [
            'source_id' => $source->source_id,
            'idempotency_key' => 'rejected-delivery-1',
            'parse_status' => 'failed',
        ]);
        $this->assertDatabaseHas('raw.dead_letters', [
            'source_id' => $source->source_id,
            'reason_code' => 'unsupported_hl7_message',
            'status' => 'open',
        ]);
        $this->assertSame(0, DB::table('integration.canonical_events')->where('source_id', $source->source_id)->count());
        $this->assertSame(0, DB::table('flow_core.flow_events')->where('source_id', $source->source_id)->count());
    }

    /** @return array{0: Unit, 1: Unit} */
    private function createTwoUnits(): array
    {
        $spaceA = $this->facilitySpace('BED-A', 'UNITA', 1);
        $spaceB = $this->facilitySpace('BED-B', 'UNITB', 2);

        $unitA = Unit::create([
            'name' => 'Unit A',
            'abbreviation' => 'UNITA',
            'type' => 'med_surg',
            'staffed_bed_count' => 1,
            'facility_space_id' => $spaceA,
        ]);
        $unitB = Unit::create([
            'name' => 'Unit B',
            'abbreviation' => 'UNITB',
            'type' => 'med_surg',
            'staffed_bed_count' => 1,
            'facility_space_id' => $spaceB,
        ]);

        return [$unitA, $unitB];
    }

    private function facilitySpace(string $locationCode, string $unitCode, int $floor): int
    {
        return (int) DB::table('hosp_space.facility_spaces')->insertGetId([
            'space_code' => 'ZEPHYRUS-500:'.$locationCode,
            'space_name' => $locationCode,
            'space_category' => 'bed',
            'floor_label' => 'Floor '.$floor,
            'floor_number' => $floor,
            'status' => 'active',
            'geometry' => json_encode(['position_ft' => ['x' => $floor * 10, 'z' => $floor * 10]], JSON_THROW_ON_ERROR),
            'attributes' => json_encode(['unit_code' => $unitCode], JSON_THROW_ON_ERROR),
            'source_system' => 'security-test',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'facility_space_id');
    }

    /** @return array<string, mixed> */
    private function storeFlowEvent(
        string $patient,
        string $messageId,
        string $unit,
        string $room,
        string $bed,
        string $trigger = 'A01',
    ): array {
        $raw = $this->rawAdt($patient, $messageId, $unit, $room, $bed, $trigger);
        $event = app(FlowEventNormalizer::class)->normalize($raw);
        app(FlowEventRepository::class)->upsertNormalizedEvent($event);

        return $event;
    }

    private function rawAdt(
        string $patient,
        string $messageId,
        string $unit,
        string $room,
        string $bed,
        string $trigger = 'A01',
    ): string {
        return implode("\r", [
            "MSH|^~\\&|EHR|AMC|FLOW|AMC|20260710120000||ADT^{$trigger}|{$messageId}|P|2.5.1",
            "EVN|{$trigger}|20260710120000",
            "PID|||{$patient}^^^AMC^MR||FLOW^{$patient}",
            "PV1||I|{$unit}^{$room}^{$bed}^ZEPHYRUS||||99001^ATTENDING^SYNTHETIC|||hospital_medicine|||||||||VIS-{$messageId}^^^AMC^VN",
            '',
        ]);
    }

    private function transportRequest(string $patientRef, string $status): TransportRequest
    {
        return TransportRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'request_type' => 'inpatient',
            'priority' => 'routine',
            'status' => $status,
            'patient_ref' => $patientRef,
            'origin' => 'Unit A',
            'destination' => 'Unit B',
            'transport_mode' => 'wheelchair',
            'requested_at' => now(),
            'completed_at' => $status === 'completed' ? now() : null,
            'is_deleted' => false,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function integrationSource(array $overrides = []): Source
    {
        $organization = Organization::query()->firstOrCreate(
            ['organization_key' => 'PATIENT_FLOW_SECURITY_IDN'],
            [
                'name' => 'Patient Flow Security Test IDN',
                'kind' => 'idn',
            ],
        );
        $facility = Facility::query()->firstOrCreate(
            ['facility_key' => 'ZEPHYRUS-500'],
            [
                'organization_id' => $organization->organization_id,
                'facility_name' => 'Patient Flow Security Test Facility',
                'idn_role' => 'community_hospital',
                'review_status' => 'client_verified',
                'is_active' => true,
            ],
        );

        return app(SourceRegistryService::class)->ensureSource(array_merge([
            'source_key' => 'ehr.hl7v2',
            'organization_id' => $organization->organization_id,
            'facility_id' => $facility->facility_id,
            'tenant_key' => $organization->organization_key,
            'facility_key' => $facility->facility_key,
            'source_name' => 'Security Test HL7 v2',
            'vendor' => 'Test EHR',
            'system_class' => 'ehr',
            'environment' => 'test',
            'interface_type' => 'hl7v2',
            'active_status' => 'active',
            'contract_status' => 'executed',
            'baa_status' => 'executed',
            'phi_allowed' => true,
            'go_live_status' => 'ready',
            'metadata' => [],
        ], $overrides));
    }
}
