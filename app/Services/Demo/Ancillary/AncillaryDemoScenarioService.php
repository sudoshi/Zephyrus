<?php

namespace App\Services\Demo\Ancillary;

use App\Services\Demo\DemoClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
            return DB::transaction(function () use ($clock): array {
                $departments = array_map(
                    fn (AncillaryDemoGenerator $generator): array => $generator->refresh($clock, self::OWNER),
                    $this->generators,
                );
                $this->registerFreshness();

                return $this->summary($clock, $departments);
            }, 3);
        } finally {
            CarbonImmutable::setTestNow($previousNow);
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
