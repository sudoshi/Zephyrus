<?php

namespace Tests\Feature\PatientFlow;

use App\Models\User;
use App\Services\PatientFlow\FlowEventNormalizer;
use App\Services\PatientFlow\FlowEventRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientFlowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_flow_api_returns_navigator_contract(): void
    {
        $facilityCode = 'ZEPHYRUS-500';

        $this->assertSame(0, Artisan::call('facility:import-catalog', [
            'path' => base_path('tests/Fixtures/facility/model_catalog_fixture.json'),
            '--facility-code' => $facilityCode,
            '--facility-name' => 'Navigator Test Facility',
            '--source-name' => 'navigator-fixture-catalog',
            '--map-operational' => true,
        ]));

        $raw = implode("\r", [
            'MSH|^~\\&|EHR|AMC|FLOW|AMC|20260625010100||ADT^A01|MSG1|P|2.5.1',
            'EVN|A01|20260625010100',
            'PID|||SYN000001^^^AMC^MR||FLOW^SYN000001',
            'PV1||I|TICU^TICU-R001^TICU-B001^ZEPHYRUS||||99001^ATTENDING^SYNTHETIC|||critical_care|||||||||VIS000001^^^AMC^VN',
            '',
        ]);

        $event = app(FlowEventNormalizer::class)->normalize($raw);
        app(FlowEventRepository::class)->upsertNormalizedEvent($event, null, null, null, $facilityCode);
        $spaceId = (int) DB::table('hosp_space.facility_spaces')
            ->where('space_code', "{$facilityCode}:TICU-B001")
            ->value('facility_space_id');
        DB::table('ops.nodes')->insert([
            'node_uuid' => (string) Str::uuid(),
            'node_type' => 'facility_space',
            'canonical_key' => "facility_space:{$spaceId}",
            'display_name' => 'TICU bed graph node',
            'source_schema' => 'hosp_space',
            'source_table' => 'facility_spaces',
            'source_pk' => (string) $spaceId,
            'status' => 'active',
            'current_state' => json_encode(['overlay' => 'capacity']),
            'metadata' => json_encode([]),
            'last_observed_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Patient-level endpoints are persona-lensed (FLOW-WINDOW-PLAN §6.4):
        // this user's `role` grants the bed_manager lens (patient_dots: full).
        $user = User::factory()->create(['role' => 'bed_manager']);

        $this->actingAs($user)
            ->getJson('/api/patient-flow/summary')
            ->assertOk()
            ->assertJsonPath('normalized_events', 1)
            ->assertJsonPath('patients', 1)
            ->assertJsonPath('ambient_signals', 4)
            ->assertJsonPath('ambient_confidence_level', 'medium')
            ->assertJsonPath('facility_code', 'ZEPHYRUS-500');

        $this->actingAs($user)
            ->getJson('/api/patient-flow/locations')
            ->assertOk()
            ->assertJsonStructure(['TICU-B001' => ['facility_space_id', 'position_m', 'name', 'ops_graph_nodes']])
            ->assertJsonPath('TICU-B001.ops_graph_nodes.0.canonicalKey', "facility_space:{$spaceId}");

        $this->actingAs($user)
            ->getJson('/api/patient-flow/events')
            ->assertOk()
            ->assertJsonPath('0.event_type', 'admit')
            ->assertJsonPath('0.to_location', 'TICU-B001')
            ->assertJsonPath('0.location_name', 'Test Surgical Trauma ICU bed 001');

        $this->actingAs($user)
            ->getJson('/api/patient-flow/state')
            ->assertOk()
            ->assertJsonPath('activePatients', 1)
            ->assertJsonPath('patients.0.location', 'TICU-B001');

        $occupancy = $this->actingAs($user)
            ->getJson('/api/patient-flow/occupancy?asOf=2026-06-25T02:00:00Z')
            ->assertOk()
            ->assertJsonPath('summary.active', 1)
            ->assertJsonPath('summary.service_lines.0.occupied', 1)
            ->assertJsonPath('occupancy.0.location', 'TICU-B001')
            ->assertJsonPath('occupancy.0.came_from', null)
            ->assertJsonStructure([
                'occupancy' => [
                    [
                        'location',
                        'location_name',
                        'service_line',
                        'stay_minutes',
                        'arrived_at',
                        'primary_status',
                        'timers' => [['kind', 'label', 'status', 'source']],
                    ],
                ],
                'summary' => ['persona', 'service_lines', 'timer_status_counts'],
            ])
            ->json('occupancy.0');

        $this->assertArrayHasKey('patient_id', $occupancy);

        $redacted = $this->actingAs(User::factory()->create(['role' => 'executive']))
            ->getJson('/api/patient-flow/occupancy?asOf=2026-06-25T02:00:00Z')
            ->assertOk()
            ->assertJsonPath('lens.patient_dots', 'none')
            ->json('occupancy.0');

        $this->assertArrayNotHasKey('patient_id', $redacted);
        $this->assertArrayNotHasKey('patient_display_id', $redacted);

        $demo = $this->actingAs($user)
            ->getJson('/api/patient-flow/occupancy?asOf=2026-06-25T02:00:00Z&demo=barriers&include=eddy_context')
            ->assertOk()
            ->assertJsonPath('demo_scenario.key', 'rtdc_barriers')
            ->assertJsonPath('eddy_context.surface', 'patient_flow_4d')
            ->assertJsonPath('eddy_context.role.id', 'bed_manager')
            ->assertJsonPath('eddy_context.redaction.patient_identifiers_included', true)
            ->assertJsonStructure([
                'occupancy' => [
                    [
                        'barrier_reasons',
                        'barrier_codes',
                        'barrier_labels',
                        'owner_roles',
                        'delay_impacts',
                        'rtdc_metrics',
                        'eddy_summaries',
                        'barrier_owner_map',
                        'timers' => [['reason', 'barrier_code', 'barrier_label', 'owner_role', 'blocks', 'impact', 'rtdc_metrics', 'eddy_summary']],
                    ],
                ],
                'summary' => [
                    'top_barriers' => [['barrier_code', 'label', 'reason', 'owner_role', 'count', 'service_lines', 'rtdc_metrics', 'eddy_summary']],
                ],
                'eddy_context' => [
                    'current_metrics',
                    'top_barriers' => [['barrier_code', 'label', 'owner_role', 'rtdc_metrics', 'eddy_summary', 'recommended_focus']],
                    'barrier_owner_map',
                    'recommended_focus_areas',
                    'source_lineage' => ['timer_sources', 'demo_scenario', 'generated_from'],
                    'action_allowlist',
                ],
            ])
            ->json();

        $this->assertNotEmpty($demo['summary']['top_barriers']);
        $this->assertNotEmpty(collect($demo['occupancy'])->first(fn (array $item): bool => ! empty($item['barrier_reasons'])));
        $this->assertGreaterThanOrEqual(
            5,
            collect($demo['summary']['top_barriers'])->pluck('barrier_code')->filter()->unique()->count(),
        );
        $this->assertNotEmpty(collect($demo['occupancy'])->first(fn (array $item): bool => ! empty($item['rtdc_metrics']) && ! empty($item['eddy_summaries'])));
        $this->assertContains('draft_huddle_summary', $demo['eddy_context']['action_allowlist']);
        $this->assertNotEmpty($demo['eddy_context']['recommended_focus_areas']);

        $scenarioRegistry = $this->actingAs($user)
            ->getJson('/api/patient-flow/demo-scenarios')
            ->assertOk()
            ->assertJsonPath('meta.source_mode', 'synthetic_demo')
            ->json('data');

        $scenarioKeys = array_column($scenarioRegistry, 'key');
        foreach ([
            'ed_boarding_surge',
            'evs_backlog',
            'or_pacu_hold',
            'weekend_staffing_gap',
            'post_acute_discharge_gridlock',
            'critical_care_outflow',
        ] as $requiredScenario) {
            $this->assertContains($requiredScenario, $scenarioKeys);
        }

        $this->actingAs($user)
            ->getJson('/api/patient-flow/occupancy?asOf=2026-06-25T02:00:00Z&demo=critical_care_outflow&include=eddy_context')
            ->assertOk()
            ->assertJsonPath('demo_scenario.key', 'critical_care_outflow')
            ->assertJsonPath('demo_scenario.enabled', true)
            ->assertJsonPath('eddy_context.source_lineage.demo_scenario.key', 'critical_care_outflow');

        DB::table('flow_core.occupancy_snapshots')->insert([
            'snapshot_at' => '2026-06-25 02:00:00+00',
            'facility_space_id' => $spaceId,
            'active_patient_count' => 1,
            'service_line_counts' => json_encode(['critical_care' => 1]),
            'acuity_counts' => json_encode(['icu' => 1]),
            'occupancy_details' => json_encode([[
                'location' => 'TICU-B001',
                'patient_id' => 'SYN000001',
                'patient_display_id' => 'Demo SYN000001',
                'encounter_id' => 'VIS000001',
                'mrn' => 'MRN-SYN000001',
                'primary_status' => 'watch',
                'nested' => [
                    'patient_name' => 'Demo Patient',
                    'encounter_ref' => 'ENC-SYN000001',
                    'safe_context' => 'retained',
                ],
            ]]),
            'timer_status_counts' => json_encode(['ok' => 0, 'watch' => 1, 'delayed' => 0]),
            'service_line_timer_counts' => json_encode(['critical_care' => ['watch' => 1]]),
            'persona_timer_counts' => json_encode(['bed_manager' => 1]),
            'active_blocker_counts' => json_encode(['stepdown_staffing_variance' => 1]),
            'projection_window' => json_encode(['from' => '2026-06-25T02:00:00Z', 'to' => '2026-06-26T02:00:00Z']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $history = $this->actingAs($user)
            ->getJson('/api/patient-flow/occupancy/history?from=2026-06-25T00:00:00Z&to=2026-06-25T03:00:00Z&demo=critical_care_outflow')
            ->assertOk()
            ->assertJsonPath('summary.snapshots', 1)
            ->assertJsonPath('history.0.facility_space.space_code', "{$facilityCode}:TICU-B001")
            ->assertJsonPath('history.0.lineage.source_table', 'flow_core.occupancy_snapshots')
            ->json('history.0.occupancy_details.0');

        $this->assertStringStartsWith('ptok_', $history['patient_context_ref'] ?? '');
        $this->assertArrayNotHasKey('patient_id', $history);
        $this->assertArrayNotHasKey('patient_display_id', $history);
        $this->assertArrayNotHasKey('encounter_id', $history);
        $this->assertArrayNotHasKey('mrn', $history);
        $this->assertSame(['safe_context' => 'retained'], $history['nested'] ?? null);

        $executiveHistory = $this->actingAs(User::factory()->create(['role' => 'executive']))
            ->getJson('/api/patient-flow/occupancy/history?from=2026-06-25T00:00:00Z&to=2026-06-25T03:00:00Z&demo=critical_care_outflow')
            ->assertOk()
            ->assertJsonPath('lens.patient_dots', 'none')
            ->assertJsonPath('summary.redacted', true)
            ->json('history.0.occupancy_details');

        $this->assertSame([], $executiveHistory);

        $this->actingAs($user)
            ->getJson('/api/patient-flow/occupancy/history?from=not-a-date&to=2026-06-25T03:00:00Z')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_occupancy_history_window');

        $executiveContext = $this->actingAs(User::factory()->create(['role' => 'executive']))
            ->getJson('/api/patient-flow/occupancy?asOf=2026-06-25T02:00:00Z&demo=barriers&include=eddy_context')
            ->assertOk()
            ->assertJsonPath('eddy_context.role.id', 'executive')
            ->assertJsonPath('eddy_context.redaction.patient_identifiers_included', false)
            ->assertJsonPath('eddy_context.redaction.aggregate_only', true)
            ->json('eddy_context');

        $encodedExecutiveContext = json_encode($executiveContext, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('"patient_id"', $encodedExecutiveContext);
        $this->assertStringNotContainsString('"patient_display_id"', $encodedExecutiveContext);
        $this->assertStringNotContainsString('"encounter_id"', $encodedExecutiveContext);

        $ambient = $this->actingAs($user)
            ->getJson('/api/patient-flow/ambient')
            ->assertOk()
            ->assertJsonPath('summary.adapterCount', 4)
            ->assertJsonPath('summary.eventCount', 4)
            ->assertJsonPath('adapters.0.enabled', true)
            ->json();

        $this->assertContains('fixture_rtls', array_column($ambient['adapters'], 'key'));
        $this->assertContains('fixture_nurse_call', array_column($ambient['adapters'], 'key'));
        $this->assertContains('zone_presence', array_column($ambient['events'], 'signalType'));
        $this->assertGreaterThanOrEqual(0.70, $ambient['summary']['averageConfidence']);

        $this->assertDatabaseHas('flow_realtime.ambient_signal_adapters', [
            'adapter_key' => 'fixture_rtls',
            'source_type' => 'rtls',
        ]);
        $this->assertDatabaseHas('flow_realtime.ambient_signal_events', [
            'signal_type' => 'zone_presence',
            'confidence_level' => 'high',
        ]);

        $this->actingAs($user)
            ->getJson('/api/patient-flow/fhir/bundle?event_id='.$event['event_id'])
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle')
            ->assertJsonPath('entry.0.resource.resourceType', 'Encounter');
    }

    public function test_patient_flow_api_requires_authentication(): void
    {
        $this->getJson('/api/patient-flow/summary')->assertUnauthorized();
    }

    public function test_event_queries_return_the_latest_bounded_replay_in_chronological_order(): void
    {
        $times = [
            '2026-07-05T01:00:00Z',
            '2026-07-05T02:00:00Z',
            '2026-07-05T03:00:00Z',
            '2026-07-05T04:00:00Z',
        ];

        foreach ($times as $index => $time) {
            $this->insertFlowEventAt($time, $index + 1);
        }

        $user = User::factory()->create(['role' => 'bed_manager']);
        $latest = $this->actingAs($user)
            ->getJson('/api/patient-flow/events?limit=2')
            ->assertOk()
            ->json();

        $this->assertCount(2, $latest);
        $this->assertSame(
            [
                CarbonImmutable::parse($times[2])->toJSON(),
                CarbonImmutable::parse($times[3])->toJSON(),
            ],
            array_column($latest, 'occurred_at'),
        );

        $bounded = $this->actingAs($user)
            ->getJson('/api/patient-flow/events?'.http_build_query([
                'from' => '2026-07-05T01:30:00Z',
                'to' => '2026-07-05T03:30:00Z',
                'limit' => 20,
            ]))
            ->assertOk()
            ->json();

        $this->assertSame(
            [
                CarbonImmutable::parse($times[1])->toJSON(),
                CarbonImmutable::parse($times[2])->toJSON(),
            ],
            array_column($bounded, 'occurred_at'),
        );
    }

    public function test_summary_exposes_stale_source_and_actual_event_extent(): void
    {
        DB::table('flow_core.flow_events')->delete();
        $first = '2026-07-05T01:00:00Z';
        $last = '2026-07-05T04:00:00Z';
        $this->insertFlowEventAt($first, 1);
        $this->insertFlowEventAt($last, 2);
        CarbonImmutable::setTestNow('2026-07-09T12:00:00Z');

        try {
            $this->actingAs(User::factory()->create(['role' => 'bed_manager']))
                ->getJson('/api/patient-flow/summary')
                ->assertOk()
                ->assertJsonPath('source.mode', 'live')
                ->assertJsonPath('source.freshness', 'stale')
                ->assertJsonPath('source.last_event_at', CarbonImmutable::parse($last)->toJSON())
                ->assertJsonPath('source.stale_after_seconds', 900)
                ->assertJsonPath('data_extent.first_event_at', CarbonImmutable::parse($first)->toJSON())
                ->assertJsonPath('data_extent.last_event_at', CarbonImmutable::parse($last)->toJSON())
                ->assertJsonPath('data_extent.event_count', 2)
                ->assertJsonPath('suggested_initial_time', CarbonImmutable::parse($last)->toJSON())
                ->assertJsonPath('live_events', 0);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_patient_level_flow_endpoints_require_a_patient_capable_lens(): void
    {
        // No Hummingbird persona at all → explicit 403 unauthorized state.
        $rolelessUser = User::factory()->create(['role' => 'user']);
        foreach (['/api/patient-flow/events', '/api/patient-flow/tracks', '/api/patient-flow/state', '/api/patient-flow/fhir/bundle?event_id=x'] as $path) {
            $this->actingAs($rolelessUser)
                ->getJson($path)
                ->assertForbidden()
                ->assertJsonPath('error.unauthorized_state', true);
        }

        // patient_dots: none personas (executive) are equally shut out …
        $executive = User::factory()->create(['role' => 'executive']);
        $this->actingAs($executive)
            ->getJson('/api/patient-flow/state')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'flow_lens_forbidden');

        // … but aggregate endpoints stay open to any authenticated user.
        $this->actingAs($rolelessUser)->getJson('/api/patient-flow/summary')->assertOk();

        // And the executive lens still gets the (aggregate) projection stream.
        $this->actingAs($executive)
            ->getJson('/api/patient-flow/projections')
            ->assertOk()
            ->assertJsonPath('lens.patient_dots', 'none');
    }

    public function test_superuser_receives_a_patient_capable_flow_lens(): void
    {
        $superuser = User::factory()->create(['role' => 'superuser']);

        $this->actingAs($superuser)
            ->getJson('/api/patient-flow/events')
            ->assertOk();

        // Broad-access default is the HOUSE lens (full dots), not the first
        // unit persona — charge_nurse-by-default collapsed the 4D navigator
        // to one auto-selected unit (2026-07-19 lens-collapse fix).
        $this->actingAs($superuser)
            ->getJson('/api/patient-flow/occupancy')
            ->assertOk()
            ->assertJsonPath('lens.role_id', 'house_supervisor')
            ->assertJsonPath('lens.patient_dots', 'full');
    }

    private function insertFlowEventAt(string $time, int $sequence): void
    {
        $hl7Time = CarbonImmutable::parse($time)->format('YmdHis');
        $raw = implode("\r", [
            "MSH|^~\\&|EHR|AMC|FLOW|AMC|{$hl7Time}||ADT^A01|ORDER{$sequence}|P|2.5.1",
            "EVN|A01|{$hl7Time}",
            "PID|||ORDER{$sequence}^^^AMC^MR||FLOW^ORDER{$sequence}",
            "PV1||I|TICU^TICU-R001^TICU-B001^ZEPHYRUS||||99001^ATTENDING^SYNTHETIC|||critical_care|||||||||VISORDER{$sequence}^^^AMC^VN",
            '',
        ]);

        $event = app(FlowEventNormalizer::class)->normalize($raw);
        app(FlowEventRepository::class)->upsertNormalizedEvent($event);
    }
}
