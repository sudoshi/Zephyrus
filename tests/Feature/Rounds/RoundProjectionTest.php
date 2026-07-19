<?php

namespace Tests\Feature\Rounds;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsRoundsStory;
use Tests\TestCase;

class RoundProjectionTest extends TestCase
{
    use RefreshDatabase, SeedsRoundsStory;

    private string $runUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoundsStory();

        $board = $this->createRoundsRun();
        $this->runUuid = $board['data']['run']['run_uuid'];
    }

    public function test_board_envelope_carries_version_cutoff_scope_and_lens(): void
    {
        $board = $this->actingAs($this->chargeNurse)
            ->getJson("/api/rounds/runs/{$this->runUuid}/board")
            ->assertOk()->json();

        $this->assertSame(1, $board['meta']['version']);
        $this->assertNotNull($board['meta']['generated_at']);
        $this->assertNotNull($board['meta']['source_cutoff_at']);
        $this->assertSame('unit:'.$this->roundsUnit->unit_id, $board['meta']['scope']);
        $this->assertSame('detail', $board['meta']['lens']);
    }

    public function test_scene_projection_contains_only_opaque_tokens(): void
    {
        $response = $this->actingAs($this->chargeNurse)
            ->getJson("/api/rounds/runs/{$this->runUuid}/scene")
            ->assertOk();

        $scene = $response->json();
        $this->assertCount(3, $scene['data']['stops']);

        foreach ($scene['data']['stops'] as $stop) {
            $this->assertArrayHasKey('round_patient_uuid', $stop);
            $this->assertArrayHasKey('status', $stop);
            $this->assertArrayHasKey('priority_band', $stop);
            // The 4D navigator's queue sprites, route polyline, and tour
            // (flow4d Phase 3) place stops by these fields — contract guard.
            $this->assertArrayHasKey('queue_position', $stop);
            $this->assertArrayHasKey('unit_id', $stop);
            $this->assertArrayHasKey('bed', $stop);
            $this->assertArrayHasKey('pinned', $stop);
            $this->assertArrayNotHasKey('patient_ref', $stop);
            $this->assertArrayNotHasKey('patient_label', $stop);
        }

        // No patient identifier anywhere in the scene payload.
        $this->assertStringNotContainsString('ROUNDS-PAT', $response->getContent());

        // Discharge/missing-input glyph flags are derived server-side.
        $flags = collect($scene['data']['stops'])->firstWhere('discharge_ready', true);
        $this->assertNotNull($flags);
    }

    public function test_scene_projection_strips_bed_level_context_for_aggregate_lenses(): void
    {
        // F-2 ruling (2026-07-19): under patient_dots = none, bed + queue +
        // status is re-identifiable at ward level on a shared wall — the
        // projection anchors at unit centroids (bed/facility_space stripped).
        $run = \App\Models\Rounds\RoundRun::query()->where('run_uuid', $this->runUuid)->firstOrFail();
        $scene = app(\App\Services\Rounds\RoundProjectionService::class)
            ->scene($run, $this->chargeNurse, aggregate: true);

        $this->assertCount(3, $scene['data']['stops']);
        foreach ($scene['data']['stops'] as $stop) {
            $this->assertNull($stop['bed']);
            $this->assertNull($stop['facility_space_id']);
            // The overlay still works: opaque anchor identity + unit centroid.
            $this->assertArrayHasKey('round_patient_uuid', $stop);
            $this->assertNotNull($stop['unit_id']);
        }

        // The full-detail path is unchanged (contract test above still pins bed).
        $full = app(\App\Services\Rounds\RoundProjectionService::class)
            ->scene($run, $this->chargeNurse);
        $this->assertNotNull(collect($full['data']['stops'])->firstWhere('bed'));
    }

    public function test_broad_access_admin_sees_detail_without_unit_assignment(): void
    {
        $board = $this->actingAs($this->admin)
            ->getJson("/api/rounds/runs/{$this->runUuid}/board")
            ->assertOk()->json();

        $this->assertSame('detail', $board['meta']['lens']);
        $this->assertNotNull($board['data']['patients'][0]['patient_label']);
    }

    public function test_participant_without_unit_share_gets_aggregate_lens_via_detail_redaction(): void
    {
        // Invite an off-unit consultant as a run participant: they can view,
        // but the aggregate lens must redact identifiers and clinical text.
        $consultant = User::factory()->create(['role' => 'user']);
        $run = \App\Models\Rounds\RoundRun::query()->where('run_uuid', $this->runUuid)->firstOrFail();
        \App\Models\Rounds\RoundParticipant::create([
            'participant_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'run_id' => $run->run_id,
            'user_id' => $consultant->id,
            'role_code' => 'consultant',
            'required' => false,
            'status' => 'invited',
        ]);

        // Participants may contribute but do NOT share the unit, so the board
        // itself stays forbidden (unit-scope view requires assignment).
        $this->actingAs($consultant)
            ->getJson("/api/rounds/runs/{$this->runUuid}/board")
            ->assertForbidden();
    }

    public function test_patient_detail_includes_requirements_and_audit_fields(): void
    {
        $board = $this->actingAs($this->chargeNurse)
            ->getJson("/api/rounds/runs/{$this->runUuid}/board")->json();
        $uuid = $board['data']['patients'][0]['round_patient_uuid'];

        $detail = $this->actingAs($this->chargeNurse)
            ->getJson("/api/rounds/patients/{$uuid}")
            ->assertOk()->json();

        $this->assertArrayHasKey('requirements', $detail['data']);
        $this->assertArrayHasKey('missing', $detail['data']['requirements']);
        $this->assertArrayHasKey('priority_reasons', $detail['data']);
        $this->assertNotEmpty($detail['data']['priority_reasons']);
        $this->assertArrayHasKey('inclusion', $detail['data']);
        $this->assertSame('prod_census', $detail['data']['inclusion']['source']);
    }

    public function test_run_listing_filters_to_viewable_runs(): void
    {
        $runs = $this->actingAs($this->outsider)
            ->getJson('/api/rounds/runs')
            ->assertOk()->json('data');

        $this->assertSame([], $runs);

        $runs = $this->actingAs($this->chargeNurse)
            ->getJson('/api/rounds/runs')
            ->assertOk()->json('data');

        $this->assertCount(1, $runs);
    }

    public function test_scopes_endpoint_lists_only_assigned_units(): void
    {
        $scopes = $this->actingAs($this->bedsideNurse)
            ->getJson('/api/rounds/scopes')
            ->assertOk()->json('data');

        $this->assertCount(1, $scopes);
        $this->assertSame((string) $this->roundsUnit->unit_id, $scopes[0]['scope_key']);

        $adminScopes = $this->actingAs($this->admin)
            ->getJson('/api/rounds/scopes')
            ->assertOk()->json('data');

        $this->assertGreaterThanOrEqual(2, count($adminScopes));
    }
}
