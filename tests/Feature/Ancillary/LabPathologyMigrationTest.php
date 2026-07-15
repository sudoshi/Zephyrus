<?php

namespace Tests\Feature\Ancillary;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Lab\AnatomicPathologyCase;
use App\Models\Lab\BloodBankReadiness;
use App\Models\Lab\LabTestCatalog;
use App\Models\Lab\Result;
use App\Models\Lab\Specimen;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LabPathologyMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
    }

    public function test_satellite_tables_constraints_indexes_and_real_or_case_keys_exist(): void
    {
        foreach (['hosp_ref.lab_test_catalog', 'prod.lab_specimens', 'prod.lab_results', 'prod.lab_critical_values', 'prod.ap_cases', 'prod.bb_readiness'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}");
        }

        $this->assertTrue(Schema::hasColumns('prod.ap_cases', ['case_id', 'ancillary_order_id', 'stage', 'frozen_status']));
        $this->assertTrue(Schema::hasColumns('prod.bb_readiness', ['case_id', 'ancillary_order_id', 'readiness_state', 'type_screen_state', 'crossmatch_state']));
        $this->assertFalse(Schema::hasColumn('prod.ap_cases', 'or_case_id'));
        $this->assertFalse(Schema::hasColumn('prod.bb_readiness', 'or_case_id'));

        $resultColumns = DB::table('information_schema.columns')
            ->where('table_schema', 'prod')
            ->where('table_name', 'lab_results')
            ->pluck('column_name')
            ->all();
        $this->assertEmpty(array_intersect($resultColumns, ['result_value', 'result_text', 'result_narrative', 'value', 'narrative']));

        $constraints = DB::table('pg_constraint')->whereIn('conname', [
            'lab_test_catalog_decision_class_check',
            'lab_specimens_status_evidence_check',
            'lab_results_status_evidence_check',
            'lab_critical_values_state_evidence_check',
            'ap_cases_frozen_evidence_check',
            'bb_readiness_state_evidence_check',
        ])->pluck('conname')->all();
        $this->assertCount(6, $constraints);

        $indexes = DB::table('pg_indexes')->whereIn('indexname', [
            'lab_specimens_pending_collection_idx',
            'lab_specimens_pending_receipt_idx',
            'lab_results_pending_decision_idx',
            'lab_results_critical_open_idx',
            'ap_cases_stage_aging_idx',
            'ap_cases_or_frozen_readiness_idx',
            'bb_readiness_pending_or_idx',
            'bb_readiness_active_mtp_idx',
        ])->pluck('indexname')->all();
        $this->assertCount(8, $indexes);
    }

    public function test_catalog_rejects_decision_classes_outside_the_governed_vocabulary(): void
    {
        $this->expectException(QueryException::class);
        LabTestCatalog::factory()->decisionClass('patient_flow_guess')->create();
    }

    public function test_specimen_requires_a_lab_order_and_matching_encounter(): void
    {
        $specimen = Specimen::factory()->create();
        $this->assertSame('lab', $specimen->ancillaryOrder->department);
        $this->assertSame($specimen->encounter_id, $specimen->ancillaryOrder->encounter_id);

        $pathologyOrder = AncillaryOrder::factory()->pathology()->create();
        $this->expectException(QueryException::class);
        Specimen::factory()->create([
            'ancillary_order_id' => $pathologyOrder->ancillary_order_id,
            'source_id' => $pathologyOrder->source_id,
            'encounter_id' => $pathologyOrder->encounter_id,
        ]);
    }

    public function test_result_requires_its_specimen_and_catalog_to_match_a_clinical_lab_order(): void
    {
        $first = Specimen::factory()->create();
        $second = Specimen::factory()->create();

        $this->expectException(QueryException::class);
        Result::factory()->create([
            'ancillary_order_id' => $first->ancillary_order_id,
            'source_id' => $first->source_id,
            'lab_specimen_id' => $second->lab_specimen_id,
        ]);
    }

    public function test_pathology_and_blood_bank_projection_guards_reject_wrong_departments(): void
    {
        $labOrder = AncillaryOrder::factory()->lab()->create();

        try {
            AnatomicPathologyCase::factory()->create([
                'ancillary_order_id' => $labOrder->ancillary_order_id,
                'source_id' => $labOrder->source_id,
                'encounter_id' => $labOrder->encounter_id,
            ]);
            $this->fail('Anatomic pathology accepted a lab order.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        BloodBankReadiness::factory()->create([
            'ancillary_order_id' => $labOrder->ancillary_order_id,
            'source_id' => $labOrder->source_id,
            'encounter_id' => $labOrder->encounter_id,
        ]);
    }

    public function test_source_natural_keys_are_idempotency_boundaries(): void
    {
        $specimen = Specimen::factory()->create(['source_specimen_key' => 'duplicate-specimen']);
        $this->expectException(QueryException::class);
        Specimen::factory()->create([
            'source_id' => $specimen->source_id,
            'source_specimen_key' => 'duplicate-specimen',
        ]);
    }

    public function test_empty_local_satellites_can_rehearse_down_and_up_without_dropping_the_shared_order_ledger(): void
    {
        $migration = require database_path('migrations/2026_07_12_000800_create_lab_pathology_satellite_tables.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('prod.lab_results'));
        $this->assertFalse(Schema::hasTable('prod.ap_cases'));
        $this->assertFalse(Schema::hasTable('prod.bb_readiness'));
        $this->assertTrue(Schema::hasTable('prod.ancillary_orders'));

        $migration->up();
        $this->seed(AncillaryReferenceSeeder::class);
        $this->assertTrue(Schema::hasTable('prod.lab_results'));
        $this->assertTrue(Schema::hasTable('prod.ap_cases'));
        $this->assertTrue(Schema::hasTable('prod.bb_readiness'));
        $this->assertSame(9, LabTestCatalog::query()->count());
    }

    public function test_populated_satellites_refuse_destructive_down_migration(): void
    {
        Specimen::factory()->create();
        $migration = require database_path('migrations/2026_07_12_000800_create_lab_pathology_satellite_tables.php');

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
