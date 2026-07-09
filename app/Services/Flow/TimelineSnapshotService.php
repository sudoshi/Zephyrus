<?php

namespace App\Services\Flow;

use App\Models\Bed;
use App\Models\CensusSnapshot;
use App\Models\Encounter;
use App\Models\OperationalEvent;
use App\Models\PatientFlow\OccupancySnapshot;
use App\Models\Unit;
use App\Services\AcuityService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Hourly checkpoints for the Flow Window's review half —
 * FLOW-WINDOW-PLAN §6.2 (W2) and D2 ("past = checkpoint + replay").
 *
 * capture()  writes one prod.census_snapshots row per unit for "now" (the
 *            same counting convention as CensusProjector::snapshot) plus a
 *            per-unit-space flow_core.occupancy_snapshots row — the table
 *            finally earns its keep as the space-time checkpoint store.
 *
 * backfill() replays prod.operational_events in memory (never mutating the
 *            materialized read model — that is CensusRebuilder's job) and
 *            writes the hourly checkpoints for the trailing window, so the
 *            feature works the moment it ships and against freshly seeded
 *            demo databases.
 */
class TimelineSnapshotService
{
    public function __construct(private readonly AcuityService $acuity) {}

    /**
     * Capture the live checkpoint for every active unit. Idempotent per
     * unit-hour: re-runs within the same hour update in place.
     *
     * @return int number of unit checkpoints written
     */
    public function capture(?CarbonInterface $at = null): int
    {
        $at = CarbonImmutable::parse($at ?? now())->startOfHour();
        $units = Unit::with(['beds' => fn ($q) => $q->where('is_deleted', false)])
            ->where('is_deleted', false)
            ->get();

        $acuityByUnit = Encounter::query()
            ->where('status', 'active')
            ->where('is_deleted', false)
            ->whereNotNull('unit_id')
            ->selectRaw('unit_id, acuity_tier, COUNT(*) AS n')
            ->groupBy('unit_id', 'acuity_tier')
            ->get()
            ->groupBy('unit_id');

        $encountersByUnit = Encounter::query()
            ->with('bed')
            ->where('status', 'active')
            ->where('is_deleted', false)
            ->whereNotNull('unit_id')
            ->get()
            ->groupBy('unit_id');

        $written = 0;
        foreach ($units as $unit) {
            $occupied = $unit->beds->where('status', 'occupied')->count();
            $available = $unit->beds->where('status', 'available')->count();
            $blocked = $unit->beds->whereIn('status', ['blocked', 'dirty'])->count();
            $details = $this->liveOccupancyDetails(
                unit: $unit,
                encounters: $encountersByUnit[$unit->unit_id] ?? collect(),
                capturedAt: $at,
            );
            $timerStatusCounts = $this->timerStatusCounts($details, $occupied);
            $serviceLineCounts = $this->serviceLineCounts($unit, count($details), $occupied);
            $serviceLineTimerCounts = $this->serviceLineTimerCounts($unit, $timerStatusCounts);

            $this->writeCheckpoint(
                unit: $unit,
                capturedAt: $at,
                occupied: $occupied,
                available: $available,
                blocked: $blocked,
                acuityAdjusted: $this->acuity->adjustedCapacity((int) $unit->unit_id),
                acuityCounts: ($acuityByUnit[$unit->unit_id] ?? collect())
                    ->mapWithKeys(fn ($row): array => [(string) $row->acuity_tier => (int) $row->n])
                    ->all(),
                serviceLineCounts: $serviceLineCounts,
                occupancyDetails: $details,
                timerStatusCounts: $timerStatusCounts,
                serviceLineTimerCounts: $serviceLineTimerCounts,
                personaTimerCounts: $this->personaTimerCounts($details, $occupied, $blocked),
                projectionWindow: $this->projectionWindow($at, $details),
            );
            $written++;
        }

        return $written;
    }

    /**
     * Rebuild hourly checkpoints for the trailing window by replaying
     * prod.operational_events in memory. Beds start 'available' at the head
     * of the stream — the same implicit convention CensusRebuilder uses.
     *
     * acuity_adjusted_capacity for historical hours is stored as staffed
     * beds (the acuity mix at past instants is not replayable cheaply);
     * scrubbing uses occupied/available/blocked, so this is cosmetic.
     *
     * @return int number of unit-hour checkpoints written
     */
    public function backfill(int $hours = 24, ?CarbonInterface $until = null): int
    {
        $end = CarbonImmutable::parse($until ?? now())->startOfHour();
        $start = $end->subHours(max(1, $hours));

        $units = Unit::where('is_deleted', false)->get()->keyBy('unit_id');
        $bedUnit = Bed::where('is_deleted', false)->pluck('unit_id', 'bed_id')
            ->map(fn ($unitId): int => (int) $unitId)->all();

        // Per-unit counters, advanced incrementally as bed statuses change.
        $counts = [];
        foreach ($units as $unitId => $unit) {
            $counts[$unitId] = ['occupied' => 0, 'blocked' => 0];
        }
        $bedStatus = []; // bed_id => status (unseen beds are 'available')
        $encounterBed = []; // patient_ref => bed_id

        $applyBedStatus = function (int $bedId, string $to) use (&$bedStatus, &$counts, $bedUnit): void {
            $unitId = $bedUnit[$bedId] ?? null;
            if ($unitId === null || ! isset($counts[$unitId])) {
                return;
            }
            $from = $bedStatus[$bedId] ?? 'available';
            foreach ([[$from, -1], [$to, 1]] as [$status, $delta]) {
                if ($status === 'occupied') {
                    $counts[$unitId]['occupied'] += $delta;
                } elseif ($status === 'blocked' || $status === 'dirty') {
                    $counts[$unitId]['blocked'] += $delta;
                }
            }
            $bedStatus[$bedId] = $to;
        };

        $written = 0;
        $boundary = $start;
        $flushThrough = function (CarbonImmutable $upTo) use (&$boundary, &$counts, &$written, $units, $end): void {
            while ($boundary->lte($upTo) && $boundary->lte($end)) {
                foreach ($units as $unitId => $unit) {
                    $occupied = max(0, $counts[$unitId]['occupied']);
                    $blocked = max(0, $counts[$unitId]['blocked']);
                    $available = max(0, (int) $unit->staffed_bed_count - $occupied - $blocked);

                    $this->writeCheckpoint(
                        unit: $unit,
                        capturedAt: $boundary,
                        occupied: $occupied,
                        available: $available,
                        blocked: $blocked,
                        acuityAdjusted: (int) $unit->staffed_bed_count,
                        acuityCounts: [],
                        timerStatusCounts: ['ok' => $occupied, 'watch' => 0, 'delayed' => 0],
                        personaTimerCounts: $this->personaTimerCounts([], $occupied, $blocked),
                    );
                    $written++;
                }
                $boundary = $boundary->addHour();
            }
        };

        OperationalEvent::query()
            ->orderBy('occurred_at')
            ->orderBy('operational_event_id')
            ->chunk(1000, function ($events) use (&$encounterBed, $applyBedStatus, $flushThrough): void {
                foreach ($events as $event) {
                    $flushThrough(CarbonImmutable::parse($event->occurred_at)->subSecond()->startOfHour());

                    $payload = is_array($event->payload) ? $event->payload : (array) json_decode((string) $event->payload, true);
                    $ref = $event->encounter_ref;

                    switch ($event->type) {
                        case 'EncounterStarted':
                            if (($bedId = $payload['bed_id'] ?? null) !== null) {
                                $applyBedStatus((int) $bedId, 'occupied');
                                if ($ref !== null) {
                                    $encounterBed[$ref] = (int) $bedId;
                                }
                            }
                            break;
                        case 'EncounterTransferred':
                            if ($ref !== null && isset($encounterBed[$ref])) {
                                $applyBedStatus($encounterBed[$ref], 'dirty');
                            }
                            if (($bedId = $payload['to_bed_id'] ?? null) !== null) {
                                $applyBedStatus((int) $bedId, 'occupied');
                                if ($ref !== null) {
                                    $encounterBed[$ref] = (int) $bedId;
                                }
                            }
                            break;
                        case 'EncounterDischarged':
                            if ($ref !== null && isset($encounterBed[$ref])) {
                                $applyBedStatus($encounterBed[$ref], 'dirty');
                                unset($encounterBed[$ref]);
                            }
                            break;
                        case 'BedStatusChanged':
                            if (($bedId = $payload['bed_id'] ?? null) !== null && isset($payload['status'])) {
                                $applyBedStatus((int) $bedId, (string) $payload['status']);
                            }
                            break;
                    }
                }
            });

        $flushThrough($end);

        return $written;
    }

    private function writeCheckpoint(
        Unit $unit,
        CarbonInterface $capturedAt,
        int $occupied,
        int $available,
        int $blocked,
        int $acuityAdjusted,
        array $acuityCounts,
        array $serviceLineCounts = [],
        array $occupancyDetails = [],
        array $timerStatusCounts = [],
        array $serviceLineTimerCounts = [],
        array $personaTimerCounts = [],
        array $activeBlockerCounts = [],
        array $projectionWindow = [],
    ): void {
        CensusSnapshot::updateOrCreate(
            ['unit_id' => $unit->unit_id, 'captured_at' => $capturedAt],
            [
                'staffed_beds' => (int) $unit->staffed_bed_count,
                'occupied' => $occupied,
                'available' => $available,
                'blocked' => $blocked,
                'acuity_adjusted_capacity' => $acuityAdjusted,
            ],
        );

        if ($unit->facility_space_id !== null) {
            OccupancySnapshot::updateOrCreate(
                ['snapshot_at' => $capturedAt, 'facility_space_id' => $unit->facility_space_id],
                [
                    'active_patient_count' => $occupied,
                    'service_line_counts' => $serviceLineCounts,
                    'acuity_counts' => $acuityCounts,
                    'occupancy_details' => $occupancyDetails,
                    'timer_status_counts' => $timerStatusCounts ?: ['ok' => $occupied, 'watch' => 0, 'delayed' => 0],
                    'service_line_timer_counts' => $serviceLineTimerCounts,
                    'persona_timer_counts' => $personaTimerCounts ?: ['transport' => 0, 'evs' => 0, 'bed_manager' => 0, 'capacity' => 0],
                    'active_blocker_counts' => $activeBlockerCounts,
                    'projection_window' => $projectionWindow,
                ],
            );
        }
    }

    private function liveOccupancyDetails(Unit $unit, iterable $encounters, CarbonImmutable $capturedAt): array
    {
        $serviceLine = $this->serviceLine($unit);

        return collect($encounters)
            ->map(function (Encounter $encounter) use ($unit, $capturedAt, $serviceLine): array {
                $admittedAt = $encounter->admitted_at ? CarbonImmutable::parse($encounter->admitted_at) : null;
                $elapsedMinutes = $admittedAt ? max(0, $admittedAt->diffInMinutes($capturedAt, false)) : null;
                $expectedDischarge = $encounter->expected_discharge_date
                    ? CarbonImmutable::parse($encounter->expected_discharge_date)->endOfDay()
                    : null;
                $timerStatus = $this->timerStatus($capturedAt, $admittedAt, $expectedDischarge, (int) $unit->access_standard_minutes);

                return [
                    'unit_id' => (int) $unit->unit_id,
                    'unit' => $unit->abbreviation ?: $unit->name,
                    'bed_id' => $encounter->bed_id ? (int) $encounter->bed_id : null,
                    'bed_label' => $encounter->bed?->label,
                    'service_line' => $serviceLine,
                    'patient_ref' => (string) $encounter->patient_ref,
                    'encounter_id' => (int) $encounter->encounter_id,
                    'acuity_tier' => (int) $encounter->acuity_tier,
                    'admitted_at' => $admittedAt?->toIso8601String(),
                    'elapsed_minutes' => $elapsedMinutes,
                    'expected_discharge_date' => $encounter->expected_discharge_date?->toDateString(),
                    'anticipated_move' => $expectedDischarge && $expectedDischarge->lte($capturedAt->addDay())
                        ? 'planned_discharge'
                        : null,
                    'primary_status' => $timerStatus,
                    'timer_status' => $timerStatus,
                    'source' => [
                        'schema' => 'prod',
                        'table' => 'encounters',
                        'captured_at' => $capturedAt->toIso8601String(),
                        'source_mode' => 'snapshot',
                    ],
                ];
            })
            ->values()
            ->all();
    }

    private function timerStatus(
        CarbonImmutable $capturedAt,
        ?CarbonImmutable $admittedAt,
        ?CarbonImmutable $expectedDischarge,
        int $accessStandardMinutes,
    ): string {
        if ($expectedDischarge && $expectedDischarge->lt($capturedAt)) {
            return 'delayed';
        }

        if ($expectedDischarge && $expectedDischarge->lte($capturedAt->addHours(12))) {
            return 'watch';
        }

        if ($admittedAt && $accessStandardMinutes > 0
            && $admittedAt->diffInMinutes($capturedAt, false) >= $accessStandardMinutes) {
            return 'watch';
        }

        return 'ok';
    }

    private function timerStatusCounts(array $details, int $occupied): array
    {
        $counts = ['ok' => 0, 'watch' => 0, 'delayed' => 0];
        foreach ($details as $detail) {
            $status = in_array($detail['timer_status'] ?? null, ['ok', 'watch', 'delayed'], true)
                ? $detail['timer_status']
                : 'ok';
            $counts[$status]++;
        }

        if ($occupied > count($details)) {
            $counts['ok'] += $occupied - count($details);
        }

        return $counts;
    }

    private function serviceLineCounts(Unit $unit, int $detailCount, int $occupied): array
    {
        $count = max($detailCount, $occupied);

        return $count > 0 ? [$this->serviceLine($unit) => $count] : [];
    }

    private function serviceLineTimerCounts(Unit $unit, array $timerStatusCounts): array
    {
        return array_sum($timerStatusCounts) > 0
            ? [$this->serviceLine($unit) => $timerStatusCounts]
            : [];
    }

    private function personaTimerCounts(array $details, int $occupied, int $blocked): array
    {
        $watchOrDelayed = collect($details)
            ->filter(fn (array $detail): bool => in_array($detail['timer_status'] ?? null, ['watch', 'delayed'], true))
            ->count();

        return [
            'transport' => collect($details)->where('anticipated_move', 'planned_discharge')->count(),
            'evs' => $blocked,
            'bed_manager' => $watchOrDelayed,
            'capacity' => $occupied,
        ];
    }

    private function projectionWindow(CarbonImmutable $capturedAt, array $details): array
    {
        return [
            'from' => $capturedAt->toIso8601String(),
            'to' => $capturedAt->addDay()->toIso8601String(),
            'anticipated_discharges' => collect($details)->where('anticipated_move', 'planned_discharge')->count(),
            'source_mode' => 'snapshot',
        ];
    }

    private function serviceLine(Unit $unit): string
    {
        return $unit->type ?: 'unassigned';
    }
}
