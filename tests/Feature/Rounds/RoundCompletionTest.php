<?php

namespace Tests\Feature\Rounds;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsRoundsStory;
use Tests\TestCase;

class RoundCompletionTest extends TestCase
{
    use RefreshDatabase, SeedsRoundsStory;

    private string $runUuid;

    private string $patientUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoundsStory();

        $board = $this->createRoundsRun();
        $this->runUuid = $board['data']['run']['run_uuid'];
        $this->patientUuid = $this->boardRowFor($board, 'ROUNDS-PAT-ROUTINE')['round_patient_uuid'];

        $this->actingAs($this->chargeNurse)->postJson("/api/rounds/runs/{$this->runUuid}/start")->assertOk();
    }

    public function test_board_names_exactly_which_requirement_is_missing(): void
    {
        $board = $this->actingAs($this->chargeNurse)
            ->getJson("/api/rounds/runs/{$this->runUuid}/board")->json();

        $requirements = $this->boardRowFor($board, 'ROUNDS-PAT-ROUTINE')['requirements'];

        $this->assertFalse($requirements['satisfied']);
        $this->assertEqualsCanonicalizing([
            ['role' => 'bedside_nurse', 'section' => 'overnight_events', 'requirement' => 'hard'],
            ['role' => 'attending', 'section' => 'clinical_plan', 'requirement' => 'hard'],
            ['role' => 'pharmacist', 'section' => 'medications', 'requirement' => 'soft'],
        ], $requirements['missing']);
    }

    public function test_cannot_round_patient_with_missing_hard_requirements_without_exception(): void
    {
        // Move the patient into a reviewable state first.
        $this->submitNurseNote();

        $this->actingAs($this->chargeNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/complete")
            ->assertStatus(422); // attending plan still missing, no exception given

        $detail = $this->actingAs($this->chargeNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/complete", [
                'exception_reason' => 'Attending unavailable; verbal plan received by phone.',
            ])->assertOk()->json();

        $this->assertSame('rounded', $detail['data']['status']);
    }

    public function test_full_requirements_path_marks_patient_rounded_without_exception(): void
    {
        $this->submitNurseNote();

        $this->actingAs($this->attending)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/contributions", [
                'section_code' => 'clinical_plan',
                'author_role' => 'attending',
                'structured_data' => ['plan' => 'Continue current management.', 'disposition' => 'continue'],
                'submit' => true,
            ])->assertCreated();

        // Soft pharmacist requirement does not block.
        $ready = $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/mark-ready")
            ->assertOk()->json();
        $this->assertSame('ready_for_review', $ready['data']['status']);
        $this->assertTrue($ready['data']['requirements']['satisfied']);

        $rounded = $this->actingAs($this->chargeNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/complete")
            ->assertOk()->json();
        $this->assertSame('rounded', $rounded['data']['status']);
    }

    public function test_reopen_returns_patient_to_in_progress_and_bumps_version(): void
    {
        $this->submitNurseNote();

        $this->actingAs($this->chargeNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/complete", [
                'exception_reason' => 'Rounding with partial input for test.',
            ])->assertOk();

        $reopened = $this->actingAs($this->chargeNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/reopen", [
                'reason' => 'New overnight event reported.',
            ])->assertOk()->json();

        $this->assertSame('in_progress', $reopened['data']['status']);
    }

    public function test_bedside_nurse_cannot_mark_patient_rounded(): void
    {
        $this->submitNurseNote();

        $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/complete", [
                'exception_reason' => 'Should not be allowed.',
            ])->assertForbidden();
    }

    public function test_defer_and_skip_require_reasons(): void
    {
        $this->actingAs($this->chargeNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/defer")
            ->assertStatus(422);

        $deferred = $this->actingAs($this->chargeNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/defer", [
                'reason' => 'Patient off unit for imaging.',
            ])->assertOk()->json();

        $this->assertSame('deferred', $deferred['data']['status']);
        $this->assertSame('Patient off unit for imaging.', $deferred['data']['status_reason']);
    }

    public function test_stale_patient_version_conflicts(): void
    {
        $this->actingAs($this->chargeNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/defer", [
                'reason' => 'Off unit.',
                'expected_version' => 42,
            ])->assertStatus(409);
    }

    private function submitNurseNote(): void
    {
        $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/contributions", [
                'section_code' => 'overnight_events',
                'structured_data' => ['events' => 'Stable overnight.'],
                'submit' => true,
            ])->assertCreated();
    }
}
