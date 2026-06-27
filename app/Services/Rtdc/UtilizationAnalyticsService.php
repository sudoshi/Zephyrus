<?php

namespace App\Services\Rtdc;

use Illuminate\Support\Facades\DB;

/**
 * Builds the RTDC "Utilization & Capacity" analytics payload from the live
 * `prod` schema (DB_SCHEMA=prod).
 *
 * The page (resources/js/Pages/RTDC/Analytics/Utilization.jsx) renders the
 * shared Zephyrus design system (Section / MetricGrid / KpiTile / UnitHeatStrip)
 * promoted from the Command Center. This service supplies:
 *
 *   houseSummary: array<string,int>          house-wide roll-up
 *   kpis:         list<KpiMetric>            the KPI wall (occupancy/available/…)
 *   unitCensus:   list<UnitCensus>          per-unit census (heat strip)
 *   occupancyByUnit: list<{...}>            per-unit occupancy bar series
 *   occupancyTrend:  list<{label,occupancy}> real house-wide daily occupancy
 *
 * `prod.census_snapshots` is the authoritative source for occupancy/capacity:
 * the latest snapshot per non-deleted unit (DISTINCT ON captured_at) is the
 * "now" surface, mirroring RtdcDashboardService / BedTrackingService idioms. The
 * trend is computed from the same reporting units' daily-latest snapshots, so it
 * is REAL data, not a synthesized curve. Computation is deterministic and safe
 * on empty tables: every accessor returns zeros / empty collections rather than
 * throwing.
 */
class UtilizationAnalyticsService
{
    /** Human-readable label per unit `type` (drives the heat-strip "type"). */
    private const TYPE_LABELS = [
        'ed' => 'Emergency',
        'icu' => 'Critical',
        'step_down' => 'Step-down',
        'med_surg' => 'Med/Surg',
        'or' => 'Surgical',
    ];

    /**
     * Full Utilization & Capacity payload.
     *
     * @return array{
     *     houseSummary: array<string,int>,
     *     kpis: list<array<string,mixed>>,
     *     unitCensus: list<array<string,mixed>>,
     *     occupancyByUnit: list<array<string,mixed>>,
     *     occupancyTrend: list<array{label:string,occupancy:int}>
     * }
     */
    public function build(): array
    {
        $census = $this->latestCensusPerUnit();
        $summary = $this->houseSummary($census);
        $unitCensus = $this->unitCensus($census);
        $trend = $this->occupancyTrend();

        return [
            'houseSummary' => $summary,
            'kpis' => $this->kpis($summary, $trend),
            'unitCensus' => $unitCensus,
            'occupancyByUnit' => $this->occupancyByUnit($unitCensus),
            'occupancyTrend' => $trend,
        ];
    }

    // -----------------------------------------------------------------------
    // Source queries
    // -----------------------------------------------------------------------

    /**
     * Latest census snapshot per non-deleted unit (DISTINCT ON captured_at).
     *
     * @return list<object>
     */
    private function latestCensusPerUnit(): array
    {
        return DB::select(
            'SELECT DISTINCT ON (cs.unit_id)
                cs.unit_id, cs.staffed_beds, cs.occupied, cs.available,
                cs.blocked, cs.acuity_adjusted_capacity,
                u.name AS unit_name, u.abbreviation, u.type AS unit_type
             FROM prod.census_snapshots cs
             JOIN prod.units u ON u.unit_id = cs.unit_id
             WHERE u.is_deleted = false
             ORDER BY cs.unit_id, cs.captured_at DESC'
        );
    }

    /**
     * Real house-wide daily occupancy trend, computed from the daily-latest
     * snapshot of each currently-reporting unit. Restricting to reporting units
     * (the same set the "now" view shows) excludes pre-dedup seed scaffolding
     * that briefly inflated the bed counts on the bootstrap day.
     *
     * @return list<array{label:string,occupancy:int}>
     */
    private function occupancyTrend(): array
    {
        $rows = DB::select(
            'WITH reporting AS (
                SELECT DISTINCT ON (cs.unit_id) cs.unit_id
                FROM prod.census_snapshots cs
                JOIN prod.units u ON u.unit_id = cs.unit_id
                WHERE u.is_deleted = false
                ORDER BY cs.unit_id, cs.captured_at DESC
             ),
             daily AS (
                SELECT cs.unit_id, cs.captured_at::date AS d,
                    (array_agg(cs.occupied ORDER BY cs.captured_at DESC))[1] AS occ,
                    (array_agg(cs.staffed_beds ORDER BY cs.captured_at DESC))[1] AS staffed
                FROM prod.census_snapshots cs
                WHERE cs.unit_id IN (SELECT unit_id FROM reporting)
                GROUP BY cs.unit_id, cs.captured_at::date
             )
             SELECT d,
                SUM(occ) AS occ,
                SUM(staffed) AS staffed
             FROM daily
             GROUP BY d
             ORDER BY d
             LIMIT 30'
        );

        $out = [];
        foreach ($rows as $row) {
            $staffed = (int) $row->staffed;
            $occ = (int) $row->occ;
            $out[] = [
                'label' => $this->formatTrendLabel((string) $row->d),
                'occupancy' => $staffed > 0 ? (int) round($occ / $staffed * 100) : 0,
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // House-wide roll-up
    // -----------------------------------------------------------------------

    /**
     * @param  list<object>  $census
     * @return array<string,int>
     */
    private function houseSummary(array $census): array
    {
        $staffed = 0;
        $occupied = 0;
        $available = 0;
        $blocked = 0;
        $acuityCapacity = 0;
        $unitCount = 0;

        foreach ($census as $row) {
            $staffed += (int) $row->staffed_beds;
            $occupied += (int) $row->occupied;
            $available += (int) $row->available;
            $blocked += (int) $row->blocked;
            $acuityCapacity += (int) $row->acuity_adjusted_capacity;
            $unitCount++;
        }

        $occupancy = $staffed > 0 ? (int) round($occupied / $staffed * 100) : 0;
        // Acuity-adjusted occupancy: occupied against the acuity-reduced
        // effective capacity (a high-acuity census consumes more capacity than
        // the raw bed count implies), capped at 100.
        $acuityOccupancy = $acuityCapacity > 0
            ? min(100, (int) round($occupied / $acuityCapacity * 100))
            : $occupancy;

        return [
            'staffed' => $staffed,
            'occupied' => $occupied,
            'available' => $available,
            'blocked' => $blocked,
            'acuityCapacity' => $acuityCapacity,
            'occupancy' => $occupancy,
            'acuityOccupancy' => $acuityOccupancy,
            'unitCount' => $unitCount,
        ];
    }

    /**
     * The KPI wall: KpiMetric-shaped tiles (the Command Center contract). The
     * occupancy tiles carry the real trend series so they render a sparkline;
     * `%` tiles render as radial gauges in the KpiTile.
     *
     * @param  array<string,int>  $summary
     * @param  list<array{label:string,occupancy:int}>  $trend
     * @return list<array<string,mixed>>
     */
    private function kpis(array $summary, array $trend): array
    {
        $occupancy = (int) $summary['occupancy'];
        $available = (int) $summary['available'];
        $blocked = (int) $summary['blocked'];
        $acuityOccupancy = (int) $summary['acuityOccupancy'];
        $occupied = (int) $summary['occupied'];
        $staffed = (int) $summary['staffed'];

        $trendPoints = array_map(static fn ($p): int => (int) $p['occupancy'], $trend);
        $occupancyTrajectory = count($trendPoints) >= 2 ? $trendPoints : null;

        return [
            $this->metric([
                'key' => 'house-occupancy',
                'label' => 'House occupancy',
                'value' => $occupancy,
                'unit' => '%',
                'status' => $this->occupancyStatus($occupancy),
                'target' => 85,
                'goodWhenDown' => true,
                'trajectory' => $occupancyTrajectory,
                'definition' => 'Occupied ÷ staffed beds across all reporting units. The headline capacity number; sustained >85% strains patient flow.',
            ]),
            $this->metric([
                'key' => 'occupied',
                'label' => 'Occupied beds',
                'value' => $occupied,
                'status' => 'info',
                'target' => $staffed > 0 ? (int) round($staffed * 0.85) : null,
                'goodWhenDown' => true,
                'definition' => 'Beds with a current inpatient across reporting units.',
            ]),
            $this->metric([
                'key' => 'available',
                'label' => 'Available now',
                'value' => $available,
                'status' => $this->availableStatus($available),
                'definition' => 'Clean, staffed, immediately assignable beds house-wide.',
                'drillHref' => '/rtdc/bed-tracking',
            ]),
            $this->metric([
                'key' => 'blocked',
                'label' => 'Blocked',
                'value' => $blocked,
                'status' => $blocked > 0 ? 'warning' : 'success',
                'goodWhenDown' => true,
                'definition' => 'Beds unavailable for staffing, isolation, or maintenance reasons.',
            ]),
            $this->metric([
                'key' => 'acuity-occupancy',
                'label' => 'Acuity-adjusted',
                'value' => $acuityOccupancy,
                'unit' => '%',
                'status' => $this->occupancyStatus($acuityOccupancy),
                'target' => 90,
                'goodWhenDown' => true,
                'definition' => 'Occupied beds against acuity-adjusted effective capacity. Runs hotter than raw occupancy when the census is high-acuity.',
            ]),
        ];
    }

    // -----------------------------------------------------------------------
    // Per-unit census + bar series
    // -----------------------------------------------------------------------

    /**
     * Per-unit census matching UnitCensus in commandCenter.ts (drives the
     * UnitHeatStrip). ED is kept here (it is a real reporting unit on this
     * analytics surface), sorted hottest-first so strain reads top-left.
     *
     * @param  list<object>  $census
     * @return list<array<string,mixed>>
     */
    private function unitCensus(array $census): array
    {
        $out = [];
        foreach ($census as $row) {
            $type = (string) $row->unit_type;
            $staffed = (int) $row->staffed_beds;
            $occupied = (int) $row->occupied;
            $available = (int) $row->available;
            $blocked = (int) $row->blocked;
            $acuityCapacity = (int) $row->acuity_adjusted_capacity;
            $occupancyPct = $staffed > 0 ? (int) round($occupied / $staffed * 100) : 0;
            $acuityAdjustedPct = $acuityCapacity > 0
                ? min(100, (int) round($occupied / $acuityCapacity * 100))
                : $occupancyPct;

            $out[] = [
                'unitId' => (int) $row->unit_id,
                'name' => (string) ($row->abbreviation ?: $row->unit_name),
                'type' => self::TYPE_LABELS[$type] ?? ucfirst(str_replace('_', '-', $type)),
                'staffed' => $staffed,
                'occupied' => $occupied,
                'blocked' => $blocked,
                'available' => $available,
                'occupancyPct' => $occupancyPct,
                'acuityAdjustedPct' => $acuityAdjustedPct,
                'status' => $this->occupancyStatus($occupancyPct),
            ];
        }

        // Hottest units first.
        usort($out, static fn ($a, $b): int => $b['occupancyPct'] <=> $a['occupancyPct']);

        return $out;
    }

    /**
     * Per-unit occupancy bar series (one bar per unit). Carries the occupancy
     * percentage, the raw occupied/staffed fraction, and a four-color status so
     * the page can color each bar from the same status vocabulary.
     *
     * @param  list<array<string,mixed>>  $unitCensus
     * @return list<array<string,mixed>>
     */
    private function occupancyByUnit(array $unitCensus): array
    {
        $out = [];
        foreach ($unitCensus as $u) {
            $out[] = [
                'unitId' => (int) $u['unitId'],
                'name' => (string) $u['name'],
                'type' => (string) $u['type'],
                'occupancyPct' => (int) $u['occupancyPct'],
                'occupied' => (int) $u['occupied'],
                'staffed' => (int) $u['staffed'],
                'available' => (int) $u['available'],
                'status' => (string) $u['status'],
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Local KpiMetric factory — mirrors resources/js/Components/system/metric.ts
     * so the payload matches the kpiMetricSchema the KpiTile consumes.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function metric(array $input): array
    {
        $value = (int) ($input['value'] ?? 0);
        $unit = (string) ($input['unit'] ?? '');
        $isPct = $unit === '%';
        $display = $isPct ? "{$value}%" : number_format($value);

        $target = $input['target'] ?? null;
        $targetDisplay = $target !== null
            ? ($isPct ? "{$target}%" : number_format((int) $target))
            : null;

        $trajectory = null;
        $points = $input['trajectory'] ?? null;
        if (is_array($points) && count($points) >= 2) {
            $points = array_values(array_map(static fn ($n): int => (int) $n, $points));
            $prev = $points[count($points) - 2];
            $last = $points[count($points) - 1];
            $direction = $last > $prev ? 'up' : ($last < $prev ? 'down' : 'flat');
            $trajectory = [
                'points' => $points,
                'direction' => $direction,
                'goodWhenDown' => (bool) ($input['goodWhenDown'] ?? false),
            ];
        }

        return [
            'key' => (string) $input['key'],
            'label' => (string) $input['label'],
            'value' => $value,
            'unit' => $unit,
            'display' => $display,
            'target' => $target !== null ? (int) $target : null,
            'targetDisplay' => $targetDisplay,
            'status' => (string) ($input['status'] ?? 'neutral'),
            'trajectory' => $trajectory,
            'drillHref' => $input['drillHref'] ?? null,
            'definition' => (string) ($input['definition'] ?? $input['label']),
        ];
    }

    /** Map occupancy → four-color status vocabulary. */
    private function occupancyStatus(int $occupancy): string
    {
        if ($occupancy >= 92) {
            return 'critical';
        }
        if ($occupancy >= 85) {
            return 'warning';
        }

        return 'success';
    }

    /** Lower available beds are worse (a capacity squeeze). */
    private function availableStatus(int $available): string
    {
        if ($available <= 5) {
            return 'critical';
        }
        if ($available <= 15) {
            return 'warning';
        }

        return 'success';
    }

    /** "2026-06-27" → "Jun 27"; tolerant of any parseable date string. */
    private function formatTrendLabel(string $date): string
    {
        $ts = strtotime($date);

        return $ts !== false ? date('M j', $ts) : $date;
    }
}
