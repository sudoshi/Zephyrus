<?php

namespace App\Services\Cockpit;

use App\Enums\CockpitStatus;
use App\Models\Encounter;
use App\Services\Mobile\MobilePatientContextService;
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
        private readonly MobilePatientContextService $patients,
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
        $unit = $this->manifest->unit((string) $scope->key);

        // The perioperative platform is a procedural surface, not a census ward —
        // its live face IS the periop domain drill (same reuse rule as a department
        // mount), never a bed-census card that would honestly-but-uselessly read 0%.
        if (($unit['type'] ?? null) === 'periop') {
            $drill = $this->drills->build('periop');
            if ($drill !== null) {
                return ['scope' => $scope->toArray(), 'render' => 'face'] + $drill;
            }
        }

        $row = $this->unitCensusRow($this->unitNameCandidates($unit, (string) $scope->key));

        if ($row === null) {
            // ED falls back to its domain drill if its census unit ever disappears.
            if (($unit['type'] ?? null) === 'ed') {
                $drill = $this->drills->build('ed');
                if ($drill !== null) {
                    return ['scope' => $scope->toArray(), 'render' => 'face'] + $drill;
                }
            }

            // The unit is real (manifest-validated) but has no live census yet.
            return $this->emptyFace($scope, 'No live census for this unit yet');
        }

        $roster = $this->unitRoster((int) $row['unitId']);

        return [
            'scope' => $scope->toArray(),
            'render' => 'face',
            'title' => $scope->label,
            'sub' => $row['type'].' — '.$row['occupancyPct'].'% occupancy',
            'asOf' => now()->toIso8601String(),
            'kpis' => $this->unitKpis($row, $roster),
            'tables' => [
                $this->unitRosterTable($roster),
                $this->boardTable('Unit capacity', [$row]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceLineFace(CockpitScope $scope): array
    {
        // Census rows may be keyed by either the branded abbr or the CAD join key
        // (prod.units.abbreviation differs by which importer wrote it) — roll up both.
        $abbrSet = [];
        foreach ($this->manifest->unitsByServiceLine((string) $scope->key) as $u) {
            foreach ($this->unitNameCandidates($u, (string) $u['abbr']) as $candidate) {
                $abbrSet[$candidate] = true;
            }
        }

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

        // Line-wide patient-care aggregates from the live encounter spine. The
        // altitude model keeps the patient BOARD at the unit mount — a line-wide
        // roster would be a 150-row wall; here the line gets the numbers and each
        // unit row is one picker-hop from its own board.
        $actives = Encounter::query()
            ->active()
            ->whereIn('unit_id', array_map(fn (array $r): int => (int) $r['unitId'], $rows))
            ->get(['acuity_tier', 'expected_discharge_date', 'admitted_at']);

        $today = now()->endOfDay();
        $dcDue = $actives
            ->filter(fn (Encounter $e): bool => $e->expected_discharge_date !== null
                && $e->expected_discharge_date->lte($today))
            ->count();
        $highAcuity = $actives->filter(fn (Encounter $e): bool => (int) $e->acuity_tier >= 4)->count();
        $losHours = $actives
            ->filter(fn (Encounter $e): bool => $e->admitted_at !== null)
            ->map(fn (Encounter $e): int => (int) $e->admitted_at->diffInHours(now()));
        $alosDays = $losHours->isEmpty() ? null : round($losHours->avg() / 24, 1);

        return [
            'scope' => $scope->toArray(),
            'render' => 'face',
            'title' => $scope->label,
            'sub' => count($rows).' units — '.$occ.'% occupancy — '.$actives->count().' patients',
            'asOf' => now()->toIso8601String(),
            'kpis' => [
                $this->tile('sl.occupancy', 'Occupancy', $occ, $occ.'%', '%', count($rows).' units', $this->occupancyState($occ)),
                $this->tile('sl.census', 'Patients', $actives->count(), (string) $actives->count(), null, $staffed.' staffed beds'),
                $this->tile('sl.available', 'Available', $available, (string) $available, status: $available > 0 ? CockpitStatus::OK : CockpitStatus::WARN),
                $this->tile('sl.blocked', 'Blocked', $blocked, (string) $blocked, status: $blocked > 0 ? CockpitStatus::WARN : CockpitStatus::NORMAL),
                $this->tile('sl.high_acuity', 'High acuity', $highAcuity, (string) $highAcuity, null, 'Tier 4+', $highAcuity > 0 ? CockpitStatus::WATCH : CockpitStatus::NORMAL),
                $this->tile('sl.dc_due', 'DC due today', $dcDue, (string) $dcDue, null, 'Expected discharges', $dcDue > 0 ? CockpitStatus::OK : CockpitStatus::NORMAL),
                $this->tile('sl.alos', 'ALOS (active)', $alosDays ?? 0, $alosDays !== null ? $alosDays.'d' : 'N/A', 'days'),
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
     * The uppercase names a unit's live-census row may carry: the branded manifest
     * abbreviation and (when present) the CAD join key — prod.units.abbreviation
     * holds one or the other depending on which importer created the unit.
     *
     * @param  array<string, mixed>|null  $unit
     * @return list<string>
     */
    private function unitNameCandidates(?array $unit, string $fallbackAbbr): array
    {
        $candidates = [strtoupper($fallbackAbbr)];

        foreach (['abbr', 'cad_code'] as $field) {
            $value = $unit[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $candidates[] = strtoupper($value);
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param  list<string>  $nameCandidates  uppercase unit-name candidates
     * @return array<string, mixed>|null
     */
    private function unitCensusRow(array $nameCandidates): ?array
    {
        foreach ($this->liveUnitCensus() as $row) {
            if (in_array(strtoupper((string) $row['name']), $nameCandidates, true)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  \Illuminate\Support\Collection<int, Encounter>  $roster
     * @return list<array<string, mixed>>
     */
    private function unitKpis(array $row, $roster): array
    {
        $occ = (int) $row['occupancyPct'];
        $available = (int) $row['available'];
        $blocked = (int) $row['blocked'];

        $dcDue = $roster
            ->filter(fn (Encounter $e): bool => $e->expected_discharge_date !== null
                && $e->expected_discharge_date->lte(now()->endOfDay()))
            ->count();

        return [
            $this->tile('unit.occupancy', 'Occupancy', $occ, $occ.'%', '%', 'Acuity-adj '.$row['acuityAdjustedPct'].'%', $this->occupancyState($occ)),
            $this->tile('unit.staffed', 'Staffed beds', (int) $row['staffed'], (string) $row['staffed']),
            $this->tile('unit.occupied', 'Occupied', (int) $row['occupied'], (string) $row['occupied']),
            $this->tile('unit.available', 'Available', $available, (string) $available, status: $available > 0 ? CockpitStatus::OK : CockpitStatus::WARN),
            $this->tile('unit.blocked', 'Blocked', $blocked, (string) $blocked, status: $blocked > 0 ? CockpitStatus::WARN : CockpitStatus::NORMAL),
            $this->tile('unit.dc_due', 'DC due today', $dcDue, (string) $dcDue, null, 'Expected discharges', $dcDue > 0 ? CockpitStatus::OK : CockpitStatus::NORMAL),
        ];
    }

    /**
     * Active encounters on a unit, sickest-first then longest-stay — the roster
     * behind both the patient board and the discharge-due tile. Deliberately
     * de-identified (bed / acuity / LOS / EDD only): patient identity stays gated
     * at A2P behind EnforceFlowLens; the drill cell carries only the opaque ptok.
     *
     * @return \Illuminate\Support\Collection<int, Encounter>
     */
    private function unitRoster(int $unitId)
    {
        return Encounter::query()
            ->active()
            ->where('unit_id', $unitId)
            ->with('bed')
            ->orderByDesc('acuity_tier')
            ->orderBy('admitted_at')
            ->get();
    }

    /**
     * The §6.4 Cell-grammar patient board for a unit mount. The bed cell descends
     * to the A2P patient lens when the patient_ref resolves to a context token
     * (same affordance as the ED track board); RBAC is enforced at the
     * destination, so the opaque ptok is safe to carry.
     *
     * @param  \Illuminate\Support\Collection<int, Encounter>  $roster
     * @return array<string, mixed>
     */
    private function unitRosterTable($roster): array
    {
        $today = now()->endOfDay();

        $rows = $roster->map(function (Encounter $e) use ($today): array {
            $bedLabel = $e->bed?->label ?? 'Unassigned';
            $contextRef = $this->patients->contextRefFor($e->patient_ref);
            $tier = (int) $e->acuity_tier;
            $losHours = $e->admitted_at !== null ? (int) $e->admitted_at->diffInHours(now()) : null;

            $edd = $e->expected_discharge_date;
            $eddCell = $edd === null
                ? ['v' => '—', 'dim' => true]
                : ($edd->lte($today)
                    ? ['tag' => ['text' => 'Today', 'status' => 'success']]
                    : ['v' => $edd->format('M j'), 'dim' => true]);

            return [
                'bed' => $contextRef !== null
                    ? ['drill' => ['patientRef' => $contextRef, 'text' => $bedLabel, 'strong' => true]]
                    : ['v' => $bedLabel, 'strong' => true],
                'acuity' => ['tag' => [
                    'text' => 'T'.$tier,
                    'status' => $tier >= 4 ? 'critical' : ($tier === 3 ? 'warning' : 'neutral'),
                ]],
                'los' => [
                    'v' => $this->losDisplay($losHours),
                    'status' => ($losHours ?? 0) > 120 ? 'warning' : 'neutral',
                ],
                'edd' => $eddCell,
            ];
        })->values()->all();

        return [
            'caption' => 'Patient board',
            'columns' => [
                ['key' => 'bed', 'header' => 'Bed', 'align' => 'left'],
                ['key' => 'acuity', 'header' => 'Acuity', 'align' => 'left'],
                ['key' => 'los', 'header' => 'LOS', 'align' => 'right'],
                ['key' => 'edd', 'header' => 'Expected DC', 'align' => 'right'],
            ],
            'rows' => $rows,
        ];
    }

    private function losDisplay(?int $hours): string
    {
        if ($hours === null) {
            return 'N/A';
        }

        if ($hours >= 48) {
            $days = intdiv($hours, 24);

            return $days.'d '.($hours % 24).'h';
        }

        return $hours.'h';
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
