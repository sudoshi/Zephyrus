<?php

namespace Tests\Feature\Mobile;

use App\Models\Unit;
use App\Models\User;
use App\Services\Mobile\MobilePersonaCatalog;
use Database\Seeders\RtdcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsFlowStory;
use Tests\TestCase;

/**
 * The 48-hour Flow Window contract — FLOW-WINDOW-PLAN §6.4 + §12:
 * lens clamping, the 48h span cap, layer filtering, the ETagged plate
 * asset, and (most importantly) the redaction matrix: zero raw patient
 * refs in ANY window payload, and zero patient identity of any kind for
 * patient_dots:none personas.
 */
class FlowWindowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsFlowStory;

    private const RAW_PATIENT_REFS = ['FLOWTEST-PAT-EDD', 'FLOWTEST-PAT-TRIP', 'FLOWTEST-PAT-TURN'];

    private const DOTLESS_ROLES = ['executive', 'pi_lead', 'staffing_coordinator', 'or_nurse', 'periop_manager'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RtdcSeeder::class);
        $this->seedFlowStory();
    }

    public function test_window_returns_the_uniform_envelope_with_all_layers_for_the_bed_manager(): void
    {
        $this->actingAsRole('bed_manager');

        $response = $this->getJson('/api/mobile/v1/flow/window?persona=bed_manager')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'window' => ['from', 'to', 'now'],
                    'lens' => ['role_id', 'scopes_allowed', 'layers', 'event_kinds', 'projection_kinds', 'patient_dots', 'actions', 'default_zoom_hours'],
                    'scope' => ['type', 'label'],
                    'spaces' => ['plates_version', 'floors'],
                    'snapshots',
                    'events',
                    'projections',
                ],
                'meta' => ['as_of', 'stale', 'version'],
                'links' => ['web'],
            ]);

        $this->assertSame('house', $response->json('data.scope.type'));
        $this->assertSame('full', $response->json('data.lens.patient_dots'));
        $this->assertStringContainsString('persona=bed_manager', $response->json('links.web'));

        // The seeded story surfaces on both halves of the window.
        $kinds = collect($response->json('data.events'))->pluck('kind');
        $this->assertTrue($kinds->contains('admit'), 'seeded admit event should be on the timeline');
        $this->assertTrue($kinds->contains('barrier_opened'), 'seeded barrier should be on the timeline');

        $projections = collect($response->json('data.projections'));
        $this->assertTrue($projections->pluck('kind')->contains('expected_discharge'));
        $this->assertTrue($projections->pluck('kind')->contains('transport_due'));

        // Every projection is defensible: confidence + provenance.service, always.
        foreach ($projections as $item) {
            $this->assertContains($item['confidence'], ['definite', 'probable', 'possible']);
            $this->assertNotEmpty($item['provenance']['service']);
        }
    }

    public function test_window_span_is_capped_at_48_hours_centered_on_now(): void
    {
        $this->actingAsRole('bed_manager');

        $response = $this->getJson('/api/mobile/v1/flow/window?persona=bed_manager&from=2000-01-01T00:00:00Z&to=2100-01-01T00:00:00Z')
            ->assertOk();

        $from = new \DateTimeImmutable($response->json('data.window.from'));
        $to = new \DateTimeImmutable($response->json('data.window.to'));
        $this->assertEqualsWithDelta(48 * 3600, $to->getTimestamp() - $from->getTimestamp(), 5);
    }

    public function test_layers_parameter_filters_the_payload(): void
    {
        $this->actingAsRole('bed_manager');

        $response = $this->getJson('/api/mobile/v1/flow/window?persona=bed_manager&layers=snapshots')
            ->assertOk();

        $this->assertArrayHasKey('snapshots', $response->json('data'));
        $this->assertArrayNotHasKey('events', $response->json('data'));
        $this->assertArrayNotHasKey('projections', $response->json('data'));
        $this->assertArrayNotHasKey('spaces', $response->json('data'));
    }

    public function test_unauthorized_scopes_are_an_explicit_403_state(): void
    {
        // Executive lens is house-only.
        $this->actingAsRole('executive');
        $unit = Unit::where('abbreviation', 'MICU')->firstOrFail();

        $this->getJson('/api/mobile/v1/flow/window?persona=executive&scope=unit:'.$unit->unit_id)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'flow_lens_forbidden')
            ->assertJsonPath('error.unauthorized_state', true);

        // Transport may not take a unit scope either (house/floor/patient only).
        $this->actingAsRole('transport');
        $this->getJson('/api/mobile/v1/flow/window?persona=transport&scope=unit:'.$unit->unit_id)
            ->assertForbidden();
    }

    public function test_every_persona_gets_a_window_and_no_payload_ever_leaks_a_raw_patient_ref(): void
    {
        foreach (MobilePersonaCatalog::ROLE_IDS as $roleId) {
            $this->actingAsRole($roleId);

            $response = $this->getJson('/api/mobile/v1/flow/window?persona='.$roleId);
            $response->assertOk();

            $body = $response->getContent();
            foreach (self::RAW_PATIENT_REFS as $rawRef) {
                $this->assertStringNotContainsString($rawRef, $body, "{$roleId} window leaked a raw patient ref");
            }
            $this->assertStringNotContainsString('"_patient_ref"', $body, "{$roleId} window leaked the internal ref field");

            // The dot policy in the payload must match the lens config.
            $expected = config("hummingbird.flow_lens.{$roleId}.patient_dots");
            if ($expected === 'none') {
                $this->assertStringNotContainsString('ptok_', $body, "{$roleId} is patient_dots:none but its payload contains a patient token");
            }
            $this->assertSame($roleId, $response->json('data.lens.role_id'));
        }
    }

    public function test_task_depth_roles_see_tokens_only_for_their_own_request_patients(): void
    {
        $this->actingAsRole('transport');

        $body = $this->getJson('/api/mobile/v1/flow/window?persona=transport')
            ->assertOk()
            ->getContent();

        // The trip patient is task-linked → its ptok may appear …
        $tripPtok = app(\App\Services\Mobile\MobilePatientContextService::class)
            ->contextRefFor('FLOWTEST-PAT-TRIP');
        $this->assertStringContainsString($tripPtok, $body);

        // … but the EDD patient (no transport request) must not.
        $eddPtok = app(\App\Services\Mobile\MobilePatientContextService::class)
            ->contextRefFor('FLOWTEST-PAT-EDD');
        $this->assertStringNotContainsString($eddPtok, $body);
    }

    public function test_unit_depth_requires_a_shared_unit_assignment(): void
    {
        $micu = Unit::where('abbreviation', 'MICU')->firstOrFail();
        $eddPtok = app(\App\Services\Mobile\MobilePatientContextService::class)
            ->contextRefFor('FLOWTEST-PAT-EDD');

        // A charge nurse NOT assigned to MICU: unit scope works, identity is stripped.
        $stranger = $this->actingAsRole('charge_nurse');
        $body = $this->getJson('/api/mobile/v1/flow/window?persona=charge_nurse&scope=unit:'.$micu->unit_id)
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString($eddPtok, $body);

        // Assigned to MICU: the same request now carries the unit's patient tokens.
        $assigned = $this->actingAsRole('charge_nurse');
        $assigned->units()->attach($micu->unit_id, ['role' => 'charge_nurse', 'is_primary' => true]);
        $body = $this->getJson('/api/mobile/v1/flow/window?persona=charge_nurse&scope=unit:'.$micu->unit_id)
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString($eddPtok, $body);
    }

    public function test_or_milestones_and_scheduled_cases_carry_the_room_identity(): void
    {
        $this->actingAsRole('periop_manager');

        $response = $this->getJson('/api/mobile/v1/flow/window?persona=periop_manager&scope=house')
            ->assertOk();

        // The four OR-side milestones land in the named room, not generic 'OR'.
        $milestones = collect($response->json('data.events'))->where('kind', 'or_milestone');
        $this->assertNotEmpty($milestones, 'seeded orlog milestones should be on the timeline');
        $this->assertSame(['OR 3'], $milestones->pluck('to_space')->unique()->values()->all());

        $cases = collect($response->json('data.projections'))->where('kind', 'scheduled_or_case');
        $this->assertNotEmpty($cases, 'seeded OR case should project onto the schedule lane');
        $this->assertSame('OR 3', $cases->first()['room']);

        // Non-OR projections carry the field as null — additive, never absent.
        $this->actingAsRole('bed_manager');
        $discharge = collect($this->getJson('/api/mobile/v1/flow/window?persona=bed_manager')->json('data.projections'))
            ->firstWhere('kind', 'expected_discharge');
        $this->assertArrayHasKey('room', $discharge);
        $this->assertNull($discharge['room']);
    }

    public function test_bed_statuses_are_served_only_to_bed_status_lenses_at_floor_or_unit_scope(): void
    {
        $micu = Unit::where('abbreviation', 'MICU')->firstOrFail();
        $floor = (int) app(\App\Support\Hospital\HospitalManifest::class)->unit('MICU')['floor'];

        // EVS (event_kinds includes bed_status) at floor scope → the turn map.
        $this->actingAsRole('evs');
        $data = $this->getJson('/api/mobile/v1/flow/window?persona=evs&scope=floor:'.$floor)
            ->assertOk()
            ->json('data');
        $this->assertArrayHasKey('bed_statuses', $data);
        $occupied = collect($data['bed_statuses'])->firstWhere('unit_id', $micu->unit_id);
        $this->assertNotNull($occupied, 'floor scope must include the scoped floor\'s beds');
        foreach ($data['bed_statuses'] as $bed) {
            $this->assertContains($bed['status'], ['available', 'occupied', 'blocked', 'dirty']);
            $this->assertIsInt($bed['bed_id']);
            $this->assertIsInt($bed['unit_id']);
            $this->assertNotSame('', (string) $bed['label']);
        }
        $this->assertContains('occupied', collect($data['bed_statuses'])->pluck('status')->all());

        // …but never at house scope (current-state bed detail is floor/unit only).
        $this->assertArrayNotHasKey(
            'bed_statuses',
            $this->getJson('/api/mobile/v1/flow/window?persona=evs')->assertOk()->json('data'),
        );

        // Transport's lens has no bed_status kind → same floor, no key.
        $this->actingAsRole('transport');
        $this->assertArrayNotHasKey(
            'bed_statuses',
            $this->getJson('/api/mobile/v1/flow/window?persona=transport&scope=floor:'.$floor)->assertOk()->json('data'),
        );

        // Executive is house-only and dotless — unaffected either way.
        $this->actingAsRole('executive');
        $this->assertArrayNotHasKey(
            'bed_statuses',
            $this->getJson('/api/mobile/v1/flow/window?persona=executive')->assertOk()->json('data'),
        );
    }

    public function test_transport_status_and_evs_status_events_ride_the_timeline(): void
    {
        $this->actingAsRole('bed_manager');

        $events = collect($this->getJson('/api/mobile/v1/flow/window?persona=bed_manager')
            ->assertOk()
            ->json('data.events'));

        $transport = $events->where('kind', 'transport_status');
        $this->assertCount(2, $transport, 'both seeded transport transitions should be on the timeline');
        $this->assertSame('ED', $transport->first()['from_space']);
        $this->assertSame('MICU-01', $transport->first()['to_space']);

        $evs = $events->where('kind', 'evs_status');
        $this->assertNotEmpty($evs, 'seeded EVS transition should be on the timeline');
        $this->assertSame('MICU-01', $evs->first()['to_space']);
    }

    public function test_delta_refresh_narrows_the_append_only_halves_and_serves_projections_in_full(): void
    {
        Artisan::call('flow:snapshot', ['--backfill' => '24h']);
        $this->actingAsRole('bed_manager');

        $full = $this->getJson('/api/mobile/v1/flow/window?persona=bed_manager')->assertOk()->json('data');
        $events = collect($full['events']);
        $this->assertNotEmpty($events, 'the seeded story should put events on the timeline');

        // A `since` at the earliest event drops at least that event (t > since is strict).
        $earliest = $events->map(fn (array $e): string => $e['t'])->sort()->first();
        $since = (new \DateTimeImmutable($earliest))->format(\DateTimeInterface::ATOM);

        $delta = $this->getJson('/api/mobile/v1/flow/window?persona=bed_manager&since='.urlencode($since))
            ->assertOk()->json('data');

        // The cursor is echoed (compared as instants — the server re-serializes it).
        $this->assertNotNull($delta['window']['since']);
        $this->assertEquals(new \DateTimeImmutable($since), new \DateTimeImmutable($delta['window']['since']));

        // Append-only halves are strictly narrowed; nothing at/at-or-before the cursor survives.
        $this->assertLessThan(count($full['events']), count($delta['events']));
        foreach ($delta['events'] as $event) {
            $this->assertGreaterThan(new \DateTimeImmutable($since), new \DateTimeImmutable($event['t']));
        }
        $this->assertLessThanOrEqual(count($full['snapshots']), count($delta['snapshots']));

        // Projections and spaces are recomputed in full on every request.
        $this->assertCount(count($full['projections']), $delta['projections']);
        $this->assertSame($full['spaces']['plates_version'], $delta['spaces']['plates_version']);
    }

    public function test_delta_with_out_of_range_or_malformed_since_is_a_422(): void
    {
        $this->actingAsRole('bed_manager');

        foreach (['2000-01-01T00:00:00Z', '2100-01-01T00:00:00Z', 'not-a-timestamp'] as $bad) {
            $this->getJson('/api/mobile/v1/flow/window?persona=bed_manager&since='.urlencode($bad))
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'invalid_since');
        }
    }

    public function test_delta_responses_still_enforce_lens_redaction(): void
    {
        // Executive is patient_dots:none — a delta request must be as redacted as a full one.
        $this->actingAsRole('executive');
        $now = $this->getJson('/api/mobile/v1/flow/window?persona=executive')->assertOk()->json('data.window.now');

        $body = $this->getJson('/api/mobile/v1/flow/window?persona=executive&since='.urlencode($now))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('ptok_', $body, 'a dotless role must not gain a token on a delta refresh');
        foreach (self::RAW_PATIENT_REFS as $rawRef) {
            $this->assertStringNotContainsString($rawRef, $body);
        }
    }

    public function test_bed_statuses_survive_a_qualifying_evs_floor_scope_delta(): void
    {
        $floor = (int) app(\App\Support\Hospital\HospitalManifest::class)->unit('MICU')['floor'];
        $this->actingAsRole('evs');

        $now = $this->getJson('/api/mobile/v1/flow/window?persona=evs&scope=floor:'.$floor)
            ->assertOk()->json('data.window.now');

        $data = $this->getJson('/api/mobile/v1/flow/window?persona=evs&scope=floor:'.$floor.'&since='.urlencode($now))
            ->assertOk()->json('data');

        // bed_statuses is "now" state, always full — a delta never strips it.
        $this->assertArrayHasKey('bed_statuses', $data);
        $this->assertNotEmpty($data['bed_statuses']);
        $this->assertEquals(new \DateTimeImmutable($now), new \DateTimeImmutable($data['window']['since']));
    }

    public function test_duties_are_clamped_to_each_personas_lens_duty_kinds(): void
    {
        foreach (MobilePersonaCatalog::ROLE_IDS as $roleId) {
            $this->actingAsRole($roleId);
            $allowed = config("hummingbird.flow_lens.{$roleId}.duty_kinds");

            $duties = collect($this->getJson('/api/mobile/v1/flow/window?persona='.$roleId.'&layers=duties')
                ->assertOk()->json('data.duties'));

            foreach ($duties as $duty) {
                $this->assertContains($duty['kind'], $allowed, "{$roleId} received an out-of-lens duty kind: {$duty['kind']}");
            }
            if ($allowed === []) {
                $this->assertCount(0, $duties, "{$roleId} has no duty_kinds but its payload carried duties");
            }
        }
    }

    public function test_frontline_duties_carry_temporal_status_provenance_and_an_action(): void
    {
        $this->actingAsRole('transport');

        $run = collect($this->getJson('/api/mobile/v1/flow/window?persona=transport')->assertOk()->json('data.duties'))
            ->firstWhere('kind', 'transport_run');

        $this->assertNotNull($run, 'the seeded trip should surface as a transport_run duty');
        $this->assertContains($run['window_status'], ['overdue', 'due', 'upcoming', null]);
        $this->assertContains($run['tier'], ['critical', 'warning', 'info']);
        $this->assertSame('duty_projection', $run['provenance']['service']);
        $this->assertArrayHasKey('centroid_m', $run); // present (a 3D anchor or null when unmapped)
        $this->assertNotNull($run['action'], 'a transport_run is actionable');
        $this->assertStringContainsString('/transport/requests/', $run['action']['endpoint']);
        $this->assertSame('POST', $run['action']['method']);

        // The EVS turn and the EDD both surface as duties for their personas.
        $this->actingAsRole('evs');
        $this->assertNotNull(
            collect($this->getJson('/api/mobile/v1/flow/window?persona=evs')->assertOk()->json('data.duties'))
                ->firstWhere('kind', 'bed_turn'),
            'the seeded isolation turn should surface as a bed_turn duty',
        );
    }

    public function test_duties_honour_the_redaction_matrix(): void
    {
        foreach (MobilePersonaCatalog::ROLE_IDS as $roleId) {
            $this->actingAsRole($roleId);
            $duties = $this->getJson('/api/mobile/v1/flow/window?persona='.$roleId.'&layers=duties')
                ->assertOk()->json('data.duties');
            $json = json_encode($duties);

            foreach (self::RAW_PATIENT_REFS as $raw) {
                $this->assertStringNotContainsString($raw, $json, "{$roleId} duties leaked a raw patient ref");
            }
            $this->assertStringNotContainsString('_patient_ref', $json, "{$roleId} duties leaked the internal ref field");

            if (config("hummingbird.flow_lens.{$roleId}.patient_dots") === 'none') {
                foreach ($duties as $duty) {
                    $this->assertNull($duty['patient_context_ref'] ?? null, "{$roleId} is patient_dots:none but a duty carried a token");
                }
            }
        }
    }

    public function test_duties_survive_a_since_delta_in_full(): void
    {
        $this->actingAsRole('transport');
        $now = $this->getJson('/api/mobile/v1/flow/window?persona=transport')->assertOk()->json('data.window.now');

        $full = $this->getJson('/api/mobile/v1/flow/window?persona=transport')->assertOk()->json('data.duties');
        $delta = $this->getJson('/api/mobile/v1/flow/window?persona=transport&since='.urlencode($now))
            ->assertOk()->json('data.duties');

        $this->assertNotEmpty($full);
        $this->assertCount(count($full), $delta, 'duties are current worklist — always full, never narrowed by a delta');
    }

    public function test_spaces3d_asset_is_versioned_and_carries_3d_centroids(): void
    {
        $this->actingAsRole('bed_manager');

        $res = $this->getJson('/api/mobile/v1/flow/spaces3d')->assertOk();
        $etag = $res->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertSame(trim($etag, '"'), $res->json('data.version'));

        // The bare test DB has no CAD catalog imported (facility:import-catalog
        // is a demo/deploy step), so `spaces` may be empty here; against a
        // catalog-loaded DB every entry carries a {x, y, z} metre centroid.
        $spaces = $res->json('data.spaces');
        $this->assertIsArray($spaces);
        foreach ($spaces as $space) {
            $this->assertEqualsCanonicalizing(['x', 'y', 'z'], array_keys($space['centroid_m']));
            $this->assertArrayHasKey('service_line', $space, 'each space carries its (nullable) service line for map color');
        }

        // The service-line legend is the single source of map color. It always carries the
        // neutral `unassigned` swatch; any real (catalog) line adds a {name, color} entry.
        $legend = $res->json('data.service_lines');
        $this->assertIsArray($legend);
        $this->assertArrayHasKey('unassigned', $legend);
        foreach ($legend as $code => $style) {
            $this->assertArrayHasKey('name', $style, "legend entry {$code} has a display name");
            $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $style['color'], "legend entry {$code} has a hex color");
        }

        $this->withHeaders(['If-None-Match' => $etag])
            ->getJson('/api/mobile/v1/flow/spaces3d')
            ->assertStatus(304);
    }

    public function test_floors_asset_is_versioned_and_supports_conditional_requests(): void
    {
        $this->actingAsRole('bed_manager');

        $first = $this->getJson('/api/mobile/v1/flow/floors')->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertSame(trim($etag, '"'), $first->json('data.version'));

        $this->withHeaders(['If-None-Match' => $etag])
            ->getJson('/api/mobile/v1/flow/floors')
            ->assertStatus(304);
    }

    public function test_snapshot_backfill_populates_the_review_half(): void
    {
        Artisan::call('flow:snapshot', ['--backfill' => '24h']);

        $this->actingAsRole('bed_manager');
        $snapshots = $this->getJson('/api/mobile/v1/flow/window?persona=bed_manager&layers=snapshots')
            ->assertOk()
            ->json('data.snapshots');

        $this->assertNotEmpty($snapshots, 'backfill should yield census checkpoints inside the window');
        $this->assertArrayHasKey('occupied', $snapshots[0]);
        $this->assertArrayHasKey('staffed', $snapshots[0]);
    }

    public function test_live_flow_snapshot_persists_redactable_occupancy_details(): void
    {
        $unit = Unit::where('abbreviation', 'MICU')->firstOrFail();
        $spaceId = DB::table('hosp_space.facility_spaces')->insertGetId([
            'space_code' => 'FLOWTEST:MICU',
            'space_name' => 'Flow Test MICU',
            'space_category' => 'unit',
            'floor_label' => '3',
            'floor_number' => 3,
            'service_line_code' => 'critical_care',
            'acuity_level' => 'icu',
            'status' => 'active',
            'geometry' => json_encode(['bounds' => [0, 0, 100, 100]]),
            'attributes' => json_encode(['fixture' => 'flow-window-test']),
            'source_system' => 'test',
            'source_confidence' => 1,
            'facility_key' => 'HOSP1',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'facility_space_id');
        $unit->update(['facility_space_id' => $spaceId]);

        Artisan::call('flow:snapshot');

        $row = DB::table('flow_core.occupancy_snapshots')
            ->where('active_patient_count', '>', 0)
            ->whereRaw('jsonb_array_length(occupancy_details) > 0')
            ->orderByDesc('snapshot_at')
            ->first();

        $this->assertNotNull($row, 'flow:snapshot should write disk-ready occupancy detail rows.');

        $details = json_decode($row->occupancy_details, true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($details);
        $this->assertSame('FLOWTEST-PAT-EDD', $details[0]['patient_ref']);
        $this->assertArrayHasKey('encounter_id', $details[0]);
        $this->assertArrayHasKey('timer_status', $details[0]);
        $this->assertSame('prod', $details[0]['source']['schema']);
        $this->assertSame('encounters', $details[0]['source']['table']);

        $snapshotAt = Carbon::parse($row->snapshot_at);
        $query = http_build_query([
            'from' => $snapshotAt->copy()->subMinute()->toIso8601String(),
            'to' => $snapshotAt->copy()->addMinute()->toIso8601String(),
        ]);

        $bedManager = User::factory()->create(['role' => 'bed_manager', 'must_change_password' => false]);
        $webHistoryDetail = $this->actingAs($bedManager)
            ->getJson("/api/patient-flow/occupancy/history?{$query}")
            ->assertOk()
            ->assertJsonPath('summary.snapshots', 1)
            ->json('history.0.occupancy_details.0');

        $this->assertStringStartsWith('ptok_', $webHistoryDetail['patient_context_ref'] ?? '');
        $this->assertArrayNotHasKey('patient_ref', $webHistoryDetail);
        $this->assertArrayNotHasKey('encounter_id', $webHistoryDetail);

        $executive = User::factory()->create(['role' => 'executive', 'must_change_password' => false]);
        $executiveDetail = $this->actingAs($executive)
            ->getJson("/api/patient-flow/occupancy/history?{$query}")
            ->assertOk()
            ->assertJsonPath('lens.patient_dots', 'none')
            ->assertJsonPath('summary.redacted', true)
            ->json('history.0.occupancy_details');

        $this->assertSame([], $executiveDetail);

        Sanctum::actingAs($bedManager, ['mobile:read']);
        $this->getJson('/api/mobile/v1/flow/demo-scenarios?persona=bed_manager')
            ->assertOk()
            ->assertJsonPath('data.0.history_supported', true)
            ->assertJsonPath('meta.source_mode', 'synthetic_demo');

        $mobileHistoryResponse = $this->getJson("/api/mobile/v1/flow/occupancy/history?persona=bed_manager&{$query}")
            ->assertOk()
            ->assertJsonPath('data.summary.snapshots', 1);

        $mobileHistoryBody = $mobileHistoryResponse->getContent();
        $this->assertStringNotContainsString('FLOWTEST-PAT-EDD', $mobileHistoryBody);
        $this->assertStringNotContainsString('"patient_ref"', $mobileHistoryBody);
        $this->assertStringNotContainsString('"encounter_id"', $mobileHistoryBody);

        $mobileHistoryDetail = $mobileHistoryResponse->json('data.history.0.occupancy_details.0');
        $this->assertStringStartsWith('ptok_', $mobileHistoryDetail['patient_context_ref'] ?? '');

        Sanctum::actingAs($executive, ['mobile:read']);
        $this->getJson("/api/mobile/v1/flow/occupancy/history?persona=executive&{$query}")
            ->assertOk()
            ->assertJsonPath('data.lens.patient_dots', 'none')
            ->assertJsonPath('data.history.0.occupancy_details', []);

        $this->getJson('/api/mobile/v1/flow/occupancy/history?persona=executive&from=nope')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_occupancy_history_window');
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function actingAsRole(string $roleId): User
    {
        $user = User::factory()->create([
            'role' => $roleId,
            'must_change_password' => false,
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['mobile:read']);

        return $user;
    }
}
