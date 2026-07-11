<?php

namespace Tests\Feature\Rounds;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsRoundsStory;
use Tests\TestCase;

class RoundQueueTest extends TestCase
{
    use RefreshDatabase, SeedsRoundsStory;

    private array $board;

    private string $runUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoundsStory();

        $this->board = $this->createRoundsRun();
        $this->runUuid = $this->board['data']['run']['run_uuid'];
    }

    public function test_initial_order_is_deterministic_by_band(): void
    {
        $ordered = array_map(
            fn ($row) => $row['patient_label'],
            $this->board['data']['patients'],
        );

        // Band 2 (acute) before band 3 (discharge-ready) before band 5/6.
        $this->assertSame('ROUNDS-PAT-ACUTE', $ordered[0]);
        $this->assertSame('ROUNDS-PAT-DISCHARGE', $ordered[1]);
        $this->assertSame('ROUNDS-PAT-ROUTINE', $ordered[2]);

        // Positions and ETA windows are assigned in order.
        $positions = array_column($this->board['data']['patients'], 'queue_position');
        $this->assertSame([1, 2, 3], $positions);
        $this->assertNotNull($this->board['data']['patients'][0]['eta_window_start']);
        $this->assertNotNull($this->board['data']['patients'][2]['eta_window_end']);
    }

    public function test_pin_requires_reason_and_moves_patient_to_band_one(): void
    {
        $routine = $this->boardRowFor($this->board, 'ROUNDS-PAT-ROUTINE');

        $this->actingAs($this->chargeNurse)
            ->postJson("/api/rounds/patients/{$routine['round_patient_uuid']}/pin", [
                'pinned' => true,
                'expected_queue_version' => 1,
            ])->assertStatus(422); // reason is required

        $updated = $this->actingAs($this->chargeNurse)
            ->postJson("/api/rounds/patients/{$routine['round_patient_uuid']}/pin", [
                'pinned' => true,
                'reason' => 'Family meeting at 10:00.',
                'expected_queue_version' => 1,
            ])->assertOk()->json();

        $this->assertSame(
            'ROUNDS-PAT-ROUTINE',
            $updated['data']['patients'][0]['patient_label'],
        );
        $this->assertSame(1, $updated['data']['patients'][0]['priority_band']);
        $this->assertTrue($updated['data']['patients'][0]['pinned']);
        $this->assertGreaterThan(1, $updated['meta']['version']);
    }

    public function test_stale_queue_version_returns_409_with_current_projection(): void
    {
        $uuids = array_column($this->board['data']['patients'], 'round_patient_uuid');

        $response = $this->actingAs($this->chargeNurse)
            ->patchJson("/api/rounds/runs/{$this->runUuid}/queue", [
                'order' => array_reverse($uuids),
                'expected_queue_version' => 99,
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'rounds_conflict');

        // The conflict body carries the current projection for recovery.
        $this->assertSame(1, $response->json('current.meta.version'));
        $this->assertCount(3, $response->json('current.data.patients'));
    }

    public function test_reorder_applies_explicit_order_and_bumps_version(): void
    {
        $uuids = array_column($this->board['data']['patients'], 'round_patient_uuid');
        $reversed = array_reverse($uuids);

        $updated = $this->actingAs($this->chargeNurse)
            ->patchJson("/api/rounds/runs/{$this->runUuid}/queue", [
                'order' => $reversed,
                'expected_queue_version' => 1,
            ])->assertOk()->json();

        $this->assertSame($reversed, array_column($updated['data']['patients'], 'round_patient_uuid'));
        $this->assertSame(2, $updated['meta']['version']);
    }

    public function test_reorder_is_idempotent_by_key(): void
    {
        $uuids = array_column($this->board['data']['patients'], 'round_patient_uuid');
        $key = 'reorder-'.uniqid();

        $this->actingAs($this->chargeNurse)
            ->patchJson("/api/rounds/runs/{$this->runUuid}/queue", [
                'order' => array_reverse($uuids),
                'expected_queue_version' => 1,
            ], ['Idempotency-Key' => $key])->assertOk();

        // Replay with the same key: no second execution, version unchanged.
        $replayed = $this->actingAs($this->chargeNurse)
            ->patchJson("/api/rounds/runs/{$this->runUuid}/queue", [
                'order' => array_reverse($uuids),
                'expected_queue_version' => 1,
            ], ['Idempotency-Key' => $key])->assertOk()->json();

        $this->assertSame(2, $replayed['meta']['version']);
    }

    public function test_bedside_nurse_cannot_reorder_queue(): void
    {
        $uuids = array_column($this->board['data']['patients'], 'round_patient_uuid');

        $this->actingAs($this->bedsideNurse)
            ->patchJson("/api/rounds/runs/{$this->runUuid}/queue", [
                'order' => array_reverse($uuids),
                'expected_queue_version' => 1,
            ])->assertForbidden();
    }
}
