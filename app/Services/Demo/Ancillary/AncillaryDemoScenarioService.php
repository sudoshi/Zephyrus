<?php

namespace App\Services\Demo\Ancillary;

use App\Services\Demo\DemoClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AncillaryDemoScenarioService
{
    public const OWNER = 'operations-demo:summit-500-current-operations-v1:ancillary:v1';

    /** @param list<AncillaryDemoGenerator> $generators */
    public function __construct(private readonly array $generators) {}

    /** @return array<string, mixed> */
    public function preview(DemoClock $clock): array
    {
        $departments = array_map(fn (AncillaryDemoGenerator $generator): array => $generator->preview($clock), $this->generators);

        return $this->summary($clock, $departments);
    }

    /** @return array<string, mixed> */
    public function refresh(DemoClock $clock): array
    {
        $previousNow = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow($clock->anchor());

        try {
            $result = DB::transaction(function () use ($clock): array {
                $departments = array_map(
                    fn (AncillaryDemoGenerator $generator): array => $generator->refresh($clock, self::OWNER),
                    $this->generators,
                );
                $this->registerFreshness();

                return $this->summary($clock, $departments);
            }, 3);

            $this->refreshPlannerStatistics();

            return $result;
        } finally {
            CarbonImmutable::setTestNow($previousNow);
        }
    }

    /**
     * The bulk delete/reinsert above churns these tables faster than
     * autoanalyze follows; on a freshly provisioned database the planner
     * still assumes they are empty and picks unparameterized nested-loop
     * joins (measured 2.3x roll-forward slowdown — see
     * docs/audits/D1-ancillary-refresh-profile-2026-07-25.md). Statistics
     * are an optimization, never a correctness requirement, so a failure
     * (e.g. missing maintenance privilege) only logs.
     */
    private function refreshPlannerStatistics(): void
    {
        try {
            DB::statement('ANALYZE prod.ancillary_orders, prod.ancillary_milestones, prod.ancillary_current_assertions, prod.ancillary_breaches, integration.canonical_events, integration.provenance_records');
        } catch (Throwable $exception) {
            Log::warning('Ancillary demo planner-statistics refresh failed', ['error' => $exception->getMessage()]);
        }
    }

    /** @param list<array<string, mixed>> $departments @return array<string, mixed> */
    private function summary(DemoClock $clock, array $departments): array
    {
        return [
            'owner' => self::OWNER,
            'anchor' => $clock->key(),
            'orders' => array_sum(array_column($departments, 'orders')),
            'milestones' => array_sum(array_column($departments, 'milestones')),
            'breaches' => array_sum(array_map(
                fn (array $department): int => (int) ($department['breaches'] ?? $department['expectedBreaches'] ?? 0),
                $departments,
            )),
            'collisions' => array_values(array_merge(...array_column($departments, 'collisions'))),
            'departments' => $departments,
        ];
    }

    private function registerFreshness(): void
    {
        foreach ([
            ['ancillary_orders', 'Ancillary order projection', 'ancillary_orders', 'source_cutoff_at', 15, 60],
            ['ancillary_milestones', 'Ancillary milestone ledger', 'ancillary_milestones', 'received_at', 15, 60],
        ] as [$key, $label, $table, $column, $expected, $warning]) {
            DB::table('ops.source_freshness')->updateOrInsert(['source_key' => $key], [
                'source_label' => $label,
                'source_schema' => 'prod',
                'source_table' => $table,
                'freshness_column' => $column,
                'expected_lag_minutes' => $expected,
                'warning_lag_minutes' => $warning,
                'metadata' => json_encode(['demo_owner' => self::OWNER]),
                'checked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
