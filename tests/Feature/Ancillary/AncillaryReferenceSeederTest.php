<?php

namespace Tests\Feature\Ancillary;

use App\Domain\Ocel\HospitalProcessCatalog;
use App\Integrations\Healthcare\Ancillary\AncillaryEventVocabulary;
use App\Models\Ancillary\AncillarySlaDefinition;
use App\Models\Ops\MetricDefinition;
use Database\Factories\Ancillary\AncillaryScenarioFactory;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AncillaryReferenceSeederTest extends TestCase
{
    use RefreshDatabase;

    private const COCKPIT_KEYS = [
        'flow.ancillary_rad_open_breaches',
        'flow.ancillary_rad_oldest_unread',
        'flow.ancillary_rad_scanners_down',
        'flow.ancillary_lab_stat_compliance',
        'flow.ancillary_lab_oldest_decision_pending',
        'flow.ancillary_lab_critical_callbacks',
        'flow.ancillary_rx_verification_queue',
        'flow.ancillary_rx_oldest_stat',
        'flow.ancillary_rx_sepsis_at_risk',
        'flow.ancillary_rx_shortage_stockouts',
    ];

    public function test_seeder_populates_complete_milestone_barrier_sla_and_cockpit_catalogs(): void
    {
        $this->seed(AncillaryReferenceSeeder::class);
        $this->seed(CockpitKpiDefinitionSeeder::class);

        $this->assertSame(60, DB::table('hosp_ref.ancillary_milestone_types')->count());
        $this->assertSame(17, DB::table('hosp_ref.ancillary_barrier_reasons')->count());
        $this->assertSame(27, AncillarySlaDefinition::query()->count());
        $this->assertSame(
            ['collection', 'transport', 'analytic', 'post_analytic', 'end_to_end'],
            AncillarySlaDefinition::query()
                ->where('department', 'lab')
                ->get()
                ->filter(fn (AncillarySlaDefinition $definition): bool => (bool) ($definition->scope['study_segment'] ?? false))
                ->sortBy(fn (AncillarySlaDefinition $definition): int => (int) ($definition->scope['sequence'] ?? 999))
                ->map(fn (AncillarySlaDefinition $definition): string => (string) $definition->scope['phase'])
                ->values()->all(),
        );
        $this->assertSame(10, MetricDefinition::query()->whereIn('metric_key', self::COCKPIT_KEYS)->count());
        $this->assertSame(9, DB::table('hosp_ref.lab_test_catalog')->count());
        $this->assertSame(
            ['discharge_gate', 'ed_disposition', 'none', 'or_gate'],
            DB::table('hosp_ref.lab_test_catalog')->distinct()->orderBy('decision_class')->pluck('decision_class')->all(),
        );
        $this->assertSame(9, DB::table('hosp_ref.rx_formulary')->count());
        $this->assertSame(1, DB::table('hosp_ref.rx_formulary')->where('terminology_status', 'unmapped_local')->count());

        $departmentCounts = DB::table('hosp_ref.ancillary_milestone_types')
            ->select('department', DB::raw('count(*) as total'))
            ->groupBy('department')
            ->orderBy('department')
            ->pluck('total', 'department')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
        $this->assertSame([
            'blood_bank' => 6,
            'lab' => 14,
            'pathology' => 9,
            'rad' => 15,
            'rx' => 16,
        ], $departmentCounts);

        $this->assertSame(0, DB::table('hosp_ref.ancillary_milestone_types')
            ->whereNull('expected_source_class')
            ->orWhereNull('ocel_event_type')
            ->count());

        foreach (self::COCKPIT_KEYS as $key) {
            $definition = MetricDefinition::query()->where('metric_key', $key)->firstOrFail();
            $this->assertSame('flow', $definition->domain);
            $this->assertNotEmpty($definition->label);
            $this->assertNotEmpty($definition->definition);
            $this->assertNotEmpty($definition->unit);
            $this->assertContains($definition->direction, ['up', 'down']);
            $this->assertGreaterThan(0, $definition->refresh_secs);
            $this->assertNotNull($definition->warn_edge);
            $this->assertNotNull($definition->crit_edge);
        }
    }

    public function test_every_sla_uses_catalog_codes_from_its_own_department_and_valid_thresholds(): void
    {
        $this->seed(AncillaryReferenceSeeder::class);

        foreach (AncillarySlaDefinition::query()->get() as $definition) {
            $startDepartment = DB::table('hosp_ref.ancillary_milestone_types')
                ->where('code', $definition->start_milestone_code)
                ->value('department');
            $stopDepartment = DB::table('hosp_ref.ancillary_milestone_types')
                ->where('code', $definition->stop_milestone_code)
                ->value('department');

            $this->assertSame($definition->department, $startDepartment, "Bad start code for {$definition->metric_key}");
            $this->assertSame($definition->department, $stopDepartment, "Bad stop code for {$definition->metric_key}");
            if ($definition->warning_minutes !== null && $definition->breach_minutes !== null) {
                $this->assertLessThanOrEqual($definition->breach_minutes, $definition->warning_minutes);
            }
        }
    }

    public function test_barrier_reasons_map_only_to_existing_barrier_categories(): void
    {
        $this->seed(AncillaryReferenceSeeder::class);

        $invalid = DB::table('hosp_ref.ancillary_barrier_reasons')
            ->whereNotIn('category', ['medical', 'logistical', 'placement', 'social'])
            ->count();

        $this->assertSame(0, $invalid);
        $this->assertSame(
            ['blood_bank', 'lab', 'pathology', 'rad', 'rx'],
            DB::table('hosp_ref.ancillary_barrier_reasons')->distinct()->orderBy('department')->pluck('department')->all(),
        );
    }

    public function test_every_ocel_process_mapping_resolves_to_the_executable_hospital_catalog(): void
    {
        $this->seed(AncillaryReferenceSeeder::class);

        $rows = DB::table('hosp_ref.ancillary_milestone_types')->get(['code', 'process_ids']);
        foreach ($rows as $row) {
            $processIds = json_decode($row->process_ids, true, 512, JSON_THROW_ON_ERROR);
            $this->assertNotEmpty($processIds, "{$row->code} lacks an OCEL process mapping");
            foreach ($processIds as $processId) {
                $this->assertNotNull(HospitalProcessCatalog::find($processId), "{$row->code} references unknown process {$processId}");
            }
        }
    }

    public function test_parser_projection_vocabulary_matches_the_seeded_catalog_exactly(): void
    {
        $this->seed(AncillaryReferenceSeeder::class);

        $catalogCodes = DB::table('hosp_ref.ancillary_milestone_types')->orderBy('code')->pluck('code')->all();
        $vocabularyCodes = AncillaryEventVocabulary::codes();
        sort($vocabularyCodes);

        $this->assertSame($catalogCodes, $vocabularyCodes);
        $this->assertSame(count($vocabularyCodes), count(array_unique(AncillaryEventVocabulary::eventTypes())));
        foreach (AncillaryEventVocabulary::eventTypes() as $eventType) {
            $code = AncillaryEventVocabulary::milestoneCodeFor($eventType);
            $this->assertSame($eventType, AncillaryEventVocabulary::eventTypeFor($code));
        }
    }

    public function test_reseeding_preserves_uuids_and_governed_sla_and_cockpit_tuning(): void
    {
        $this->seed(AncillaryReferenceSeeder::class);
        $this->seed(CockpitKpiDefinitionSeeder::class);

        $sla = AncillarySlaDefinition::query()->where('metric_key', 'rad.stat_order_final')->firstOrFail();
        $metric = MetricDefinition::query()->where('metric_key', 'flow.ancillary_rad_open_breaches')->firstOrFail();
        $slaUuid = $sla->definition_uuid;
        $metricUuid = $metric->metric_definition_uuid;

        $sla->update(['warning_minutes' => 700, 'breach_minutes' => 777]);
        $metric->update(['warn_edge' => 700, 'crit_edge' => 777]);

        $this->seed(AncillaryReferenceSeeder::class);
        $this->seed(CockpitKpiDefinitionSeeder::class);

        $sla->refresh();
        $metric->refresh();
        $this->assertSame($slaUuid, $sla->definition_uuid);
        $this->assertSame(700, $sla->warning_minutes);
        $this->assertSame(777, $sla->breach_minutes);
        $this->assertSame($metricUuid, $metric->metric_definition_uuid);
        $this->assertSame(700.0, (float) $metric->warn_edge);
        $this->assertSame(777.0, (float) $metric->crit_edge);
        $this->assertSame(60, DB::table('hosp_ref.ancillary_milestone_types')->count());
        $this->assertSame(27, AncillarySlaDefinition::query()->count());
        $this->assertSame(9, DB::table('hosp_ref.lab_test_catalog')->count());
        $this->assertSame(9, DB::table('hosp_ref.rx_formulary')->count());
    }

    public function test_database_rejects_removal_of_a_catalog_code_referenced_by_sla_policy(): void
    {
        $this->seed(AncillaryReferenceSeeder::class);

        $this->expectException(QueryException::class);
        DB::table('hosp_ref.ancillary_milestone_types')->where('code', 'RAD_ORDERED')->delete();
    }

    public function test_demo_scenario_fails_if_a_non_sla_catalog_code_is_removed(): void
    {
        $this->seed(AncillaryReferenceSeeder::class);
        DB::table('hosp_ref.ancillary_milestone_types')->where('code', 'LAB_REJECTED')->delete();

        $this->expectException(QueryException::class);
        (new AncillaryScenarioFactory)->reworkLoop();
    }
}
