<?php

namespace Tests\Feature\Rounds;

use App\Models\Rounds\RoundContribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsRoundsStory;
use Tests\TestCase;

class RoundContributionTest extends TestCase
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
    }

    public function test_bedside_nurse_submits_overnight_events_via_mapped_role(): void
    {
        $detail = $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/contributions", [
                'section_code' => 'overnight_events',
                'structured_data' => ['events' => 'Slept well, afebrile overnight.'],
                'summary' => 'Quiet night.',
                'submit' => true,
            ])
            ->assertCreated()->json();

        $contribution = collect($detail['data']['contributions'])->firstWhere('status', 'submitted');
        $this->assertSame('overnight_events', $contribution['section_code']);
        $this->assertSame('bedside_nurse', $contribution['author_role']);

        // First activity nudges the patient out of queued.
        $this->assertSame('in_progress', $detail['data']['status']);

        // The bedside participant slot is satisfied.
        $board = $this->actingAs($this->chargeNurse)
            ->getJson("/api/rounds/runs/{$this->runUuid}/board")->json();
        $slot = collect($board['data']['participants'])->firstWhere('role_code', 'bedside_nurse');
        $this->assertSame('contributed', $slot['status']);
    }

    public function test_unknown_section_and_disallowed_role_are_rejected(): void
    {
        $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/contributions", [
                'section_code' => 'freeform_notes',
                'structured_data' => ['note' => 'anything'],
            ])
            ->assertStatus(422);

        // Bedside nurse may not author the clinical plan.
        $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/contributions", [
                'section_code' => 'clinical_plan',
                'structured_data' => ['plan' => 'Discharge.'],
            ])
            ->assertStatus(422);
    }

    public function test_structured_data_is_allowlisted_per_section(): void
    {
        $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/contributions", [
                'section_code' => 'overnight_events',
                'structured_data' => ['diagnosis' => 'not allowed here'],
            ])
            ->assertStatus(422);

        $this->actingAs($this->attending)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/contributions", [
                'section_code' => 'clinical_plan',
                'author_role' => 'attending',
                'structured_data' => ['disposition' => 'not_a_valid_enum'],
            ])
            ->assertStatus(422);
    }

    public function test_submitted_contribution_is_immutable_and_superseded_by_resubmission(): void
    {
        $this->actingAs($this->attending)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/contributions", [
                'section_code' => 'clinical_plan',
                'author_role' => 'attending',
                'structured_data' => ['plan' => 'Original plan.', 'disposition' => 'continue'],
                'summary' => 'Original',
                'submit' => true,
            ])->assertCreated();

        $detail = $this->actingAs($this->attending)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/contributions", [
                'section_code' => 'clinical_plan',
                'author_role' => 'attending',
                'structured_data' => ['plan' => 'Corrected plan.', 'disposition' => 'discharge_today'],
                'summary' => 'Corrected',
                'submit' => true,
            ])->assertCreated()->json();

        $plans = collect($detail['data']['contributions'])
            ->where('section_code', 'clinical_plan')
            ->values();

        $this->assertCount(2, $plans);

        $active = $plans->firstWhere('status', 'submitted');
        $superseded = $plans->firstWhere('status', 'superseded');

        $this->assertSame('Corrected', $active['summary']);
        $this->assertSame(2, $active['version']);
        $this->assertSame('Original', $superseded['summary']);
        $this->assertSame($superseded['contribution_uuid'], $active['supersedes_uuid']);

        // The original row's content was never mutated.
        $original = RoundContribution::query()
            ->where('contribution_uuid', $superseded['contribution_uuid'])->firstOrFail();
        $this->assertSame('Original plan.', $original->structured_data['plan']);
    }

    public function test_two_roles_contribute_concurrently_without_lost_updates(): void
    {
        $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/contributions", [
                'section_code' => 'overnight_events',
                'structured_data' => ['events' => 'Nurse note.'],
                'submit' => true,
            ])->assertCreated();

        $detail = $this->actingAs($this->attending)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/contributions", [
                'section_code' => 'clinical_plan',
                'author_role' => 'attending',
                'structured_data' => ['plan' => 'Attending plan.'],
                'submit' => true,
            ])->assertCreated()->json();

        $submitted = collect($detail['data']['contributions'])->where('status', 'submitted');
        $this->assertCount(2, $submitted);
        $this->assertEqualsCanonicalizing(
            ['overnight_events', 'clinical_plan'],
            $submitted->pluck('section_code')->all(),
        );
    }

    public function test_outsider_cannot_contribute(): void
    {
        $this->actingAs($this->outsider)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/contributions", [
                'section_code' => 'overnight_events',
                'structured_data' => ['events' => 'Should not land.'],
                'submit' => true,
            ])->assertForbidden();
    }

    public function test_draft_then_submit_and_withdraw(): void
    {
        // Draft (no submit flag) — mutable, updated in place.
        $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/contributions", [
                'section_code' => 'overnight_events',
                'structured_data' => ['events' => 'Draft v1'],
            ])->assertCreated();

        $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/patients/{$this->patientUuid}/contributions", [
                'section_code' => 'overnight_events',
                'structured_data' => ['events' => 'Draft v2'],
            ])->assertCreated();

        $this->assertSame(1, RoundContribution::query()->where('status', 'draft')->count());

        $draft = RoundContribution::query()->where('status', 'draft')->firstOrFail();
        $this->assertSame('Draft v2', $draft->structured_data['events']);

        $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/contributions/{$draft->contribution_uuid}/submit")
            ->assertOk();

        $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/contributions/{$draft->contribution_uuid}/withdraw", [
                'reason' => 'Entered on the wrong patient.',
            ])->assertOk();

        $this->assertSame('withdrawn', $draft->fresh()->status);
    }
}
