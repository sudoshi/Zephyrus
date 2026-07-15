<?php

namespace Tests\Feature\Ancillary;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Pharmacy\AdcTransaction;
use App\Models\Pharmacy\Administration;
use App\Models\Pharmacy\DischargeQueueItem;
use App\Models\Pharmacy\FormularyItem;
use App\Models\Pharmacy\MedicationOrder;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PharmacyMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AncillaryReferenceSeeder::class);
    }

    public function test_satellite_tables_constraints_and_indexes_exist(): void
    {
        foreach ([
            'hosp_ref.rx_formulary',
            'prod.rx_orders',
            'prod.rx_verifications',
            'prod.rx_preps',
            'prod.rx_dispenses',
            'prod.rx_administrations',
            'prod.adc_stations',
            'prod.adc_transactions',
            'prod.rx_discharge_queue',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}");
        }

        $this->assertTrue(Schema::hasColumns('prod.rx_orders', ['ancillary_order_id', 'rxnorm_cui', 'ndc_code', 'terminology_status', 'clock_class', 'preparation_branch', 'is_controlled', 'is_hazardous', 'on_shortage']));
        $this->assertTrue(Schema::hasColumns('prod.rx_administrations', ['source_cutoff_at', 'import_batch_key']));
        $this->assertTrue(Schema::hasColumns('prod.rx_discharge_queue', ['pipeline_status', 'status_changed_at', 'planned_discharge_at']));

        $constraints = DB::table('pg_constraint')->whereIn('conname', [
            'rx_formulary_terminology_evidence_check',
            'rx_orders_clock_class_check',
            'rx_orders_prep_branch_check',
            'rx_orders_terminology_evidence_check',
            'rx_orders_status_evidence_check',
            'rx_verifications_state_evidence_check',
            'rx_preps_state_evidence_check',
            'rx_dispenses_status_evidence_check',
            'rx_administrations_cutoff_order_check',
            'rx_administrations_import_batch_check',
            'adc_transactions_type_check',
            'adc_transactions_discrepancy_key_check',
            'rx_discharge_queue_status_check',
            'rx_discharge_queue_status_evidence_check',
        ])->pluck('conname')->all();
        $this->assertCount(14, $constraints);

        $indexes = DB::table('pg_indexes')->whereIn('indexname', [
            'rx_orders_source_identity_unique',
            'rx_orders_open_stat_idx',
            'rx_orders_shortage_open_idx',
            'rx_orders_controlled_idx',
            'rx_verifications_open_queue_idx',
            'rx_preps_active_work_idx',
            'rx_dispenses_pending_delivery_idx',
            'rx_administrations_source_version_unique',
            'rx_administrations_import_batch_idx',
            'adc_transactions_station_rollup_idx',
            'adc_transactions_unit_rollup_idx',
            'adc_transactions_order_link_idx',
            'adc_transactions_open_discrepancy_idx',
            'adc_transactions_stockout_idx',
            'rx_discharge_queue_pipeline_idx',
            'rx_discharge_queue_pending_candidate_idx',
        ])->pluck('indexname')->all();
        $this->assertCount(16, $indexes);
    }

    public function test_no_table_exposes_individual_risk_or_actor_attribution_columns(): void
    {
        foreach (['rx_orders', 'rx_administrations', 'adc_transactions', 'adc_stations'] as $table) {
            $columns = DB::table('information_schema.columns')
                ->where('table_schema', 'prod')
                ->where('table_name', $table)
                ->pluck('column_name')
                ->all();

            $this->assertEmpty(
                array_intersect($columns, ['user_id', 'staff_id', 'nurse_ref', 'actor_ref', 'user_ref', 'risk_score', 'diversion_score', 'diversion_risk', 'risk_rank']),
                "prod.{$table} must not carry individual attribution or risk-score columns",
            );
        }
    }

    public function test_rx_orders_require_a_pharmacy_order_and_matching_encounter(): void
    {
        $order = MedicationOrder::factory()->create();
        $this->assertSame('rx', $order->ancillaryOrder->department);
        $this->assertSame($order->encounter_id, $order->ancillaryOrder->encounter_id);

        $labOrder = AncillaryOrder::factory()->lab()->create();
        $this->expectException(QueryException::class);
        MedicationOrder::factory()->create([
            'ancillary_order_id' => $labOrder->ancillary_order_id,
            'source_id' => $labOrder->source_id,
            'encounter_id' => $labOrder->encounter_id,
        ]);
    }

    public function test_terminology_codes_may_both_be_null_only_under_the_explicit_unmapped_state(): void
    {
        $mapped = MedicationOrder::factory()->create();
        $this->assertSame('mapped', $mapped->terminology_status);
        $this->assertNotNull($mapped->rxnorm_cui);

        $unmapped = MedicationOrder::factory()->unmappedLocal()->create();
        $this->assertNull($unmapped->rxnorm_cui);
        $this->assertNull($unmapped->ndc_code);
        $this->assertSame('unmapped_local', $unmapped->terminology_status);

        $this->assertDatabaseRejects(
            fn () => MedicationOrder::factory()->create([
                'rxnorm_cui' => null,
                'ndc_code' => null,
                'terminology_status' => 'mapped',
            ]),
            'A mapped medication order accepted null RxNorm and NDC codes.',
        );

        $this->assertDatabaseRejects(
            fn () => MedicationOrder::factory()->create(['terminology_status' => 'unmapped_local']),
            'An unmapped medication order accepted a populated RxNorm code.',
        );
    }

    public function test_administration_rows_cannot_omit_source_cutoff_or_import_identity(): void
    {
        $this->assertDatabaseRejects(
            fn () => Administration::factory()->create(['source_cutoff_at' => null]),
            'An administration row accepted a null source cutoff.',
        );

        $this->assertDatabaseRejects(
            fn () => Administration::factory()->create(['import_batch_key' => null]),
            'An administration row accepted a null import batch identity.',
        );

        $this->assertDatabaseRejects(
            fn () => Administration::factory()->create(['import_batch_key' => '   ']),
            'An administration row accepted a blank import batch identity.',
        );
    }

    public function test_adc_transaction_types_are_governed_and_discrepancies_carry_pairing_keys(): void
    {
        $stockout = AdcTransaction::factory()->stockout()->create();
        $this->assertSame('stockout', $stockout->transaction_type);

        $this->assertDatabaseRejects(
            fn () => AdcTransaction::factory()->create(['transaction_type' => 'diversion_flag']),
            'adc_transactions accepted an ungoverned transaction type.',
        );

        $this->assertDatabaseRejects(
            fn () => AdcTransaction::factory()->create([
                'transaction_type' => 'discrepancy_open',
                'discrepancy_key' => null,
            ]),
            'A controlled discrepancy was accepted without a pairing key.',
        );
    }

    public function test_discharge_queue_requires_a_discharge_clock_order_and_status_evidence(): void
    {
        $routineOrder = MedicationOrder::factory()->create();

        $this->assertDatabaseRejects(
            fn () => DischargeQueueItem::factory()->create([
                'rx_order_id' => $routineOrder->rx_order_id,
                'source_id' => $routineOrder->source_id,
                'encounter_id' => $routineOrder->encounter_id,
            ]),
            'rx_discharge_queue accepted a non-discharge medication order.',
        );

        $this->assertDatabaseRejects(
            fn () => DischargeQueueItem::factory()->create([
                'pipeline_status' => 'ready',
                'ready_at' => null,
            ]),
            'rx_discharge_queue accepted a ready status without ready evidence.',
        );
    }

    public function test_source_natural_keys_are_idempotency_boundaries(): void
    {
        $order = MedicationOrder::factory()->create(['source_order_key' => 'duplicate-rx-order']);

        $this->assertDatabaseRejects(
            fn () => MedicationOrder::factory()->create([
                'source_id' => $order->source_id,
                'source_order_key' => 'duplicate-rx-order',
            ]),
            'rx_orders accepted a duplicate source natural key.',
        );

        $administration = Administration::factory()->create();
        $corrected = Administration::factory()->correctionOf($administration)->create();
        $this->assertSame($administration->source_administration_key, $corrected->source_administration_key);

        $this->assertDatabaseRejects(
            fn () => Administration::factory()->create([
                'rx_order_id' => $administration->rx_order_id,
                'source_id' => $administration->source_id,
                'source_administration_key' => $administration->source_administration_key,
                'source_row_version' => $administration->source_row_version,
            ]),
            'rx_administrations accepted a duplicate source row version.',
        );
    }

    public function test_empty_local_satellites_can_rehearse_down_and_up_without_dropping_the_shared_order_ledger(): void
    {
        $migration = require database_path('migrations/2026_07_13_000900_create_pharmacy_satellite_tables.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('prod.rx_orders'));
        $this->assertFalse(Schema::hasTable('prod.adc_transactions'));
        $this->assertFalse(Schema::hasTable('prod.rx_discharge_queue'));
        $this->assertFalse(Schema::hasTable('hosp_ref.rx_formulary'));
        $this->assertTrue(Schema::hasTable('prod.ancillary_orders'));
        $this->assertTrue(Schema::hasTable('hosp_ref.lab_test_catalog'));

        $migration->up();
        $this->seed(AncillaryReferenceSeeder::class);
        $this->assertTrue(Schema::hasTable('prod.rx_orders'));
        $this->assertTrue(Schema::hasTable('prod.adc_transactions'));
        $this->assertTrue(Schema::hasTable('prod.rx_discharge_queue'));
        $this->assertSame(9, FormularyItem::query()->count());
    }

    public function test_populated_satellites_refuse_destructive_down_migration(): void
    {
        MedicationOrder::factory()->create();
        $migration = require database_path('migrations/2026_07_13_000900_create_pharmacy_satellite_tables.php');

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    /**
     * PostgreSQL poisons the wrapping test transaction after any failed
     * statement, so every expected rejection runs inside its own savepoint.
     */
    private function assertDatabaseRejects(callable $attempt, string $message): void
    {
        try {
            DB::transaction($attempt);
            $this->fail($message);
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
