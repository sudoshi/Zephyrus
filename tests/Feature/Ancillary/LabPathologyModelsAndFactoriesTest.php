<?php

namespace Tests\Feature\Ancillary;

use App\Models\Lab\AnatomicPathologyCase;
use App\Models\Lab\BloodBankReadiness;
use App\Models\Lab\CriticalValue;
use App\Models\Lab\LabTestCatalog;
use App\Models\Lab\Result;
use App\Models\Lab\Specimen;
use Database\Seeders\AncillaryReferenceSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LabPathologyModelsAndFactoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
    }

    public function test_reference_seeder_is_idempotent_and_covers_governed_decision_classes(): void
    {
        $uuids = LabTestCatalog::query()->orderBy('catalog_key')->pluck('catalog_uuid', 'catalog_key');
        $this->seed(AncillaryReferenceSeeder::class);

        $this->assertCount(9, LabTestCatalog::query()->get());
        $this->assertSame($uuids->all(), LabTestCatalog::query()->orderBy('catalog_key')->pluck('catalog_uuid', 'catalog_key')->all());
        $this->assertSame(
            ['discharge_gate', 'ed_disposition', 'none', 'or_gate'],
            LabTestCatalog::query()->distinct()->orderBy('decision_class')->pluck('decision_class')->all(),
        );
        $this->assertSame(4, LabTestCatalog::query()->whereNotNull('loinc_code')->count());
    }

    public function test_specimen_factories_represent_collection_receipt_rejection_and_recollect_lineage(): void
    {
        $pending = Specimen::factory()->pendingCollection()->create();
        $inTransit = Specimen::factory()->inTransit()->create();
        $rejected = Specimen::factory()->rejected('clotted')->create();
        $recollect = Specimen::factory()->recollectOf($rejected)->create([
            'ancillary_order_id' => $rejected->ancillary_order_id,
            'source_id' => $rejected->source_id,
            'encounter_id' => $rejected->encounter_id,
        ]);

        $this->assertTrue(Specimen::query()->pendingCollection()->whereKey($pending->getKey())->exists());
        $this->assertTrue(Specimen::query()->pendingReceipt()->whereKey($inTransit->getKey())->exists());
        $this->assertTrue(Specimen::query()->rejected()->whereKey($rejected->getKey())->exists());
        $this->assertSame($rejected->lab_specimen_id, $recollect->parent->lab_specimen_id);
        $this->assertTrue($rejected->recollects->contains($recollect));
        $this->assertSame('recollect', $recollect->metadata['collection_reason']);
    }

    public function test_result_factories_represent_auto_verification_corrections_critical_values_and_microbiology_stages(): void
    {
        $auto = Result::factory()->autoVerified()->create();
        $critical = Result::factory()->critical()->create();
        $correction = Result::factory()->corrected($auto)->create();
        $callback = CriticalValue::factory()->acknowledged()->create([
            'lab_result_id' => $critical->lab_result_id,
            'source_id' => $critical->source_id,
        ]);
        $microStages = collect(['preliminary', 'organism_identification', 'susceptibility', 'final'])
            ->map(fn (string $stage): Result => Result::factory()->microbiologyStage($stage)->create());

        $this->assertTrue($auto->auto_verified);
        $this->assertTrue(Result::query()->critical()->whereKey($critical->getKey())->exists());
        $this->assertSame($auto->lab_result_id, $correction->parent->lab_result_id);
        $this->assertTrue($auto->corrections->contains($correction));
        $this->assertSame('acknowledged', $callback->callback_state);
        $this->assertFalse(CriticalValue::query()->open()->whereKey($callback->getKey())->exists());
        $this->assertSame(['preliminary', 'organism_identification', 'susceptibility', 'final'], $microStages->pluck('result_stage')->all());
        $this->assertSame(4, Result::query()->microbiology()->whereKey($microStages->pluck('lab_result_id'))->count());
        $this->assertInstanceOf(DateTimeImmutable::class, $auto->resulted_at);
        $this->assertIsArray($auto->metadata);
    }

    public function test_pathology_frozen_section_and_blood_bank_states_use_the_real_or_case_id(): void
    {
        $caseId = $this->createMinimalOperatingCase();
        $frozen = AnatomicPathologyCase::factory()->frozenResulted($caseId)->create();
        $crossmatch = BloodBankReadiness::factory()->crossmatchReady($caseId)->create();
        $mtp = BloodBankReadiness::factory()->mtpActive($caseId)->create();

        $this->assertSame($caseId, $frozen->operatingCase->case_id);
        $this->assertSame('resulted', $frozen->frozen_status);
        $this->assertSame($caseId, $crossmatch->operatingCase->case_id);
        $this->assertTrue(BloodBankReadiness::query()->ready()->whereKey($crossmatch->getKey())->exists());
        $this->assertTrue(BloodBankReadiness::query()->mtpActive()->whereKey($mtp->getKey())->exists());
        $this->assertTrue($frozen->ancillaryOrder->anatomicPathologyCase->is($frozen));
        $this->assertTrue($crossmatch->ancillaryOrder->bloodBankReadiness->is($crossmatch));
    }

    private function createMinimalOperatingCase(): int
    {
        $locationId = (int) DB::table('prod.locations')->insertGetId([
            'name' => 'L-1 Campus',
            'abbreviation' => 'L1',
            'type' => 'hospital',
            'pos_type' => 'hospital',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'location_id');
        $roomId = (int) DB::table('prod.rooms')->insertGetId([
            'location_id' => $locationId,
            'name' => 'L-1 OR',
            'type' => 'OR',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'room_id');
        $specialtyId = (int) DB::table('prod.specialties')->insertGetId(['name' => 'L-1 Specialty', 'code' => 'L1SPEC', 'created_at' => now(), 'updated_at' => now()], 'specialty_id');
        $providerId = (int) DB::table('prod.providers')->insertGetId([
            'npi' => '9999999999',
            'name' => 'L-1 Surgeon',
            'specialty_id' => $specialtyId,
            'type' => 'surgeon',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'provider_id');
        $serviceId = (int) DB::table('prod.services')->insertGetId(['name' => 'L-1 Surgery', 'code' => 'L1SURG', 'created_at' => now(), 'updated_at' => now()], 'service_id');
        $statusId = (int) DB::table('prod.case_statuses')->insertGetId(['name' => 'Scheduled', 'code' => 'SCHED', 'created_at' => now(), 'updated_at' => now()], 'status_id');
        $asaId = (int) DB::table('prod.asa_ratings')->insertGetId(['name' => 'ASA II', 'code' => 'ASA2', 'created_at' => now(), 'updated_at' => now()], 'asa_id');
        $caseTypeId = (int) DB::table('prod.case_types')->insertGetId(['name' => 'Elective', 'code' => 'ELECTIVE', 'created_at' => now(), 'updated_at' => now()], 'case_type_id');
        $caseClassId = (int) DB::table('prod.case_classes')->insertGetId(['name' => 'Inpatient', 'code' => 'IP', 'created_at' => now(), 'updated_at' => now()], 'case_class_id');
        $patientClassId = (int) DB::table('prod.patient_classes')->insertGetId(['name' => 'Inpatient', 'code' => 'IP', 'created_at' => now(), 'updated_at' => now()], 'patient_class_id');

        return (int) DB::table('prod.or_cases')->insertGetId([
            'patient_id' => 'L1-PATIENT',
            'surgery_date' => today(),
            'room_id' => $roomId,
            'location_id' => $locationId,
            'primary_surgeon_id' => $providerId,
            'case_service_id' => $serviceId,
            'scheduled_start_time' => now()->addHour(),
            'scheduled_duration' => 90,
            'record_create_date' => now(),
            'status_id' => $statusId,
            'asa_rating_id' => $asaId,
            'case_type_id' => $caseTypeId,
            'case_class_id' => $caseClassId,
            'patient_class_id' => $patientClassId,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'case_id');
    }
}
