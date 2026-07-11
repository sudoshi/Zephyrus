<?php

namespace Tests\Feature\Rounds;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsRoundsStory;
use Tests\TestCase;

class RoundRunLifecycleTest extends TestCase
{
    use RefreshDatabase, SeedsRoundsStory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoundsStory();
    }

    public function test_feature_flag_off_returns_404(): void
    {
        config(['rounds.enabled' => false]);

        $this->actingAs($this->chargeNurse)->getJson('/api/rounds/templates')->assertNotFound();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/rounds/templates')->assertUnauthorized();
    }

    public function test_charge_nurse_creates_run_with_cohort_from_active_census(): void
    {
        $board = $this->createRoundsRun();

        $this->assertSame('draft', $board['data']['run']['status']);
        $this->assertCount(3, $board['data']['patients']);
        $this->assertNotNull($board['meta']['source_cutoff_at']);
        $this->assertSame(1, $board['meta']['version']);
        $this->assertSame('detail', $board['meta']['lens']);

        // Role slots: 1 bedside (assigned) + 1 attending (unassigned) + 1 pharmacist (unassigned).
        $roles = array_column($board['data']['participants'], 'role_code');
        sort($roles);
        $this->assertSame(['attending', 'bedside_nurse', 'pharmacist'], $roles);

        // Explainable priority: acute patient in band 2, discharge-ready in band 3.
        $this->assertSame(2, $this->boardRowFor($board, 'ROUNDS-PAT-ACUTE')['priority_band']);
        $this->assertSame(3, $this->boardRowFor($board, 'ROUNDS-PAT-DISCHARGE')['priority_band']);
        $acuteReasons = array_column($this->boardRowFor($board, 'ROUNDS-PAT-ACUTE')['priority_reasons'], 'code');
        $this->assertContains('time_critical_acuity', $acuteReasons);
    }

    public function test_outsider_gets_403_without_patient_identifiers(): void
    {
        $board = $this->createRoundsRun();
        $runUuid = $board['data']['run']['run_uuid'];

        $response = $this->actingAs($this->outsider)->getJson("/api/rounds/runs/{$runUuid}/board");
        $response->assertForbidden();

        $body = $response->getContent();
        $this->assertStringNotContainsString('ROUNDS-PAT', $body);
        $this->assertStringNotContainsString('5E-0', $body);
    }

    public function test_outsider_cannot_start_run_on_foreign_unit(): void
    {
        $this->actingAs($this->outsider)->postJson('/api/rounds/runs', [
            'template_uuid' => $this->roundsTemplate->template_uuid,
            'scope_type' => 'unit',
            'scope_key' => (string) $this->roundsUnit->unit_id,
        ])->assertForbidden();
    }

    public function test_run_lifecycle_start_pause_resume_complete_with_exception(): void
    {
        $board = $this->createRoundsRun();
        $runUuid = $board['data']['run']['run_uuid'];
        $actor = $this->chargeNurse;

        $this->actingAs($actor)->postJson("/api/rounds/runs/{$runUuid}/start")
            ->assertOk()->assertJsonPath('data.run.status', 'active');

        $this->actingAs($actor)->postJson("/api/rounds/runs/{$runUuid}/pause")
            ->assertOk()->assertJsonPath('data.run.status', 'paused');

        // Invalid transition: cannot complete a paused run.
        $this->actingAs($actor)->postJson("/api/rounds/runs/{$runUuid}/complete")
            ->assertStatus(409);

        $this->actingAs($actor)->postJson("/api/rounds/runs/{$runUuid}/resume")
            ->assertOk()->assertJsonPath('data.run.status', 'active');

        // Patients still queued: completion without an exception is a policy error.
        $this->actingAs($actor)->postJson("/api/rounds/runs/{$runUuid}/complete")
            ->assertStatus(422);

        $this->actingAs($actor)->postJson("/api/rounds/runs/{$runUuid}/complete", [
            'exception_reason' => 'End of shift; remaining patients handed to day team.',
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonPath('data.run.completion_exception.reason', 'End of shift; remaining patients handed to day team.');
    }

    public function test_bedside_nurse_cannot_lead_run_lifecycle(): void
    {
        $board = $this->createRoundsRun();
        $runUuid = $board['data']['run']['run_uuid'];

        $this->actingAs($this->bedsideNurse)
            ->postJson("/api/rounds/runs/{$runUuid}/start")
            ->assertForbidden();
    }

    public function test_create_run_is_idempotent_by_key(): void
    {
        $key = 'create-run-'.uniqid();

        $first = $this->actingAs($this->chargeNurse)
            ->postJson('/api/rounds/runs', [
                'template_uuid' => $this->roundsTemplate->template_uuid,
                'scope_type' => 'unit',
                'scope_key' => (string) $this->roundsUnit->unit_id,
            ], ['Idempotency-Key' => $key])
            ->assertCreated()->json('data.run.run_uuid');

        $second = $this->actingAs($this->chargeNurse)
            ->postJson('/api/rounds/runs', [
                'template_uuid' => $this->roundsTemplate->template_uuid,
                'scope_type' => 'unit',
                'scope_key' => (string) $this->roundsUnit->unit_id,
            ], ['Idempotency-Key' => $key])
            ->assertCreated()->json('data.run.run_uuid');

        $this->assertSame($first, $second);
        $this->assertSame(1, \App\Models\Rounds\RoundRun::query()->count());
    }

    public function test_reconcile_suggests_new_admission_without_rewriting_cohort(): void
    {
        $board = $this->createRoundsRun();
        $runUuid = $board['data']['run']['run_uuid'];

        $newEncounter = \App\Models\Encounter::create([
            'patient_ref' => 'ROUNDS-PAT-NEW',
            'unit_id' => $this->roundsUnit->unit_id,
            'admitted_at' => now(),
            'acuity_tier' => 2,
            'status' => 'active',
        ]);

        // Suggestion only — the cohort must not change.
        $suggestions = $this->actingAs($this->chargeNurse)
            ->postJson("/api/rounds/runs/{$runUuid}/cohort/reconcile")
            ->assertOk()->json('data.suggestions');

        $this->assertCount(1, $suggestions['add']);
        $this->assertSame($newEncounter->encounter_id, $suggestions['add'][0]['prod_encounter_id']);
        $this->assertSame(3, \App\Models\Rounds\RoundPatient::query()->count());

        // Applying the suggestion enrolls the patient and bumps the queue version.
        $updated = $this->actingAs($this->chargeNurse)
            ->postJson("/api/rounds/runs/{$runUuid}/cohort/reconcile", [
                'add' => [$newEncounter->encounter_id],
                'reason' => 'Admitted after cutoff',
            ])
            ->assertOk()->json();

        $this->assertCount(4, $updated['data']['patients']);
        $this->assertGreaterThan(1, $updated['meta']['version']);
    }
}
