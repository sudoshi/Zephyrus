<?php

namespace Tests\Feature\Mobile;

use App\Models\Encounter;
use App\Models\Unit;
use App\Services\Mobile\Demo\HummingbirdReferencePatientProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HummingbirdReferencePatientProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_dry_run_by_default_and_commit_is_idempotent(): void
    {
        $unit = $this->unit('Reference Unit');

        $this->artisan('hummingbird:seed-reference-patient', [
            '--unit-id' => $unit->unit_id,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('prod.encounters', [
            'patient_ref' => HummingbirdReferencePatientProvisioner::DEFAULT_PATIENT_REF,
        ]);

        $this->artisan('hummingbird:seed-reference-patient', [
            '--unit-id' => $unit->unit_id,
            '--commit' => true,
        ])->assertSuccessful();
        $first = Encounter::query()
            ->where('patient_ref', HummingbirdReferencePatientProvisioner::DEFAULT_PATIENT_REF)
            ->sole();

        $this->artisan('hummingbird:seed-reference-patient', [
            '--unit-id' => $unit->unit_id,
            '--commit' => true,
        ])->assertSuccessful();

        $this->assertSame(1, Encounter::query()
            ->where('patient_ref', HummingbirdReferencePatientProvisioner::DEFAULT_PATIENT_REF)
            ->count());
        $this->assertSame($first->encounter_id, Encounter::query()
            ->where('patient_ref', HummingbirdReferencePatientProvisioner::DEFAULT_PATIENT_REF)
            ->value('encounter_id'));
        $this->assertDatabaseHas('prod.encounters', [
            'encounter_id' => $first->encounter_id,
            'unit_id' => $unit->unit_id,
            'bed_id' => null,
            'status' => 'active',
            'is_deleted' => false,
            'created_by' => HummingbirdReferencePatientProvisioner::CREATED_BY,
        ]);
    }

    public function test_provisioner_rejects_non_synthetic_or_foreign_owned_patient_references(): void
    {
        $unit = $this->unit('Safety Unit');

        $this->artisan('hummingbird:seed-reference-patient', [
            '--unit-id' => $unit->unit_id,
            '--patient-ref' => 'real-looking-reference',
            '--commit' => true,
        ])->assertFailed();

        Encounter::create([
            'patient_ref' => 'demo-foreign-owned-reference',
            'unit_id' => $unit->unit_id,
            'acuity_tier' => 2,
            'status' => 'active',
            'created_by' => 'external-source',
            'is_deleted' => false,
        ]);

        $this->artisan('hummingbird:seed-reference-patient', [
            '--unit-id' => $unit->unit_id,
            '--patient-ref' => 'demo-foreign-owned-reference',
            '--commit' => true,
        ])->assertFailed();

        $this->assertDatabaseHas('prod.encounters', [
            'patient_ref' => 'demo-foreign-owned-reference',
            'created_by' => 'external-source',
        ]);
    }

    private function unit(string $name): Unit
    {
        return Unit::create([
            'name' => $name,
            'abbreviation' => str($name)->upper()->replace(' ', '')->limit(8, '')->toString(),
            'type' => 'med_surg',
            'staffed_bed_count' => 8,
            'ratio_floor' => 4,
            'is_deleted' => false,
        ]);
    }
}
