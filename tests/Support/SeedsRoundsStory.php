<?php

namespace Tests\Support;

use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Rounds\RoundTemplate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * One coherent Virtual Rounds story shared by the rounds feature tests:
 * a 4-bed unit with three active encounters (one discharge-ready, one
 * high-acuity), a charge nurse + bedside nurse assigned to the unit, an
 * attending on the unit without a mapped pivot role, and an outsider with
 * no assignment. The template requires nursing + attending inputs (hard)
 * and pharmacy (soft).
 */
trait SeedsRoundsStory
{
    protected Unit $roundsUnit;

    protected Unit $otherUnit;

    protected RoundTemplate $roundsTemplate;

    protected User $chargeNurse;

    protected User $bedsideNurse;

    protected User $attending;

    protected User $outsider;

    protected User $admin;

    /** @var array<string, Encounter> keyed by patient_ref */
    protected array $roundsEncounters = [];

    protected function seedRoundsStory(): void
    {
        config(['rounds.enabled' => true]);

        $this->roundsUnit = Unit::create([
            'name' => '5 East', 'abbreviation' => '5E', 'type' => 'med_surg',
            'staffed_bed_count' => 4, 'ratio_floor' => 4,
        ]);
        $this->otherUnit = Unit::create([
            'name' => '6 West', 'abbreviation' => '6W', 'type' => 'med_surg',
            'staffed_bed_count' => 4, 'ratio_floor' => 4,
        ]);

        $beds = [];
        foreach (range(1, 4) as $i) {
            $beds[$i] = Bed::create([
                'unit_id' => $this->roundsUnit->unit_id,
                'label' => "5E-0{$i}",
                'status' => $i <= 3 ? 'occupied' : 'available',
            ]);
        }

        $stories = [
            ['ref' => 'ROUNDS-PAT-ROUTINE', 'bed' => 1, 'acuity' => 3, 'edd' => null],
            ['ref' => 'ROUNDS-PAT-DISCHARGE', 'bed' => 2, 'acuity' => 3, 'edd' => now()->toDateString()],
            ['ref' => 'ROUNDS-PAT-ACUTE', 'bed' => 3, 'acuity' => 1, 'edd' => null],
        ];

        foreach ($stories as $story) {
            $this->roundsEncounters[$story['ref']] = Encounter::create([
                'patient_ref' => $story['ref'],
                'unit_id' => $this->roundsUnit->unit_id,
                'bed_id' => $beds[$story['bed']]->bed_id,
                'admitted_at' => now()->subDays(2),
                'expected_discharge_date' => $story['edd'],
                'acuity_tier' => $story['acuity'],
                'status' => 'active',
            ]);
        }

        $this->chargeNurse = User::factory()->create(['role' => 'user']);
        $this->chargeNurse->units()->attach($this->roundsUnit->unit_id, ['role' => 'charge']);

        $this->bedsideNurse = User::factory()->create(['role' => 'user']);
        $this->bedsideNurse->units()->attach($this->roundsUnit->unit_id, ['role' => 'bedside']);

        $this->attending = User::factory()->create(['role' => 'user']);
        $this->attending->units()->attach($this->roundsUnit->unit_id, ['role' => null]);

        $this->outsider = User::factory()->create(['role' => 'user']);
        $this->outsider->units()->attach($this->otherUnit->unit_id, ['role' => 'bedside']);

        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->roundsTemplate = RoundTemplate::create([
            'template_uuid' => (string) Str::uuid(),
            'name' => 'Test Unit Round',
            'scope_types' => '{unit}',
            'mode' => 'async',
            'required_roles' => [
                ['role_code' => 'bedside_nurse', 'sections' => ['overnight_events'], 'requirement' => 'hard'],
                ['role_code' => 'attending', 'sections' => ['clinical_plan'], 'requirement' => 'hard'],
                ['role_code' => 'pharmacist', 'sections' => ['medications'], 'requirement' => 'soft'],
            ],
            'completion_policy' => ['freshness_hours' => 24],
            'version' => 1,
            'active' => true,
        ]);
    }

    /** Create a run as the charge nurse and return the board payload. */
    protected function createRoundsRun(): array
    {
        return $this->actingAs($this->chargeNurse)
            ->postJson('/api/rounds/runs', [
                'template_uuid' => $this->roundsTemplate->template_uuid,
                'scope_type' => 'unit',
                'scope_key' => (string) $this->roundsUnit->unit_id,
            ])
            ->assertCreated()
            ->json();
    }

    /** @return array<string, mixed> the board row for one patient_ref */
    protected function boardRowFor(array $board, string $patientRef): array
    {
        foreach ($board['data']['patients'] as $row) {
            if (($row['patient_label'] ?? null) === $patientRef) {
                return $row;
            }
        }

        $this->fail("No board row for {$patientRef}");
    }
}
