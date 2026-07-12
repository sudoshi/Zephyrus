<?php

namespace Tests\Feature\Ancillary;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AncillarySpineMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_exposes_required_tables_constraints_indexes_and_foreign_keys(): void
    {
        foreach ([
            'prod.ancillary_orders',
            'prod.ancillary_milestones',
            'prod.ancillary_sla_definitions',
            'prod.ancillary_breaches',
            'hosp_ref.ancillary_milestone_types',
            'hosp_ref.ancillary_barrier_reasons',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing ancillary table {$table}");
        }

        $this->assertSame(1, DB::table('information_schema.views')
            ->where('table_schema', 'prod')
            ->where('table_name', 'ancillary_current_assertions')
            ->count());

        $constraintNames = DB::table('information_schema.table_constraints')
            ->whereIn('table_schema', ['prod', 'hosp_ref'])
            ->whereIn('table_name', [
                'ancillary_orders',
                'ancillary_milestones',
                'ancillary_sla_definitions',
                'ancillary_breaches',
                'ancillary_milestone_types',
                'ancillary_barrier_reasons',
            ])
            ->pluck('constraint_name');

        foreach ([
            'ancillary_orders_source_identity_unique',
            'ancillary_orders_department_work_item_check',
            'prod_ancillary_milestones_assertion_key_unique',
            'ancillary_sla_definitions_effective_range_check',
            'ancillary_sla_definitions_start_milestone_type_fk',
            'ancillary_sla_definitions_stop_milestone_type_fk',
            'ancillary_orders_current_milestone_type_fk',
            'ancillary_milestones_milestone_type_fk',
            'ancillary_breaches_lifecycle_check',
            'ancillary_barrier_reasons_category_check',
        ] as $constraint) {
            $this->assertTrue($constraintNames->contains($constraint), "Missing constraint {$constraint}");
        }

        $indexNames = DB::table('pg_indexes')
            ->whereIn('schemaname', ['prod', 'hosp_ref'])
            ->whereIn('tablename', [
                'ancillary_orders',
                'ancillary_milestones',
                'ancillary_sla_definitions',
                'ancillary_breaches',
            ])
            ->pluck('indexname');

        foreach ([
            'ancillary_orders_live_worklist_idx',
            'ancillary_orders_readiness_idx',
            'ancillary_orders_unit_worklist_idx',
            'ancillary_orders_open_idx',
            'ancillary_orders_reconciliation_key_idx',
            'ancillary_milestones_selection_idx',
            'ancillary_breaches_one_open_definition_idx',
        ] as $index) {
            $this->assertTrue($indexNames->contains($index), "Missing index {$index}");
        }

        $foreignKeyTargets = collect(DB::select(<<<'SQL'
            SELECT DISTINCT target_ns.nspname || '.' || target_rel.relname AS referenced_table
            FROM pg_constraint con
            JOIN pg_class rel ON rel.oid = con.conrelid
            JOIN pg_namespace ns ON ns.oid = rel.relnamespace
            JOIN pg_class target_rel ON target_rel.oid = con.confrelid
            JOIN pg_namespace target_ns ON target_ns.oid = target_rel.relnamespace
            WHERE ns.nspname = 'prod'
              AND rel.relname IN ('ancillary_orders', 'ancillary_milestones', 'ancillary_sla_definitions', 'ancillary_breaches')
              AND con.contype = 'f'
        SQL))->pluck('referenced_table');

        foreach ([
            'prod.encounters',
            'prod.barriers',
            'integration.sources',
            'integration.canonical_events',
            'integration.provenance_records',
            'hosp_ref.ancillary_milestone_types',
        ] as $target) {
            $this->assertTrue($foreignKeyTargets->contains($target), "Missing foreign key to {$target}");
        }
    }

    public function test_current_assertion_view_selects_precedence_then_newest_receipt_without_losing_history(): void
    {
        $fixture = $this->createOrderFixture(demoOwner: 'ancillary-demo');
        $base = now()->subHour();

        $this->insertMilestone($fixture, 'assertion-low-priority', 20, $base, $base->copy()->addMinutes(2));
        $selectedId = $this->insertMilestone($fixture, 'assertion-selected', 10, $base->copy()->addMinutes(4), $base->copy()->addMinutes(6));
        $this->insertMilestone($fixture, 'assertion-old-equal-rank', 10, $base->copy()->addMinutes(3), $base->copy()->addMinutes(5));

        $this->assertSame(3, DB::table('prod.ancillary_milestones')->where('ancillary_order_id', $fixture['orderId'])->count());

        $selected = DB::table('prod.ancillary_current_assertions')
            ->where('ancillary_order_id', $fixture['orderId'])
            ->where('milestone_code', 'RAD_ORDERED')
            ->first();

        $this->assertSame($selectedId, $selected->ancillary_milestone_id);
        $this->assertSame(3, $selected->assertion_count);
        $this->assertSame(240, $selected->disagreement_seconds);
    }

    public function test_milestone_update_is_rejected_by_database_guard(): void
    {
        $fixture = $this->createOrderFixture();
        $milestoneId = $this->insertMilestone($fixture, 'immutable-update');

        $this->expectException(QueryException::class);
        DB::table('prod.ancillary_milestones')
            ->where('ancillary_milestone_id', $milestoneId)
            ->update(['occurred_at' => now()->subDay()]);
    }

    public function test_direct_milestone_delete_is_rejected_even_with_demo_reset_setting(): void
    {
        $fixture = $this->createOrderFixture(demoOwner: 'ancillary-demo');
        $milestoneId = $this->insertMilestone($fixture, 'immutable-delete');
        DB::statement("SELECT set_config('zephyrus.allow_ancillary_demo_reset', 'on', true)");

        $this->expectException(QueryException::class);
        DB::table('prod.ancillary_milestones')->where('ancillary_milestone_id', $milestoneId)->delete();
    }

    public function test_guarded_owned_demo_parent_delete_cascades_to_milestones(): void
    {
        $fixture = $this->createOrderFixture(demoOwner: 'ancillary-demo');
        $this->insertMilestone($fixture, 'owned-demo-reset');

        DB::statement("SELECT set_config('zephyrus.allow_ancillary_demo_reset', 'on', true)");
        DB::table('prod.ancillary_orders')->where('ancillary_order_id', $fixture['orderId'])->delete();

        $this->assertDatabaseMissing('prod.ancillary_orders', ['ancillary_order_id' => $fixture['orderId']]);
        $this->assertDatabaseMissing('prod.ancillary_milestones', ['ancillary_order_id' => $fixture['orderId']]);
    }

    public function test_guarded_reset_cannot_delete_non_demo_order(): void
    {
        $fixture = $this->createOrderFixture();
        $this->insertMilestone($fixture, 'non-demo-reset');
        DB::statement("SELECT set_config('zephyrus.allow_ancillary_demo_reset', 'on', true)");

        $this->expectException(QueryException::class);
        DB::table('prod.ancillary_orders')->where('ancillary_order_id', $fixture['orderId'])->delete();
    }

    public function test_duplicate_source_order_identity_is_rejected(): void
    {
        $fixture = $this->createOrderFixture(sourceOrderKey: 'duplicate-order');

        $this->expectException(QueryException::class);
        $this->insertOrder($fixture['sourceId'], 'duplicate-order');
    }

    public function test_duplicate_assertion_key_is_rejected(): void
    {
        $fixture = $this->createOrderFixture();
        $this->insertMilestone($fixture, 'duplicate-assertion');

        $this->expectException(QueryException::class);
        $this->insertMilestone($fixture, 'duplicate-assertion');
    }

    public function test_overlapping_effective_sla_versions_are_rejected_for_same_scope(): void
    {
        $fixture = $this->createOrderFixture();
        $start = now()->startOfDay();
        $this->insertSlaDefinition($start, $start->copy()->addMonth(), 1);

        $this->expectException(QueryException::class);
        $this->insertSlaDefinition($start->copy()->addDays(10), null, 2);
    }

    public function test_adjacent_effective_sla_versions_are_allowed(): void
    {
        $this->createOrderFixture();
        $start = now()->startOfDay();
        $boundary = $start->copy()->addMonth();

        $this->insertSlaDefinition($start, $boundary, 1);
        $this->insertSlaDefinition($boundary, null, 2);

        $this->assertSame(2, DB::table('prod.ancillary_sla_definitions')->where('metric_key', 'rad.stat_order_final')->count());
    }

    public function test_duplicate_open_breach_is_rejected_but_cleared_history_is_allowed(): void
    {
        $fixture = $this->createOrderFixture();
        $milestoneId = $this->insertMilestone($fixture, 'breach-start');
        $definitionId = $this->insertSlaDefinition(now()->subDay(), null, 1);
        $this->insertOpenBreach($fixture['orderId'], $definitionId, $milestoneId);

        $this->expectException(QueryException::class);
        $this->insertOpenBreach($fixture['orderId'], $definitionId, $milestoneId);
    }

    public function test_empty_local_schema_can_rehearse_down_and_up(): void
    {
        $labMigration = require database_path('migrations/2026_07_12_000800_create_lab_pathology_satellite_tables.php');
        $radiologyMigration = require database_path('migrations/2026_07_11_000500_create_radiology_satellite_tables.php');
        $migration = require database_path('migrations/2026_07_11_000400_create_ancillary_spine_tables.php');

        $labMigration->down();
        $radiologyMigration->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('prod.ancillary_orders'));

        $migration->up();
        $radiologyMigration->up();
        $labMigration->up();
        $this->assertTrue(Schema::hasTable('prod.ancillary_orders'));
        $this->assertTrue(Schema::hasTable('prod.ancillary_milestones'));
        $this->assertTrue(Schema::hasTable('prod.rad_exams'));
        $this->assertTrue(Schema::hasTable('prod.lab_results'));
    }

    /** @return array{sourceId: int, canonicalEventId: int, orderId: int} */
    private function createOrderFixture(?string $demoOwner = null, ?string $sourceOrderKey = null): array
    {
        $this->ensureMilestoneTypes();

        $sourceId = DB::table('integration.sources')->insertGetId([
            'source_uuid' => (string) Str::uuid(),
            'source_key' => 'ancillary-test-'.Str::uuid(),
            'source_name' => 'Ancillary test source',
            'system_class' => 'radiology',
            'interface_type' => 'synthetic',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_id');

        $canonicalEventId = DB::table('integration.canonical_events')->insertGetId([
            'event_id' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'event_type' => 'ancillary.radiology.order_placed',
            'entity_type' => 'ancillary_order',
            'entity_ref' => 'test-order',
            'occurred_at' => now()->subMinutes(5),
            'received_at' => now(),
            'payload' => '{}',
            'payload_hash' => hash('sha256', (string) Str::uuid()),
            'idempotency_key' => 'canonical-'.Str::uuid(),
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'canonical_event_id');

        return [
            'sourceId' => $sourceId,
            'canonicalEventId' => $canonicalEventId,
            'orderId' => $this->insertOrder($sourceId, $sourceOrderKey ?? 'order-'.Str::uuid(), $demoOwner),
        ];
    }

    private function ensureMilestoneTypes(): void
    {
        foreach ([['RAD_ORDERED', 10], ['RAD_FINAL', 110]] as [$code, $ordinal]) {
            DB::table('hosp_ref.ancillary_milestone_types')->updateOrInsert(
                ['code' => $code],
                [
                    'department' => 'rad',
                    'label' => str_replace('_', ' ', $code),
                    'phase' => 'order_to_final',
                    'ordinal' => $ordinal,
                    'source_precedence' => '[]',
                    'process_ids' => '[]',
                    'display_metadata' => '{}',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function insertOrder(int $sourceId, string $sourceOrderKey, ?string $demoOwner = null): int
    {
        $orderedAt = now()->subMinutes(10);

        return DB::table('prod.ancillary_orders')->insertGetId([
            'order_uuid' => (string) Str::uuid(),
            'department' => 'rad',
            'work_item_type' => 'imaging_order',
            'source_id' => $sourceId,
            'source_order_key' => $sourceOrderKey,
            'patient_class' => 'emergency',
            'priority' => 'stat',
            'ordered_at' => $orderedAt,
            'current_state' => 'ordered',
            'source_cutoff_at' => $orderedAt->copy()->addMinute(),
            'demo_owner' => $demoOwner,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'ancillary_order_id');
    }

    /** @param array{sourceId: int, canonicalEventId: int, orderId: int} $fixture */
    private function insertMilestone(
        array $fixture,
        string $assertionKey,
        int $sourceRank = 100,
        mixed $occurredAt = null,
        mixed $receivedAt = null,
    ): int {
        return DB::table('prod.ancillary_milestones')->insertGetId([
            'milestone_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => $fixture['orderId'],
            'milestone_code' => 'RAD_ORDERED',
            'occurred_at' => $occurredAt ?? now()->subMinutes(5),
            'received_at' => $receivedAt ?? now(),
            'source_id' => $fixture['sourceId'],
            'canonical_event_id' => $fixture['canonicalEventId'],
            'assertion_key' => $assertionKey,
            'source_rank' => $sourceRank,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'ancillary_milestone_id');
    }

    private function insertSlaDefinition(mixed $effectiveFrom, mixed $effectiveTo, int $version): int
    {
        return DB::table('prod.ancillary_sla_definitions')->insertGetId([
            'definition_uuid' => (string) Str::uuid(),
            'department' => 'rad',
            'metric_key' => 'rad.stat_order_final',
            'label' => 'STAT imaging order to final',
            'start_milestone_code' => 'RAD_ORDERED',
            'stop_milestone_code' => 'RAD_FINAL',
            'priority' => 'stat',
            'patient_class' => null,
            'scope' => '{}',
            'statistic' => 'item_clock',
            'warning_minutes' => 90,
            'breach_minutes' => 120,
            'direction' => 'lower_is_better',
            'unit' => 'minutes',
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'version' => $version,
            'active' => true,
            'definition_text' => 'RAD_ORDERED to RAD_FINAL for STAT imaging orders.',
            'source_reference_id' => 'demo-policy',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'ancillary_sla_definition_id');
    }

    private function insertOpenBreach(int $orderId, int $definitionId, int $startAssertionId): int
    {
        return DB::table('prod.ancillary_breaches')->insertGetId([
            'breach_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => $orderId,
            'ancillary_sla_definition_id' => $definitionId,
            'status' => 'open',
            'breached_at' => now(),
            'start_assertion_id' => $startAssertionId,
            'elapsed_minutes_at_open' => 121,
            'last_evaluated_at' => now(),
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'ancillary_breach_id');
    }
}
