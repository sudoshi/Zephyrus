<?php

namespace Tests\Feature\Rounds;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsRoundsStory;
use Tests\TestCase;

/**
 * Guards for the 2026-07-11 rounds hardening pass:
 *  - queue_version bumps ONLY when the order changes (no spurious 409s);
 *  - task mutation is owner/leader-gated (not any unit-sharing contributor);
 *  - eddy_assisted mode is offered only when the Eddy sub-feature is on.
 */
class RoundHardeningTest extends TestCase
{
    use RefreshDatabase, SeedsRoundsStory;

    private string $runUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoundsStory();

        $board = $this->createRoundsRun();
        $this->runUuid = $board['data']['run']['run_uuid'];
        $this->actingAs($this->chargeNurse)->postJson("/api/rounds/runs/{$this->runUuid}/start")->assertOk();
    }

    public function test_non_reordering_transition_does_not_bump_queue_version_but_settling_does(): void
    {
        // A nurse note nudges ROUTINE queued -> in_progress (patient version only).
        $routine = $this->boardRowFor($this->board(), 'ROUNDS-PAT-ROUTINE');
        $this->submitNurseNote($routine['round_patient_uuid']);

        $before = $this->board()['meta']['version'];

        // in_progress -> ready_for_review keeps the patient in the active
        // partition at the same band: the order is unchanged, so the queue
        // version must NOT move (else concurrent pin/reorder would 409).
        $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/patients/{$routine['round_patient_uuid']}/mark-ready")
            ->assertOk();

        $this->assertSame($before, $this->board()['meta']['version'], 'mark-ready must not bump the queue version');

        // Deferring the ACUTE patient settles it out of the active queue: the
        // order genuinely changes, so the version SHOULD advance by exactly one.
        $acute = $this->boardRowFor($this->board(), 'ROUNDS-PAT-ACUTE');
        $this->actingAs($this->chargeNurse)
            ->postJson("/api/rounds/patients/{$acute['round_patient_uuid']}/defer", ['reason' => 'Awaiting imaging.'])
            ->assertOk();

        $this->assertSame($before + 1, $this->board()['meta']['version'], 'a settling transition must bump the queue version once');
    }

    public function test_task_transition_is_owner_or_leader_gated(): void
    {
        $routine = $this->boardRowFor($this->board(), 'ROUNDS-PAT-ROUTINE');

        // The charge nurse opens a pharmacy-owned task.
        $detail = $this->actingAs($this->chargeNurse)
            ->postJson("/api/rounds/patients/{$routine['round_patient_uuid']}/tasks", [
                'title' => 'Reconcile discharge meds',
                'owner_role' => 'pharmacist',
            ])->assertCreated()->json();

        $taskUuid = $detail['data']['tasks'][0]['task_uuid'];

        // A bedside nurse is a contributor but neither the owner, the creator,
        // nor a leader — they must not be able to close another discipline's task.
        $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/tasks/{$taskUuid}/transition", ['status' => 'completed'])
            ->assertStatus(403);

        // The charge nurse leads the run and may transition it.
        $this->actingAs($this->chargeNurse)
            ->postJson("/api/rounds/tasks/{$taskUuid}/transition", ['status' => 'completed'])
            ->assertOk();
    }

    public function test_eddy_assisted_mode_is_gated_by_the_eddy_flag(): void
    {
        $payload = [
            'template_uuid' => $this->roundsTemplate->template_uuid,
            'scope_type' => 'unit',
            'scope_key' => (string) $this->roundsUnit->unit_id,
            'mode' => 'eddy_assisted',
        ];

        // Eddy off (default): the request surface rejects the mode.
        config(['rounds.eddy_enabled' => false]);
        $this->actingAs($this->chargeNurse)->postJson('/api/rounds/runs', $payload)->assertStatus(422);

        // Eddy on: the mode is accepted (DB CHECK + templates already permit it).
        config(['rounds.eddy_enabled' => true]);
        $this->actingAs($this->chargeNurse)->postJson('/api/rounds/runs', $payload)->assertCreated();
    }

    /** @return array<string, mixed> current board payload */
    private function board(): array
    {
        return $this->actingAs($this->chargeNurse)
            ->getJson("/api/rounds/runs/{$this->runUuid}/board")
            ->assertOk()
            ->json();
    }

    private function submitNurseNote(string $roundPatientUuid): void
    {
        $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/patients/{$roundPatientUuid}/contributions", [
                'section_code' => 'overnight_events',
                'author_role' => 'bedside_nurse',
                'structured_data' => ['events' => 'Comfortable overnight.'],
                'submit' => true,
            ])->assertCreated();
    }
}
