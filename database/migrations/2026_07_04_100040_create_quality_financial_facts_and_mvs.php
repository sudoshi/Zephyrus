<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zephyrus 2.0 P7 (WS-5) — the Quality / Service-Line / Financial fact tables
 * + the three MTD materialized views that retire those domains' demo stubs.
 *
 * DELIBERATELY separate from prod.encounters: the DRG/cost/observation facts
 * live in a dedicated prod.discharge_facts ledger, NOT as columns on
 * prod.encounters. prod.encounters is the shared census/outcomes spine (live
 * readmission, O:E LOS, discharge-before-noon all scan it), and bulk-seeding
 * MTD discharges onto it would silently distort those live tiles. A billing/
 * DRG ledger is a real, distinct source anyway.
 *
 * The MVs emit (metric_key, value) rows with a UNIQUE INDEX on metric_key —
 * mandatory for REFRESH MATERIALIZED VIEW CONCURRENTLY (Part II.1#7), which
 * the hourly job uses so the wall never blocks on a refresh.
 */
return new class extends Migration
{
    use SafeMigration;

    public function up(): void
    {
        if (! Schema::hasTable('prod.quality_events')) {
            Schema::create('prod.quality_events', function (Blueprint $table) {
                $table->id('quality_event_id');
                $table->uuid('event_uuid')->unique();
                $table->string('event_type', 60); // clabsi|cauti|…|sepsis_3hr|hand_hygiene|fall|…
                $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id')->nullOnDelete();
                $table->timestamp('occurred_at');
                $table->integer('numerator')->default(0);   // infections / compliant obs / falls
                $table->integer('denominator')->default(0); // eligible observations (rate metrics)
                $table->integer('patient_days')->default(0); // exposure for per-1000 rates
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->boolean('is_deleted')->default(false);

                $table->index(['event_type', 'occurred_at']);
                $table->index('occurred_at');
            });
        }

        if (! Schema::hasTable('prod.discharge_facts')) {
            Schema::create('prod.discharge_facts', function (Blueprint $table) {
                $table->id('discharge_fact_id');
                $table->uuid('fact_uuid')->unique();
                $table->string('patient_ref', 120);
                $table->foreignId('unit_id')->nullable()->constrained('prod.units', 'unit_id')->nullOnDelete();
                $table->string('service_line', 80)->nullable();
                $table->decimal('drg_weight', 8, 3)->nullable();
                $table->decimal('total_cost', 12, 2)->nullable();
                $table->boolean('is_observation')->default(false);
                $table->timestamp('discharged_at');
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->boolean('is_deleted')->default(false);

                $table->index(['discharged_at', 'is_deleted']);
                $table->index('service_line');
            });
        }

        $this->createMvs();
    }

    public function down(): void
    {
        foreach (['ops.mv_hai_ledger', 'ops.mv_service_line_los', 'ops.mv_cost_center_productivity'] as $mv) {
            DB::statement("DROP MATERIALIZED VIEW IF EXISTS {$mv}");
        }

        if ($this->isLocalEnvironment()) {
            $this->safeDropIfExists('prod.discharge_facts');
            $this->safeDropIfExists('prod.quality_events');
        }
    }

    private function createMvs(): void
    {
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS ops.mv_hai_ledger');
        DB::statement("
            CREATE MATERIALIZED VIEW ops.mv_hai_ledger AS
            SELECT 'quality.' || event_type AS metric_key, SUM(numerator)::numeric AS value
              FROM prod.quality_events
             WHERE is_deleted = false
               AND event_type IN ('clabsi','cauti','cdiff','ssi','mrsa','vap','hapi','rapid_response')
               AND occurred_at >= date_trunc('month', now())
             GROUP BY event_type
            UNION ALL
            SELECT 'quality.' || event_type, ROUND(100.0 * SUM(numerator) / NULLIF(SUM(denominator), 0), 1)
              FROM prod.quality_events
             WHERE is_deleted = false
               AND event_type IN ('sepsis_3hr','sepsis_6hr','hand_hygiene','med_rec')
               AND occurred_at >= date_trunc('month', now())
             GROUP BY event_type
            UNION ALL
            SELECT 'quality.falls_rate', ROUND(1000.0 * SUM(numerator) / NULLIF(SUM(patient_days), 0), 1)
              FROM prod.quality_events
             WHERE is_deleted = false AND event_type = 'fall'
               AND occurred_at >= date_trunc('month', now())
            WITH DATA
        ");
        DB::statement('CREATE UNIQUE INDEX mv_hai_ledger_key ON ops.mv_hai_ledger (metric_key)');

        DB::statement('DROP MATERIALIZED VIEW IF EXISTS ops.mv_service_line_los');
        DB::statement("
            CREATE MATERIALIZED VIEW ops.mv_service_line_los AS
            SELECT 'service.cmi' AS metric_key, ROUND(AVG(drg_weight), 2) AS value
              FROM prod.discharge_facts
             WHERE is_deleted = false AND drg_weight IS NOT NULL
               AND discharged_at >= date_trunc('month', now())
            UNION ALL
            SELECT 'service.observation_rate',
                   ROUND(100.0 * COUNT(*) FILTER (WHERE is_observation) / NULLIF(COUNT(*), 0), 1)
              FROM prod.discharge_facts
             WHERE is_deleted = false AND discharged_at >= date_trunc('month', now())
            UNION ALL
            SELECT 'service.discharges_mtd', COUNT(*)::numeric
              FROM prod.discharge_facts
             WHERE is_deleted = false AND discharged_at >= date_trunc('month', now())
            WITH DATA
        ");
        DB::statement('CREATE UNIQUE INDEX mv_service_line_los_key ON ops.mv_service_line_los (metric_key)');

        DB::statement('DROP MATERIALIZED VIEW IF EXISTS ops.mv_cost_center_productivity');
        DB::statement("
            CREATE MATERIALIZED VIEW ops.mv_cost_center_productivity AS
            SELECT 'financial.worked_per_uos' AS metric_key,
                   ROUND(SUM(worked_hours) / NULLIF(SUM(census_days), 0), 2) AS value
              FROM prod.workforce_actuals
             WHERE is_deleted = false AND work_date >= date_trunc('month', now())::date
            UNION ALL
            SELECT 'financial.productivity',
                   ROUND(100.0 * SUM(target_hours) / NULLIF(SUM(worked_hours), 0), 1)
              FROM prod.workforce_actuals
             WHERE is_deleted = false AND work_date >= date_trunc('month', now())::date
            UNION ALL
            SELECT 'financial.overtime',
                   ROUND(100.0 * SUM(overtime_hours) / NULLIF(SUM(worked_hours), 0), 1)
              FROM prod.workforce_actuals
             WHERE is_deleted = false AND work_date >= date_trunc('month', now())::date
            UNION ALL
            SELECT 'financial.cost_per_case',
                   ROUND(AVG(total_cost) / 1000.0, 1)
              FROM prod.discharge_facts
             WHERE is_deleted = false AND total_cost IS NOT NULL
               AND discharged_at >= date_trunc('month', now())
            WITH DATA
        ");
        DB::statement('CREATE UNIQUE INDEX mv_cost_center_productivity_key ON ops.mv_cost_center_productivity (metric_key)');
    }
};
