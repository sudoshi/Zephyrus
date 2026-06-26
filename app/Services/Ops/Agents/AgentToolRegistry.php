<?php

namespace App\Services\Ops\Agents;

use App\Models\User;
use App\Services\Analytics\OperationsAnalyticsService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AgentToolRegistry
{
    public function __construct(private readonly OperationsAnalyticsService $analytics) {}

    /** @return array<string,array<string,mixed>> */
    public function tools(): array
    {
        return [
            'capacity.snapshot' => [
                'label' => 'Capacity snapshot',
                'description' => 'Read-only current capacity, ED boarding, bed request, and transport risk summary.',
                'read_only' => true,
                'minimum_role' => 'user',
            ],
            'data_quality.summary' => [
                'label' => 'Data quality summary',
                'description' => 'Rules-only analytics governance and source freshness summary.',
                'read_only' => true,
                'minimum_role' => 'user',
            ],
        ];
    }

    /** @param array<string,mixed> $payload */
    public function call(string $toolKey, array $payload, ?User $actor): array
    {
        $tool = $this->tools()[$toolKey] ?? null;
        if ($tool === null) {
            throw new RuntimeException("Unknown agent tool [{$toolKey}].");
        }

        $this->authorizeTool($toolKey, $tool, $actor);

        if (($tool['read_only'] ?? false) !== true) {
            throw new RuntimeException("Agent tool [{$toolKey}] is not available to read-only agents.");
        }

        return match ($toolKey) {
            'capacity.snapshot' => $this->capacitySnapshot(),
            'data_quality.summary' => $this->analytics->dataQuality(),
            default => throw new RuntimeException("Unhandled agent tool [{$toolKey}]."),
        };
    }

    public function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                $keyString = is_string($key) ? $key : (string) $key;
                $redacted[$key] = $this->isPhiKey($keyString) ? '[redacted]' : $this->redact($item);
            }

            return $redacted;
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function capacitySnapshot(): array
    {
        $capacity = $this->capacity();
        $pendingAdmits = $this->countRows('prod.bed_requests', fn (Builder $query) => $query
            ->where('status', 'pending')
            ->where('is_deleted', false));
        $edBoarders = $this->countRows('prod.ed_visits', fn (Builder $query) => $query
            ->where('disposition', 'admitted')
            ->whereNull('bed_assigned_at')
            ->where('is_deleted', false));
        $transportAtRisk = $this->countRows('prod.transport_requests', fn (Builder $query) => $query
            ->whereIn('status', ['requested', 'assigned', 'in_progress', 'escalated'])
            ->where(function (Builder $inner): void {
                $inner->where('priority', 'stat')
                    ->orWhere('needed_at', '<', now());
            })
            ->where('is_deleted', false));
        $netBeds = $capacity['available'] - $pendingAdmits;
        $riskScore = min(100, max(0, (int) round(
            max(0, -$netBeds) * 10
            + min(20, $edBoarders) * 2
            + min(20, $transportAtRisk) * 2
                + min(20, $capacity['blocked']) * 1.5
        )));
        $latestCensusAt = $capacity['latest_census_at'] ? Carbon::parse($capacity['latest_census_at']) : null;
        $censusLagMinutes = $latestCensusAt ? (int) $latestCensusAt->diffInMinutes(now()) : null;
        $sourceFreshnessStatus = $censusLagMinutes === null || $censusLagMinutes > 60 ? 'warning' : 'success';
        $status = $riskScore >= 70 ? 'critical' : ($riskScore >= 40 ? 'warning' : 'success');
        if ($status === 'success' && $sourceFreshnessStatus === 'warning') {
            $status = 'warning';
        }

        return [
            'tool' => 'capacity.snapshot',
            'generatedAtIso' => now()->toIso8601String(),
            'status' => $status,
            'summary' => [
                'staffedBeds' => $capacity['staffed'],
                'occupiedBeds' => $capacity['occupied'],
                'availableBeds' => $capacity['available'],
                'blockedBeds' => $capacity['blocked'],
                'pendingAdmits' => $pendingAdmits,
                'edBoarders' => $edBoarders,
                'transportAtRisk' => $transportAtRisk,
                'netBeds' => $netBeds,
                'riskScore' => $riskScore,
                'latestCensusAtIso' => $latestCensusAt?->toIso8601String(),
                'censusLagMinutes' => $censusLagMinutes,
                'sourceFreshnessStatus' => $sourceFreshnessStatus,
            ],
            'findings' => $this->capacityFindings($netBeds, $edBoarders, $transportAtRisk, $capacity['blocked'], $sourceFreshnessStatus, $censusLagMinutes),
            'sourceTables' => ['prod.census_snapshots', 'prod.bed_requests', 'prod.ed_visits', 'prod.transport_requests'],
        ];
    }

    /** @return array{staffed:int,occupied:int,available:int,blocked:int,latest_census_at:?string} */
    private function capacity(): array
    {
        if (! Schema::hasTable('prod.census_snapshots')) {
            return ['staffed' => 0, 'occupied' => 0, 'available' => 0, 'blocked' => 0, 'latest_census_at' => null];
        }

        $row = DB::selectOne(<<<'SQL'
            WITH latest AS (
                SELECT DISTINCT ON (unit_id)
                    unit_id, staffed_beds, occupied, available, blocked, captured_at
                FROM prod.census_snapshots
                ORDER BY unit_id, captured_at DESC
            )
            SELECT
                COALESCE(SUM(staffed_beds), 0) AS staffed,
                COALESCE(SUM(occupied), 0) AS occupied,
                COALESCE(SUM(available), 0) AS available,
                COALESCE(SUM(blocked), 0) AS blocked,
                MAX(captured_at) AS latest_census_at
            FROM latest
        SQL);

        return [
            'staffed' => (int) ($row->staffed ?? 0),
            'occupied' => (int) ($row->occupied ?? 0),
            'available' => (int) ($row->available ?? 0),
            'blocked' => (int) ($row->blocked ?? 0),
            'latest_census_at' => $row->latest_census_at ?? null,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function capacityFindings(int $netBeds, int $edBoarders, int $transportAtRisk, int $blockedBeds, string $sourceFreshnessStatus, ?int $censusLagMinutes): array
    {
        return collect([
            [
                'key' => 'capacity_source_freshness',
                'status' => $sourceFreshnessStatus,
                'detail' => $censusLagMinutes === null
                    ? 'Capacity census has no timestamped observations.'
                    : "Latest capacity census is {$censusLagMinutes} minutes old.",
                'recommendedAction' => 'Confirm census feed freshness before acting on capacity-agent findings.',
            ],
            [
                'key' => 'net_bed_deficit',
                'status' => $netBeds < 0 ? 'critical' : ($netBeds <= 3 ? 'warning' : 'success'),
                'detail' => "{$netBeds} net beds after pending admits.",
                'recommendedAction' => 'Review bed placement, EVS readiness, and discharge pull-forward opportunities.',
            ],
            [
                'key' => 'ed_boarding',
                'status' => $edBoarders > 0 ? 'warning' : 'success',
                'detail' => "{$edBoarders} admitted ED patients are awaiting beds.",
                'recommendedAction' => 'Prioritize admitted ED patients in the next capacity huddle.',
            ],
            [
                'key' => 'transport_risk',
                'status' => $transportAtRisk > 0 ? 'warning' : 'success',
                'detail' => "{$transportAtRisk} active transport requests are stat or overdue.",
                'recommendedAction' => 'Review transport escalation pool before assigning new non-urgent moves.',
            ],
            [
                'key' => 'blocked_beds',
                'status' => $blockedBeds > 0 ? 'warning' : 'success',
                'detail' => "{$blockedBeds} beds are blocked in latest census snapshots.",
                'recommendedAction' => 'Route blocked capacity to the accountable owner before the next bed meeting.',
            ],
        ])
            ->reject(fn (array $finding): bool => $finding['status'] === 'success')
            ->values()
            ->all();
    }

    private function countRows(string $table, callable $callback): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        $callback($query);

        return (int) $query->count();
    }

    /** @param array<string,mixed> $tool */
    private function authorizeTool(string $toolKey, array $tool, ?User $actor): void
    {
        if ($actor === null) {
            throw new RuntimeException("Agent tool [{$toolKey}] requires an authenticated user.");
        }

        $minimumRole = (string) ($tool['minimum_role'] ?? 'user');
        if (! $this->roleAllows((string) ($actor->role ?? 'user'), $minimumRole)) {
            throw new RuntimeException("Agent tool [{$toolKey}] requires {$minimumRole} access.");
        }
    }

    private function roleAllows(string $actual, string $minimum): bool
    {
        $rank = [
            'user' => 1,
            'admin' => 2,
            'superuser' => 3,
            'super-admin' => 3,
        ];

        return ($rank[$actual] ?? 1) >= ($rank[$minimum] ?? 1);
    }

    private function isPhiKey(string $key): bool
    {
        $normalized = strtolower($key);

        return str_contains($normalized, 'patient')
            || str_contains($normalized, 'mrn')
            || str_contains($normalized, 'ssn')
            || str_contains($normalized, 'dob')
            || str_contains($normalized, 'encounter_ref');
    }
}
