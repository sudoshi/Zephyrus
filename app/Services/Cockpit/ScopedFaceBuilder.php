<?php

namespace App\Services\Cockpit;

use App\Enums\CockpitStatus;
use App\Models\Barrier;
use App\Models\Encounter;
use App\Services\Mobile\MobilePatientContextService;
use App\Services\Rtdc\BedTrackingService;
use App\Support\Cockpit\CockpitScope;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Support\Facades\DB;

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
    /** The warn threshold occupancyState uses, surfaced as the tile target line. */
    private const OCCUPANCY_TARGET_PCT = 85;

    /** Trailing-24h trend: 12 buckets × 2h, reconstructed from the encounter spine. */
    private const TREND_BUCKETS = 12;

    private const TREND_BUCKET_HOURS = 2;

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

        $unitId = (int) $row['unitId'];
        $roster = $this->unitRoster($unitId);
        $occupiedSeries = $this->occupiedSeries([$unitId])[$unitId] ?? [];
        $movement = $this->movement24h([$unitId]);

        return [
            'scope' => $scope->toArray(),
            'render' => 'face',
            'title' => $scope->label,
            'sub' => $row['type'].' — '.$row['occupancyPct'].'% occupancy',
            'asOf' => now()->toIso8601String(),
            'kpis' => $this->unitKpis($row, $roster, $occupiedSeries, $movement),
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
        $lineUnits = $this->manifest->unitsByServiceLine((string) $scope->key);

        // Procedural platforms (ED, periop) are not census wards — their live face
        // IS the domain drill, never a bed-census card. Exclude them from the
        // rollup so a single-platform line (emergency → ED, perioperative → OR)
        // routes to its drill even when the platform unit still carries staffed
        // bed rows (prod keeps OR beds, so the periop line would otherwise degrade
        // to an honest-but-useless "0% occupancy" card). Same reuse rule as unitFace.
        $censusUnits = array_filter(
            $lineUnits,
            fn (array $u): bool => ! in_array($u['type'] ?? null, ['ed', 'periop'], true),
        );

        // Census rows may be keyed by either the branded abbr or the CAD join key
        // (prod.units.abbreviation differs by which importer wrote it) — roll up both.
        $abbrSet = [];
        foreach ($censusUnits as $u) {
            foreach ($this->unitNameCandidates($u, (string) $u['abbr']) as $candidate) {
                $abbrSet[$candidate] = true;
            }
        }

        $rows = array_values(array_filter(
            $this->liveUnitCensus(),
            fn (array $r): bool => isset($abbrSet[strtoupper((string) $r['name'])]),
        ));

        if ($rows === []) {
            // No census wards → this line's live face is its platform domain drill,
            // same reuse rule as the unit/department mounts.
            $types = array_column($lineUnits, 'type');
            $domain = in_array('ed', $types, true) ? 'ed' : (in_array('periop', $types, true) ? 'periop' : null);
            if ($domain !== null) {
                $drill = $this->drills->build($domain);
                if ($drill !== null) {
                    return ['scope' => $scope->toArray(), 'render' => 'face'] + $drill;
                }
            }

            return $this->emptyFace($scope, 'No live census for this service line yet');
        }

        $staffed = $this->sum($rows, 'staffed');
        $occupied = $this->sum($rows, 'occupied');
        $available = $this->sum($rows, 'available');
        $blocked = $this->sum($rows, 'blocked');
        $occ = $staffed > 0 ? (int) round($occupied / $staffed * 100) : 0;

        $unitIds = array_map(fn (array $r): int => (int) $r['unitId'], $rows);
        $bySeries = $this->occupiedSeries($unitIds);
        $occTrend = $this->occupancyPctSeries($this->sumSeries($bySeries), $staffed);
        $movement = $this->movement24h($unitIds);

        // Per-unit patient-care columns for the units board: which unit needs
        // help, answered without a picker-hop. One grouped query for the line.
        $perUnit = Encounter::query()
            ->active()
            ->whereIn('unit_id', $unitIds)
            ->selectRaw(
                'unit_id,'
                .' count(*) filter (where acuity_tier >= 4) as high_acuity,'
                .' count(*) filter (where expected_discharge_date <= ?) as dc_due',
                [now()->toDateString()],
            )
            ->groupBy('unit_id')
            ->get()
            ->keyBy('unit_id');

        $extras = [];
        foreach ($unitIds as $id) {
            $extras[$id] = [
                'spark' => $bySeries[$id] ?? [],
                'dcDue' => (int) ($perUnit[$id]->dc_due ?? 0),
                'highAcuity' => (int) ($perUnit[$id]->high_acuity ?? 0),
            ];
        }

        // Line-wide patient-care aggregates from the live encounter spine. The
        // altitude model keeps the patient BOARD at the unit mount — a line-wide
        // roster would be a 150-row wall; here the line gets the numbers and each
        // unit row is one picker-hop from its own board.
        $actives = Encounter::query()
            ->active()
            ->whereIn('unit_id', $unitIds)
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
                $this->tile('sl.occupancy', 'Occupancy', $occ, $occ.'%', '%', count($rows).' units', $this->occupancyState($occ), trend: $occTrend, target: self::OCCUPANCY_TARGET_PCT, direction: $this->trendDirection($occTrend, 3.0)),
                $this->tile('sl.census', 'Patients', $actives->count(), (string) $actives->count(), null, $staffed.' staffed beds'),
                $this->tile('sl.available', 'Available', $available, (string) $available, status: $available > 0 ? CockpitStatus::OK : CockpitStatus::WARN),
                $this->tile('sl.blocked', 'Blocked', $blocked, (string) $blocked, status: $blocked > 0 ? CockpitStatus::WARN : CockpitStatus::NORMAL),
                $this->tile('sl.high_acuity', 'High acuity', $highAcuity, (string) $highAcuity, null, 'Tier 4+', $highAcuity > 0 ? CockpitStatus::WATCH : CockpitStatus::NORMAL),
                $this->tile('sl.dc_due', 'DC due today', $dcDue, (string) $dcDue, null, 'Expected discharges', $dcDue > 0 ? CockpitStatus::OK : CockpitStatus::NORMAL),
                $this->tile('sl.alos', 'ALOS (active)', $alosDays ?? 0, $alosDays !== null ? $alosDays.'d' : 'N/A', 'days'),
                $this->flowTile('sl.flow_24h', $movement),
            ],
            'tables' => [$this->boardTable('Units in '.$scope->label, $rows, $extras)],
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
     * @param  list<int>  $occupiedSeries
     * @param  array{in: int, out: int}  $movement
     * @return list<array<string, mixed>>
     */
    private function unitKpis(array $row, $roster, array $occupiedSeries, array $movement): array
    {
        $occ = (int) $row['occupancyPct'];
        $available = (int) $row['available'];
        $blocked = (int) $row['blocked'];
        $occTrend = $this->occupancyPctSeries($occupiedSeries, (int) $row['staffed']);

        $dcDue = $roster
            ->filter(fn (Encounter $e): bool => $e->expected_discharge_date !== null
                && $e->expected_discharge_date->lte(now()->endOfDay()))
            ->count();

        return [
            $this->tile('unit.occupancy', 'Occupancy', $occ, $occ.'%', '%', 'Acuity-adj '.$row['acuityAdjustedPct'].'%', $this->occupancyState($occ), trend: $occTrend, target: self::OCCUPANCY_TARGET_PCT, direction: $this->trendDirection($occTrend, 3.0)),
            $this->tile('unit.staffed', 'Staffed beds', (int) $row['staffed'], (string) $row['staffed']),
            $this->tile('unit.occupied', 'Occupied', (int) $row['occupied'], (string) $row['occupied']),
            $this->tile('unit.available', 'Available', $available, (string) $available, status: $available > 0 ? CockpitStatus::OK : CockpitStatus::WARN),
            $this->tile('unit.blocked', 'Blocked', $blocked, (string) $blocked, status: $blocked > 0 ? CockpitStatus::WARN : CockpitStatus::NORMAL),
            $this->tile('unit.dc_due', 'DC due today', $dcDue, (string) $dcDue, null, 'Expected discharges', $dcDue > 0 ? CockpitStatus::OK : CockpitStatus::NORMAL),
            $this->flowTile('unit.flow_24h', $movement),
        ];
    }

    /**
     * Active encounters on a unit, sickest-first then longest-stay — the roster
     * behind both the patient board and the discharge-due tile. Deliberately
     * de-identified (bed / acuity / LOS / EDD / barrier category only): patient
     * identity stays gated at A2P behind EnforceFlowLens; the drill cell carries
     * only the opaque ptok.
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

        // Open discharge barriers per encounter — the "who is stuck and why"
        // signal. One query for the whole roster; category only (the detail
        // lives at A2P behind the drill, same de-identification rule).
        $barriers = Barrier::query()
            ->where('status', 'open')
            ->where('is_deleted', false)
            ->whereIn('encounter_id', $roster->pluck('encounter_id')->all())
            ->get(['encounter_id', 'category'])
            ->groupBy('encounter_id');

        $rows = $roster->map(function (Encounter $e) use ($today, $barriers): array {
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

            $open = $barriers->get($e->encounter_id);
            $barrierCell = $open === null || $open->isEmpty()
                ? ['v' => '—', 'dim' => true]
                : ['tag' => [
                    'text' => $open->count() > 1
                        ? $open->count().' barriers'
                        : ucfirst((string) $open->first()->category),
                    'status' => 'warning',
                ]];

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
                'barrier' => $barrierCell,
            ];
        })->values()->all();

        return [
            'caption' => 'Patient board',
            'columns' => [
                ['key' => 'bed', 'header' => 'Bed', 'align' => 'left'],
                ['key' => 'acuity', 'header' => 'Acuity', 'align' => 'left'],
                ['key' => 'los', 'header' => 'LOS', 'align' => 'right'],
                ['key' => 'edd', 'header' => 'Expected DC', 'align' => 'right'],
                ['key' => 'barrier', 'header' => 'Barrier', 'align' => 'left'],
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
        array $trend = [],
        int|float|null $target = null,
        string $direction = 'neutral',
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'display' => $display,
            'unit' => $unit,
            'sub' => $sub,
            'status' => $status->value,
            'target' => $target,
            // The client contract (cockpitMetricValueSchema) requires the enum —
            // null fails Zod and blanks the whole mount ("Could not load this mount").
            'direction' => $direction,
            'trend' => $trend,
            'trendLabel' => $trend === [] ? null : 'Last 24h',
            'updatedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * Occupied-bed counts per unit over the trailing 24h, reconstructed from the
     * encounter spine (admitted_at / discharged_at) in 12 two-hour buckets — the
     * census_snapshots table is far too sparse (~daily) to feed a 24h sparkline.
     * Bucket identity travels as the SQL row_number, so no timestamp values
     * round-trip between PHP and PostgreSQL (the NEDOCS TZ lesson).
     *
     * @param  list<int>  $unitIds
     * @return array<int, list<int>> unit_id => occupied count per bucket, oldest first
     */
    private function occupiedSeries(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        $now = now()->format('Y-m-d H:i:s');
        $spanHours = (self::TREND_BUCKETS - 1) * self::TREND_BUCKET_HOURS;
        $stepHours = self::TREND_BUCKET_HOURS;
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));

        $rows = DB::select(
            "with buckets as (
                select g.bucket, row_number() over (order by g.bucket) - 1 as idx
                from generate_series(
                    ?::timestamp - interval '{$spanHours} hours',
                    ?::timestamp,
                    interval '{$stepHours} hours'
                ) as g(bucket)
            )
            select e.unit_id, b.idx, count(*) as occupied
            from buckets b
            join prod.encounters e
              on e.admitted_at <= b.bucket
             and (e.discharged_at is null or e.discharged_at > b.bucket)
             and e.is_deleted = false
             and e.unit_id in ({$placeholders})
            group by e.unit_id, b.idx",
            [$now, $now, ...$unitIds],
        );

        $series = [];
        foreach ($unitIds as $id) {
            $series[$id] = array_fill(0, self::TREND_BUCKETS, 0);
        }
        foreach ($rows as $r) {
            $series[(int) $r->unit_id][(int) $r->idx] = (int) $r->occupied;
        }

        return $series;
    }

    /**
     * @param  list<int>  $occupied
     * @return list<int> occupancy % per bucket; empty when nothing is staffed
     */
    private function occupancyPctSeries(array $occupied, int $staffed): array
    {
        if ($staffed <= 0 || $occupied === []) {
            return [];
        }

        return array_map(fn (int $o): int => (int) round($o / $staffed * 100), $occupied);
    }

    /**
     * @param  array<int, list<int>>  $bySeries
     * @return list<int>
     */
    private function sumSeries(array $bySeries): array
    {
        if ($bySeries === []) {
            return [];
        }

        $sum = array_fill(0, self::TREND_BUCKETS, 0);
        foreach ($bySeries as $series) {
            foreach ($series as $i => $v) {
                $sum[$i] += $v;
            }
        }

        return $sum;
    }

    /**
     * up/down only past the deadband — a flat-ish line stays 'neutral' (earned
     * urgency: the trend arrow is a signal, not decoration).
     */
    private function trendDirection(array $trend, float $deadband): string
    {
        if (count($trend) < 2) {
            return 'neutral';
        }

        $delta = end($trend) - $trend[0];

        if (abs($delta) < $deadband) {
            return 'neutral';
        }

        return $delta > 0 ? 'up' : 'down';
    }

    /**
     * Admissions and discharges touching these units in the trailing 24h.
     *
     * @param  list<int>  $unitIds
     * @return array{in: int, out: int}
     */
    private function movement24h(array $unitIds): array
    {
        if ($unitIds === []) {
            return ['in' => 0, 'out' => 0];
        }

        $since = now()->subDay();

        return [
            'in' => Encounter::query()
                ->where('is_deleted', false)
                ->whereIn('unit_id', $unitIds)
                ->where('admitted_at', '>=', $since)
                ->count(),
            'out' => Encounter::query()
                ->where('is_deleted', false)
                ->whereIn('unit_id', $unitIds)
                ->where('discharged_at', '>=', $since)
                ->count(),
        ];
    }

    /**
     * Net patient flow over the trailing 24h — for an ops leader the net is more
     * actionable than static census, so it earns its own tile on every altitude.
     *
     * @param  array{in: int, out: int}  $movement
     * @return array<string, mixed>
     */
    private function flowTile(string $key, array $movement): array
    {
        $net = $movement['in'] - $movement['out'];

        return $this->tile(
            $key,
            'Net flow 24h',
            $net,
            $net === 0 ? '0' : sprintf('%+d', $net),
            null,
            $movement['in'].' in · '.$movement['out'].' out',
            CockpitStatus::NORMAL,
            direction: $net > 0 ? 'up' : ($net < 0 ? 'down' : 'neutral'),
        );
    }

    /**
     * The §6.4 Cell-grammar capacity board — the same column set as
     * DrillBuilder::unitCapacityBoard, so one React table renders every altitude.
     * With $extras (service-line altitude), each unit row also carries its 24h
     * census spark and the per-unit patient-care counts — "which unit needs
     * help" answered on one board.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, array{spark: list<int>, dcDue: int, highAcuity: int}>  $extras
     * @return array<string, mixed>
     */
    private function boardTable(string $caption, array $rows, array $extras = []): array
    {
        $columns = [
            ['key' => 'unit', 'header' => 'Unit', 'align' => 'left'],
            ['key' => 'type', 'header' => 'Type', 'align' => 'left'],
            ['key' => 'staffed', 'header' => 'Staffed', 'align' => 'right'],
            ['key' => 'occupied', 'header' => 'Occ', 'align' => 'right'],
            ['key' => 'available', 'header' => 'Avail', 'align' => 'right'],
            ['key' => 'blocked', 'header' => 'Blocked', 'align' => 'right'],
            ['key' => 'occupancy', 'header' => 'Occupancy', 'align' => 'left'],
        ];

        if ($extras !== []) {
            $columns[] = ['key' => 'census24h', 'header' => '24h census', 'align' => 'left'];
            $columns[] = ['key' => 'dcDue', 'header' => 'DC due', 'align' => 'right'];
            $columns[] = ['key' => 'highAcuity', 'header' => 'T4+', 'align' => 'right'];
        }

        $columns[] = ['key' => 'status', 'header' => '', 'align' => 'right'];

        return [
            'caption' => $caption,
            'columns' => $columns,
            'rows' => array_map(function (array $u) use ($extras): array {
                $row = [
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
                ];

                $extra = $extras[(int) $u['unitId']] ?? null;
                if ($extra !== null) {
                    $row['census24h'] = count($extra['spark']) >= 2
                        ? ['spark' => ['data' => $extra['spark'], 'status' => (string) $u['status']]]
                        : ['v' => '—', 'dim' => true];
                    // Plain counts, dimmed at zero — a full ICU of T4s is normal,
                    // not an alarm (the occupancy bar owns urgency here).
                    $row['dcDue'] = $extra['dcDue'] === 0 ? ['v' => '0', 'dim' => true] : $extra['dcDue'];
                    $row['highAcuity'] = $extra['highAcuity'] === 0 ? ['v' => '0', 'dim' => true] : $extra['highAcuity'];
                }

                return $row;
            }, $rows),
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
