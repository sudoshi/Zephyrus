<?php

namespace App\Services\Flow;

use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Evs\EvsRequest;
use App\Models\ORCase;
use App\Models\RtdcPrediction;
use App\Models\Staffing\StaffingPlan;
use App\Models\Staffing\StaffingRequest;
use App\Models\Transport\TransportRequest;
use App\Models\Unit;
use App\Services\Ed\ArrivalPredictionService;
use App\Services\Mobile\MobilePatientContextService;
use App\Support\Hospital\HospitalManifest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The prediction half of the Flow Window — FLOW-WINDOW-PLAN §6.3 (W3, G5).
 *
 * Composes the EXISTING deterministic services/tables into one +24h stream
 * of typed projection items. Nothing here is a model; every number comes
 * from a service or table that already drives a web surface, and every item
 * carries confidence ∈ {definite, probable, possible} plus provenance
 * {service, reliability} — reliability being the unit's 14-day
 * prediction-vs-actual score from prod.rtdc_reconciliations (D3: the future
 * is defensible or it is not rendered).
 *
 * Identity rule (D3): only KNOWN entities — encounters with an EDD,
 * scheduled OR cases, open transport/EVS/staffing requests — ever appear as
 * per-entity ghosts. Aggregate curves (census, arrivals, surge) carry no
 * entity at all. A future patient identity is never fabricated.
 *
 * The house-scope stream is cached for 5 minutes (plan: skip
 * materialization until profiling says otherwise); scope filtering happens
 * after the cache so every lens shares one computation.
 */
class ForwardProjectionService
{
    private const CACHE_SECONDS = 300;

    /** Discharge time-of-day bell (same trough logic CommandCenterDataService uses). */
    private const DISCHARGE_WINDOWS = [
        'definite' => [11, 14],
        'probable' => [13, 17],
        'possible' => [15, 20],
    ];

    /** @var array<int, ?float> */
    private array $reliabilityByUnit = [];

    /** @var array<int, int> */
    private array $unitFloors = [];

    public function __construct(
        private readonly ArrivalPredictionService $arrivals,
        private readonly MobilePatientContextService $patientContext,
        private readonly HospitalManifest $manifest,
    ) {}

    /**
     * Projection items in [$from, $to] (typically now → now+24h), filtered
     * to a resolved scope and kind list, ordered ascending by t.
     *
     * @param  array{type: string, floor: ?int, unit_id: ?int, patient_ref: ?string}  $scope
     * @param  list<string>  $kinds
     * @return list<array<string, mixed>>
     */
    public function projections(CarbonImmutable $from, CarbonImmutable $to, array $scope, array $kinds, int $limit = 5000): array
    {
        $bucket = $from->format('YmdH').'-'.$to->format('YmdH');

        $stream = Cache::remember(
            "flow:projections:{$bucket}",
            self::CACHE_SECONDS,
            fn (): array => $this->buildHouseStream($from, $to),
        );

        $this->primeUnitFloors();

        return collect($stream)
            ->filter(fn (array $item): bool => in_array($item['kind'], $kinds, true))
            ->filter(fn (array $item): bool => $this->inScope($item, $scope))
            ->sortBy('t')
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> the full house-scope stream */
    private function buildHouseStream(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->primeReliability();

        $discharges = $this->expectedDischarges($from, $to);

        return collect()
            ->concat($discharges)
            ->concat($this->derivedGhosts($discharges, $from, $to))
            ->concat($this->predictedCensus($from, $to))
            ->concat($this->predictedArrivals($from, $to))
            ->concat($this->scheduledOrCases($from, $to))
            ->concat($this->transportDue($from, $to))
            ->concat($this->evsDue($from, $to))
            ->concat($this->staffingShiftGaps($from, $to))
            ->concat($this->surgeProbability($from))
            ->values()
            ->all();
    }

    // -------------------------------------------------------------------
    // expected_discharge — EDD-flagged encounters × rtdc_predictions
    // definite/probable/possible vocabulary × time-of-day bell
    // -------------------------------------------------------------------

    /** @return Collection<int, array<string, mixed>> */
    private function expectedDischarges(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $encounters = Encounter::query()
            ->where('status', 'active')
            ->where('is_deleted', false)
            ->whereNotNull('expected_discharge_date')
            ->whereBetween('expected_discharge_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('unit_id')
            ->orderBy('admitted_at') // longest stay first — most discharge-ready
            ->get(['encounter_id', 'patient_ref', 'unit_id', 'bed_id', 'expected_discharge_date', 'admitted_at']);

        // Per unit + service_date: how many definite/probable/possible the
        // prediction vocabulary allows (by_2pm row carries the day's counts).
        $vocab = RtdcPrediction::query()
            ->whereIn('service_date', array_unique([$from->toDateString(), $to->toDateString()]))
            ->where('horizon', 'by_2pm')
            ->where('is_deleted', false)
            ->get(['unit_id', 'service_date', 'discharges_definite', 'discharges_probable', 'discharges_possible'])
            ->keyBy(fn (RtdcPrediction $p): string => $p->unit_id.'|'.$p->service_date->toDateString());

        $slotIndex = [];

        return $encounters->map(function (Encounter $encounter) use ($vocab, $from, $to, &$slotIndex): ?array {
            $unitId = (int) $encounter->unit_id;
            $date = CarbonImmutable::parse($encounter->expected_discharge_date)->toDateString();
            $key = "{$unitId}|{$date}";

            $row = $vocab->get($key);
            $index = $slotIndex[$key] = ($slotIndex[$key] ?? -1) + 1;

            // Walk the vocabulary: first N definite, next N probable, rest possible.
            $definite = (int) ($row?->discharges_definite ?? 0);
            $probable = (int) ($row?->discharges_probable ?? 0);
            $confidence = match (true) {
                $index < $definite => 'definite',
                $index < $definite + $probable => 'probable',
                default => 'possible',
            };

            $t = $this->dischargeBellTime($date, $confidence, $index);
            if ($t->lt($from)) {
                $t = $from->addMinutes(30 + 15 * ($index % 8)); // overdue-today EDDs project just ahead
            }
            if ($t->gt($to)) {
                return null;
            }

            return $this->item(
                t: $t,
                kind: 'expected_discharge',
                confidence: $confidence,
                unitId: $unitId,
                entity: ['type' => 'encounter', 'ref' => (string) $encounter->encounter_id],
                patientRef: $encounter->patient_ref,
                bedId: $encounter->bed_id !== null ? (int) $encounter->bed_id : null,
                label: 'Expected discharge',
                service: 'rtdc_predictions × encounter EDD',
                reliability: $this->reliabilityByUnit[$unitId] ?? null,
            );
        })->filter()->values();
    }

    /**
     * Derived ghosts (D3's chain): each definite/probable expected discharge
     * spawns a likely discharge transport at t and a bed turn at t+20m, one
     * confidence notch lower. Same known entity — nothing fabricated.
     *
     * @param  Collection<int, array<string, mixed>>  $discharges
     * @return Collection<int, array<string, mixed>>
     */
    private function derivedGhosts(Collection $discharges, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $downgrade = ['definite' => 'probable', 'probable' => 'possible'];

        return $discharges
            ->filter(fn (array $item): bool => isset($downgrade[$item['confidence']]))
            ->flatMap(function (array $discharge) use ($downgrade, $to): array {
                $t = CarbonImmutable::parse($discharge['t']);
                $confidence = $downgrade[$discharge['confidence']];
                $ghosts = [];

                $ghosts[] = $this->item(
                    t: $t,
                    kind: 'transport_due',
                    confidence: $confidence,
                    unitId: $discharge['unit_id'],
                    entity: $discharge['entity'],
                    patientRef: $discharge['_patient_ref'],
                    bedId: $discharge['bed_id'],
                    label: 'Likely discharge transport',
                    service: 'derived · expected_discharge',
                    reliability: $discharge['provenance']['reliability'],
                    derived: true,
                );

                $turnAt = $t->addMinutes(20);
                if ($turnAt->lte($to)) {
                    $ghosts[] = $this->item(
                        t: $turnAt,
                        kind: 'evs_due',
                        confidence: $confidence,
                        unitId: $discharge['unit_id'],
                        entity: $discharge['bed_id'] !== null
                            ? ['type' => 'bed', 'ref' => (string) $discharge['bed_id']]
                            : $discharge['entity'],
                        patientRef: null, // the turn is about the bed, not the person
                        bedId: $discharge['bed_id'],
                        label: 'Bed turn after discharge',
                        service: 'derived · expected_discharge',
                        reliability: $discharge['provenance']['reliability'],
                        derived: true,
                    );
                }

                return $ghosts;
            })->values();
    }

    // -------------------------------------------------------------------
    // predicted_census — per unit at 2h steps (DemandForecastService's
    // vocabulary walked across the day with the CommandCenter bells)
    // -------------------------------------------------------------------

    /** @return Collection<int, array<string, mixed>> */
    private function predictedCensus(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $units = Unit::with(['beds' => fn ($q) => $q->where('is_deleted', false)])
            ->where('is_deleted', false)
            ->where('type', '<>', 'ed')
            ->get();

        $dates = array_values(array_unique([$from->toDateString(), $to->toDateString()]));
        $predictions = RtdcPrediction::query()
            ->whereIn('service_date', $dates)
            ->where('is_deleted', false)
            ->get()
            ->groupBy(fn (RtdcPrediction $p): string => $p->unit_id.'|'.$p->service_date->toDateString());

        $items = collect();
        foreach ($units as $unit) {
            $unitId = (int) $unit->unit_id;
            $occupiedNow = $unit->beds->where('status', 'occupied')->count();
            $reliability = $this->reliabilityByUnit[$unitId] ?? null;

            for ($t = $from->addHours(2 - ($from->hour % 2))->startOfHour(); $t->lte($to); $t = $t->addHours(2)) {
                $date = $t->toDateString();
                $dayRows = $predictions->get("{$unitId}|{$date}", collect());
                $extrapolated = false;

                // Cross-midnight honesty: if tomorrow has no seeded predictions,
                // extrapolate today's shape and mark the item 'possible'.
                if ($dayRows->isEmpty() && $date !== $from->toDateString()) {
                    $dayRows = $predictions->get("{$unitId}|{$from->toDateString()}", collect());
                    $extrapolated = true;
                }
                if ($dayRows->isEmpty()) {
                    continue;
                }

                $demand = (int) $dayRows->sum('demand_expected');
                $weightedDc = (float) $dayRows->sum('discharges_weighted');

                $hour = $t->hour + $t->minute / 60;
                $inflow = $demand * $this->bellCumulative($hour, 14, 20);
                $outflow = $weightedDc * $this->bellCumulative($hour, 10, 14);
                $value = max(0, (int) round($occupiedNow + $inflow - $outflow));

                $spread = max(1, (int) round(abs($inflow - $outflow) * (1 - ($reliability ?? 0.8))) + 1);
                $hoursOut = $from->diffInHours($t);

                $items->push($this->item(
                    t: $t,
                    kind: 'predicted_census',
                    confidence: $extrapolated ? 'possible' : ($hoursOut <= 12 ? 'probable' : 'possible'),
                    unitId: $unitId,
                    entity: null,
                    patientRef: null,
                    bedId: null,
                    label: 'Predicted census',
                    service: $extrapolated ? 'demand_forecast (extrapolated past midnight)' : 'demand_forecast',
                    reliability: $reliability,
                    value: $value,
                    band: ['lower' => max(0, $value - $spread), 'upper' => $value + $spread],
                ));
            }
        }

        return $items;
    }

    // -------------------------------------------------------------------
    // predicted_arrivals — ArrivalPredictionService's hourly forecast + band
    // -------------------------------------------------------------------

    /** @return Collection<int, array<string, mixed>> */
    private function predictedArrivals(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $edUnitId = Unit::where('type', 'ed')->where('is_deleted', false)->value('unit_id');
        $edUnitId = $edUnitId !== null ? (int) $edUnitId : null;
        $houseReliability = $this->houseReliability();

        $forecast = $this->arrivals->build()['forecast'] ?? [];
        $items = collect();

        foreach ($forecast as $index => $slot) {
            $t = $from->startOfHour()->addHours($index + 1);
            if ($t->lt($from) || $t->gt($to)) {
                continue;
            }

            $items->push($this->item(
                t: $t,
                kind: 'predicted_arrivals',
                confidence: $index < 6 ? 'probable' : 'possible',
                unitId: $edUnitId,
                entity: null,
                patientRef: null,
                bedId: null,
                label: 'Predicted ED arrivals',
                service: 'ed_arrival_prediction',
                reliability: $houseReliability,
                value: (int) ($slot['predicted'] ?? 0),
                band: ['lower' => (int) ($slot['lower'] ?? 0), 'upper' => (int) ($slot['upper'] ?? 0)],
            ));
        }

        return $items;
    }

    // -------------------------------------------------------------------
    // scheduled_or_case — the OR schedule projected onto today (same
    // operative-day anchor RoomStatusService uses)
    // -------------------------------------------------------------------

    /** @return Collection<int, array<string, mixed>> */
    private function scheduledOrCases(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('prod.or_cases')) {
            return collect(); // periop import is optional; degrade to an empty lane
        }

        $anchor = ORCase::query()->where('is_deleted', false)->max('surgery_date');
        if ($anchor === null) {
            return collect();
        }

        $cases = ORCase::query()
            ->with(['room', 'service'])
            ->where('is_deleted', false)
            ->whereDate('surgery_date', $anchor)
            ->whereNotNull('scheduled_start_time')
            ->orderBy('scheduled_start_time')
            ->limit(500)
            ->get();

        $today = $from->toDateString();

        return $cases->map(function (ORCase $case) use ($today, $from, $to): ?array {
            $start = CarbonImmutable::parse($today.' '.CarbonImmutable::parse($case->scheduled_start_time)->format('H:i:s'));
            $ends = $start->addMinutes(max(30, (int) ($case->scheduled_duration ?? 90)));
            if ($ends->lt($from) || $start->gt($to)) {
                return null;
            }

            return $this->item(
                t: $start,
                kind: 'scheduled_or_case',
                confidence: 'definite', // it is on the schedule; delays shift it, not erase it
                unitId: null,
                entity: ['type' => 'or_case', 'ref' => (string) $case->case_id],
                patientRef: null, // OR lens is patient_dots:none; never tokenizes here
                bedId: null,
                label: trim(($case->room?->name ? $case->room->name.' · ' : '').($case->service?->name ?? 'Scheduled case')),
                service: 'or_schedule',
                reliability: null,
                endsAt: $ends,
            );
        })->filter()->values();
    }

    // -------------------------------------------------------------------
    // transport_due / evs_due / staffing_shift_gap — deadline projections
    // -------------------------------------------------------------------

    /** @return Collection<int, array<string, mixed>> */
    private function transportDue(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return TransportRequest::query()
            ->whereNotIn('status', ['completed', 'canceled', 'failed'])
            ->where('is_deleted', false)
            ->whereBetween('needed_at', [$from, $to])
            ->limit(2000)
            ->get()
            ->map(fn (TransportRequest $request): array => $this->item(
                t: CarbonImmutable::parse($request->needed_at),
                kind: 'transport_due',
                confidence: 'definite',
                unitId: null,
                entity: ['type' => 'transport', 'ref' => (string) $request->transport_request_id],
                patientRef: $request->patient_ref,
                bedId: null,
                label: 'Transport due · '.$request->origin.' → '.$request->destination,
                service: 'transport_requests',
                reliability: null,
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function evsDue(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return EvsRequest::query()
            ->whereIn('status', ['requested', 'queued', 'assigned', 'in_progress'])
            ->where('is_deleted', false)
            ->whereBetween('needed_at', [$from, $to])
            ->limit(2000)
            ->get()
            ->map(fn (EvsRequest $request): array => $this->item(
                t: CarbonImmutable::parse($request->needed_at),
                kind: 'evs_due',
                confidence: 'definite',
                unitId: $request->unit_id !== null ? (int) $request->unit_id : null,
                entity: ['type' => 'evs', 'ref' => (string) $request->evs_request_id],
                patientRef: $request->patient_ref,
                bedId: $request->bed_id !== null ? (int) $request->bed_id : null,
                label: 'Turn due · '.$request->location_label,
                service: 'evs_requests',
                reliability: null,
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function staffingShiftGaps(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $items = StaffingRequest::query()
            ->whereIn('status', ['requested', 'open', 'sourcing', 'assigned'])
            ->where('is_deleted', false)
            ->whereBetween('needed_by', [$from, $to])
            ->limit(2000)
            ->get()
            ->map(fn (StaffingRequest $request): array => $this->item(
                t: CarbonImmutable::parse($request->needed_by),
                kind: 'staffing_shift_gap',
                confidence: 'definite',
                unitId: $request->unit_id !== null ? (int) $request->unit_id : null,
                entity: ['type' => 'staffing', 'ref' => (string) $request->staffing_request_id],
                patientRef: null,
                bedId: null,
                label: ucfirst((string) $request->role).' needed · '.$request->unit_label,
                service: 'staffing_requests',
                reliability: null,
                value: (int) $request->headcount_needed,
            ));

        // Plan-level gap steps at shift boundaries — P10's coverage-vs-curve view.
        $shiftStart = ['day' => 7, 'evening' => 15, 'night' => 19];
        $plans = StaffingPlan::query()
            ->whereIn('status', ['gap', 'critical_gap'])
            ->where('is_deleted', false)
            ->whereBetween('shift_date', [$from->toDateString(), $to->toDateString()])
            ->limit(2000)
            ->get();

        foreach ($plans as $plan) {
            $t = CarbonImmutable::parse($plan->shift_date->toDateString())
                ->setHour($shiftStart[$plan->shift] ?? 7);
            if ($t->lt($from) || $t->gt($to)) {
                continue;
            }

            $items->push($this->item(
                t: $t,
                kind: 'staffing_shift_gap',
                confidence: 'probable',
                unitId: $plan->unit_id !== null ? (int) $plan->unit_id : null,
                entity: ['type' => 'staffing_plan', 'ref' => (string) $plan->staffing_plan_id],
                patientRef: null,
                bedId: null,
                label: ucfirst((string) $plan->role).' below safe · '.$plan->unit_label,
                service: 'staffing_plans',
                reliability: null,
                value: (int) $plan->scheduled_count - (int) $plan->minimum_safe_count,
            ));
        }

        return $items;
    }

    // -------------------------------------------------------------------
    // surge_probability — the shared SurgeHeuristic on the same inputs the
    // Command Center feeds it (netBedsNow = available − pending admits)
    // -------------------------------------------------------------------

    /** @return Collection<int, array<string, mixed>> */
    private function surgeProbability(CarbonImmutable $from): Collection
    {
        $beds = Bed::query()->where('is_deleted', false)
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'occupied') AS occupied,
                         COUNT(*) FILTER (WHERE status = 'available') AS available,
                         COUNT(*) AS total")
            ->first();

        $staffed = (int) Unit::where('is_deleted', false)->sum('staffed_bed_count');
        $occupied = (int) ($beds->occupied ?? 0);
        $available = (int) ($beds->available ?? 0);
        $occupancyPct = $staffed > 0 ? (int) round(100 * $occupied / $staffed) : 0;
        $netBedsNow = $available - \App\Models\BedRequest::pending()->count();

        $row = RtdcPrediction::query()
            ->where('service_date', $from->toDateString())
            ->where('horizon', 'by_2pm')
            ->where('is_deleted', false)
            ->selectRaw('SUM(demand_expected) AS adm, SUM(discharges_weighted) AS wdc')
            ->first();
        $predAdmissions = (int) round((float) ($row->adm ?? 0));
        $sumWtDc = (float) ($row->wdc ?? 0);
        $reliability = $this->houseReliability() ?? 0.8;

        $surgePct = \App\Support\SurgeHeuristic::pressures(
            $occupancyPct, $netBedsNow, $predAdmissions, $sumWtDc, $reliability
        )['surge_pct'];

        return collect([$this->item(
            t: $from,
            kind: 'surge_probability',
            confidence: 'probable',
            unitId: null,
            entity: null,
            patientRef: null,
            bedId: null,
            label: 'Surge probability · next 24h',
            service: 'command_center_surge_heuristic',
            reliability: $reliability,
            value: $surgePct,
        )]);
    }

    // -------------------------------------------------------------------
    // Shared plumbing
    // -------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function item(
        CarbonImmutable $t,
        string $kind,
        string $confidence,
        ?int $unitId,
        ?array $entity,
        ?string $patientRef,
        ?int $bedId,
        string $label,
        string $service,
        ?float $reliability,
        ?int $value = null,
        ?array $band = null,
        ?CarbonImmutable $endsAt = null,
        bool $derived = false,
    ): array {
        $ptok = $patientRef !== null ? $this->patientContext->contextRefFor($patientRef) : null;

        return [
            't' => $t->toIso8601String(),
            'kind' => $kind,
            'confidence' => $confidence,
            'unit_id' => $unitId,
            'bed_id' => $bedId,
            'entity' => $entity,
            'patient_context_ref' => $ptok,
            '_patient_ref' => $patientRef, // internal; stripped by the controller
            'label' => $label,
            'value' => $value,
            'band' => $band,
            'ends_at' => $endsAt?->toIso8601String(),
            'derived' => $derived,
            'provenance' => [
                'service' => $service,
                'reliability' => $reliability !== null ? round($reliability, 2) : null,
            ],
        ];
    }

    /** @param array{type: string, floor: ?int, unit_id: ?int, patient_ref: ?string} $scope */
    private function inScope(array $item, array $scope): bool
    {
        return match ($scope['type']) {
            'house' => true,
            // Aggregate house-level items (surge, arrivals with no unit) stay
            // visible on narrower scopes only when they belong to the floor/unit.
            'floor' => $item['unit_id'] !== null
                && ($this->unitFloors[$item['unit_id']] ?? null) === $scope['floor'],
            'unit' => $item['unit_id'] === $scope['unit_id'],
            'patient' => $item['_patient_ref'] !== null && $item['_patient_ref'] === $scope['patient_ref'],
            default => false,
        };
    }

    private function dischargeBellTime(string $date, string $confidence, int $index): CarbonImmutable
    {
        [$startHour, $endHour] = self::DISCHARGE_WINDOWS[$confidence];
        $spreadMinutes = ($endHour - $startHour) * 60;
        $offset = ($index * 37) % max(1, $spreadMinutes); // deterministic fan-out, no RNG

        return CarbonImmutable::parse($date)->setHour($startHour)->addMinutes($offset);
    }

    /** Cumulative share (0…1) of a bell centered on [$peakStart, $peakEnd] realized by $hour. */
    private function bellCumulative(float $hour, int $peakStart, int $peakEnd): float
    {
        $center = ($peakStart + $peakEnd) / 2;
        $width = max(2, ($peakEnd - $peakStart)) * 1.5;

        // Logistic CDF — smooth, deterministic, sums to ~1 across the day.
        return 1 / (1 + exp(-($hour - $center) / ($width / 4)));
    }

    private function primeReliability(): void
    {
        $rows = DB::table('prod.rtdc_reconciliations')
            ->where('service_date', '>=', now()->subDays(14)->toDateString())
            ->whereNotNull('reliability_score')
            ->selectRaw('unit_id, AVG(reliability_score) AS score')
            ->groupBy('unit_id')
            ->get();

        foreach ($rows as $row) {
            $this->reliabilityByUnit[(int) $row->unit_id] = (float) $row->score;
        }
    }

    private function houseReliability(): ?float
    {
        if ($this->reliabilityByUnit === []) {
            $this->primeReliability();
        }

        return $this->reliabilityByUnit !== []
            ? array_sum($this->reliabilityByUnit) / count($this->reliabilityByUnit)
            : null;
    }

    private function primeUnitFloors(): void
    {
        if ($this->unitFloors !== []) {
            return;
        }

        foreach (Unit::where('is_deleted', false)->get(['unit_id', 'abbreviation']) as $unit) {
            $entry = $unit->abbreviation ? $this->manifest->unit($unit->abbreviation) : null;
            if (isset($entry['floor'])) {
                $this->unitFloors[(int) $unit->unit_id] = (int) $entry['floor'];
            }
        }
    }
}
