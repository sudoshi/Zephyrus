<?php

namespace App\Services\Cockpit;

use App\Enums\CockpitStatus;
use App\Services\Rtdc\BedTrackingService;
use App\Support\Cockpit\CockpitScope;
use App\Support\Hospital\HospitalManifest;

/**
 * Assembles the altitude-appropriate cockpit "face" for a resolved CockpitScope
 * (Zephyrus 2.0 P8 WS-2). A face is the same payload grammar as a domain drill —
 * {scope, title, sub, asOf, kpis[], tables[]} in the §6.4 Cell grammar — so the React
 * layer renders every altitude with the existing DataTable / Tile primitives.
 *
 * Reuse-first, per the WS-2 decision — a unit/department mount renders altitude-
 * APPROPRIATE tiles, never the house 8-domain grid shrunk:
 *   - house        → a marker ('render' => 'grid'); the frontend keeps rendering the
 *                    existing DomainGrid from the untouched snapshot.
 *   - department   → the existing DrillBuilder domain face, verbatim (ed/periop/rtdc).
 *   - unit         → that unit's live census (BedTrackingService), filtered by abbr.
 *   - service_line → the line's units rolled up from the same live census.
 *
 * The house cockpit snapshot + StatusEngine are untouched; scope AUTHORIZATION (may
 * THIS user mount it) layers on in WS-6.
 */
class ScopedFaceBuilder
{
    public function __construct(
        private readonly DrillBuilder $drills,
        private readonly BedTrackingService $beds,
        private readonly HospitalManifest $manifest,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CockpitScope $scope): array
    {
        return match ($scope->level) {
            CockpitScope::LEVEL_DEPARTMENT => $this->departmentFace($scope),
            CockpitScope::LEVEL_UNIT => $this->unitFace($scope),
            CockpitScope::LEVEL_SERVICE_LINE => $this->serviceLineFace($scope),
            default => $this->houseFace($scope),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function houseFace(CockpitScope $scope): array
    {
        // The house face IS the 8-domain grid the frontend already renders from the
        // snapshot; the endpoint just signals which surface to mount.
        return [
            'scope' => $scope->toArray(),
            'render' => 'grid',
            'title' => $scope->label,
            'sub' => 'House-wide operations overview',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function departmentFace(CockpitScope $scope): array
    {
        $drill = $this->drills->build((string) $scope->key);

        if ($drill === null) {
            return $this->houseFace(CockpitScope::house($this->manifest->facilityName()));
        }

        return ['scope' => $scope->toArray(), 'render' => 'face'] + $drill;
    }

    /**
     * @return array<string, mixed>
     */
    private function unitFace(CockpitScope $scope): array
    {
        $row = $this->unitCensusRow((string) $scope->key);

        if ($row === null) {
            // The unit is real (manifest-validated) but has no live census yet.
            return $this->emptyFace($scope, 'No live census for this unit yet');
        }

        return [
            'scope' => $scope->toArray(),
            'render' => 'face',
            'title' => $scope->label,
            'sub' => $row['type'].' — '.$row['occupancyPct'].'% occupancy',
            'asOf' => now()->toIso8601String(),
            'kpis' => $this->unitKpis($row),
            'tables' => [$this->boardTable('Unit capacity', [$row])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceLineFace(CockpitScope $scope): array
    {
        $abbrSet = array_flip(array_map(
            fn (array $u): string => strtoupper((string) $u['abbr']),
            $this->manifest->unitsByServiceLine((string) $scope->key),
        ));

        $rows = array_values(array_filter(
            $this->liveUnitCensus(),
            fn (array $r): bool => isset($abbrSet[strtoupper((string) $r['name'])]),
        ));

        if ($rows === []) {
            return $this->emptyFace($scope, 'No live census for this service line yet');
        }

        $staffed = $this->sum($rows, 'staffed');
        $occupied = $this->sum($rows, 'occupied');
        $available = $this->sum($rows, 'available');
        $blocked = $this->sum($rows, 'blocked');
        $occ = $staffed > 0 ? (int) round($occupied / $staffed * 100) : 0;

        return [
            'scope' => $scope->toArray(),
            'render' => 'face',
            'title' => $scope->label,
            'sub' => count($rows).' units — '.$occ.'% occupancy',
            'asOf' => now()->toIso8601String(),
            'kpis' => [
                $this->tile('sl.occupancy', 'Occupancy', $occ, $occ.'%', '%', count($rows).' units', $this->occupancyState($occ)),
                $this->tile('sl.staffed', 'Staffed beds', $staffed, (string) $staffed),
                $this->tile('sl.occupied', 'Occupied', $occupied, (string) $occupied),
                $this->tile('sl.available', 'Available', $available, (string) $available, status: $available > 0 ? CockpitStatus::OK : CockpitStatus::WARN),
                $this->tile('sl.blocked', 'Blocked', $blocked, (string) $blocked, status: $blocked > 0 ? CockpitStatus::WARN : CockpitStatus::NORMAL),
            ],
            'tables' => [$this->boardTable('Units in '.$scope->label, $rows)],
        ];
    }

    // -- helpers -------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function liveUnitCensus(): array
    {
        return $this->beds->build()['unitCensus'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function unitCensusRow(string $abbr): ?array
    {
        foreach ($this->liveUnitCensus() as $row) {
            if (strcasecmp((string) $row['name'], $abbr) === 0) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function unitKpis(array $row): array
    {
        $occ = (int) $row['occupancyPct'];
        $available = (int) $row['available'];
        $blocked = (int) $row['blocked'];

        return [
            $this->tile('unit.occupancy', 'Occupancy', $occ, $occ.'%', '%', 'Acuity-adj '.$row['acuityAdjustedPct'].'%', $this->occupancyState($occ)),
            $this->tile('unit.staffed', 'Staffed beds', (int) $row['staffed'], (string) $row['staffed']),
            $this->tile('unit.occupied', 'Occupied', (int) $row['occupied'], (string) $row['occupied']),
            $this->tile('unit.available', 'Available', $available, (string) $available, status: $available > 0 ? CockpitStatus::OK : CockpitStatus::WARN),
            $this->tile('unit.blocked', 'Blocked', $blocked, (string) $blocked, status: $blocked > 0 ? CockpitStatus::WARN : CockpitStatus::NORMAL),
        ];
    }

    /**
     * A tile-shaped KPI matching App\Support\Cockpit\MetricValue::toArray() so the
     * React Tile renders a synthesized census KPI exactly like a snapshot metric.
     *
     * @return array<string, mixed>
     */
    private function tile(
        string $key,
        string $label,
        int|float $value,
        string $display,
        ?string $unit = null,
        ?string $sub = null,
        CockpitStatus $status = CockpitStatus::NORMAL,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'display' => $display,
            'unit' => $unit,
            'sub' => $sub,
            'status' => $status->value,
            'target' => null,
            'direction' => null,
            'trend' => [],
            'trendLabel' => null,
            'updatedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * The §6.4 Cell-grammar capacity board — the same column set as
     * DrillBuilder::unitCapacityBoard, so one React table renders every altitude.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function boardTable(string $caption, array $rows): array
    {
        return [
            'caption' => $caption,
            'columns' => [
                ['key' => 'unit', 'header' => 'Unit', 'align' => 'left'],
                ['key' => 'type', 'header' => 'Type', 'align' => 'left'],
                ['key' => 'staffed', 'header' => 'Staffed', 'align' => 'right'],
                ['key' => 'occupied', 'header' => 'Occ', 'align' => 'right'],
                ['key' => 'available', 'header' => 'Avail', 'align' => 'right'],
                ['key' => 'blocked', 'header' => 'Blocked', 'align' => 'right'],
                ['key' => 'occupancy', 'header' => 'Occupancy', 'align' => 'left'],
                ['key' => 'status', 'header' => '', 'align' => 'right'],
            ],
            'rows' => array_map(fn (array $u): array => [
                'unit' => ['v' => (string) $u['name'], 'strong' => true],
                'type' => ['v' => (string) $u['type'], 'dim' => true],
                'staffed' => (int) $u['staffed'],
                'occupied' => (int) $u['occupied'],
                'available' => (int) $u['available'],
                'blocked' => (int) $u['blocked'],
                'occupancy' => ['bar' => [
                    'pct' => (int) $u['occupancyPct'],
                    'status' => (string) $u['status'],
                    'label' => $u['occupancyPct'].'%',
                ]],
                'status' => ['chip' => (string) $u['status']],
            ], $rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyFace(CockpitScope $scope, string $sub): array
    {
        return [
            'scope' => $scope->toArray(),
            'render' => 'face',
            'title' => $scope->label,
            'sub' => $sub,
            'asOf' => now()->toIso8601String(),
            'kpis' => [],
            'tables' => [],
        ];
    }

    private function occupancyState(int $pct): CockpitStatus
    {
        return match (true) {
            $pct >= 92 => CockpitStatus::CRIT,
            $pct >= 85 => CockpitStatus::WARN,
            $pct >= 70 => CockpitStatus::OK,
            default => CockpitStatus::NORMAL,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sum(array $rows, string $key): int
    {
        return (int) array_sum(array_map(fn (array $r): int => (int) $r[$key], $rows));
    }
}
