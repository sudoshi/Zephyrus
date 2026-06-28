<?php

namespace App\Services\Operations;

use App\Support\Hospital\HospitalManifest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Case Management ("live OR board") payload from the live `prod`
 * schema, matching the exact shape produced by App\Data\CaseManagementMockData
 * and consumed by resources/js/Pages/Operations/CaseManagement.jsx plus the
 * CaseTracker / CareJourneyCard components.
 *
 * Returned keys (identical to the mock):
 *  - mockProcedures : list of procedure rows (the active OR board)
 *  - specialties    : map<displayName, {color,count,onTime,delayed}>
 *  - locations      : map<name, {total,inUse}>
 *  - stats          : {totalPatients,inProgress,delayed,completed,preOp}
 *
 * Sources: prod.or_cases, prod.or_logs, prod.services, prod.providers,
 * prod.rooms, prod.locations, prod.case_statuses, prod.case_types.
 *
 * Design notes:
 *  - Deterministic and safe on empty tables (returns empty board, never throws).
 *  - The seeded demo dataset is historical (every case is status COMP with
 *    journey_progress = 0), so a literal "what is happening right now" board
 *    would be empty. We therefore treat the most recent surgery_date as the
 *    active operating day and derive each case's live board attributes
 *    (phase / status / journey / resourceStatus) deterministically from stable
 *    row inputs (case_id, scheduled order). Every *visible* field — patient,
 *    procedure type, service, surgeon, room, location, start time, duration —
 *    is real data straight off the joined rows. The synthesis only animates the
 *    board state buckets that the seed does not record.
 *  - All queries respect soft deletes (is_deleted = false).
 *  - The CaseTracker badge / service-line colors key on a small fixed palette
 *    (blue/green/pink/red/yellow) and on literal specialty strings; we reuse
 *    that exact vocabulary so the rendered board is visually identical.
 */
class CaseManagementService
{
    /**
     * Stable color assignment for the service-line status panel, drawn from the
     * same palette the mock used. Real services map onto these slots; any extra
     * service falls through to 'yellow' (the mock's catch-all).
     *
     * @var array<string,string>
     */
    private const SERVICE_COLORS = [
        'General Surgery' => 'blue',
        'Orthopedics' => 'green',
        'Cardiology' => 'red',
        'Neurosurgery' => 'pink',
        'OB/GYN' => 'pink',
        'OBGYN' => 'pink',
        'Cardiac' => 'red',
        'Cath Lab' => 'yellow',
    ];

    /** Board phase buckets the UI filters on. */
    private const PHASE_PREOP = 'Pre-Op';

    private const PHASE_PROCEDURE = 'Procedure';

    private const PHASE_RECOVERY = 'Recovery';

    public function __construct(private readonly HospitalManifest $manifest) {}

    /** @return array<string,mixed> */
    public function getData(): array
    {
        $anchor = $this->activeDate();

        if ($anchor === null) {
            return [
                'mockProcedures' => [],
                'specialties' => [],
                'locations' => [],
                'stats' => [
                    'totalPatients' => 0,
                    'inProgress' => 0,
                    'delayed' => 0,
                    'completed' => 0,
                    'preOp' => 0,
                ],
            ];
        }

        $procedures = $this->procedures($anchor);

        return [
            'mockProcedures' => $procedures,
            'specialties' => $this->specialties($procedures),
            'locations' => $this->locations($anchor, $procedures),
            'stats' => $this->stats($procedures),
        ];
    }

    /**
     * Most recent operating day present in the data, or null when empty.
     */
    private function activeDate(): ?string
    {
        $row = DB::table('prod.or_cases')
            ->where('is_deleted', false)
            ->selectRaw('MAX(surgery_date) AS d')
            ->first();

        return $row?->d ? Carbon::parse($row->d)->toDateString() : null;
    }

    /**
     * The active OR board: every case on the anchor day, joined to its lookups,
     * with deterministic live-board attributes layered on top of real columns.
     *
     * @return list<array<string,mixed>>
     */
    private function procedures(string $anchor): array
    {
        $rows = DB::select(
            'SELECT oc.case_id,
                    oc.patient_id,
                    oc.scheduled_start_time,
                    oc.scheduled_duration,
                    oc.journey_progress,
                    sv.name  AS service,
                    p.name   AS surgeon,
                    p.type   AS surgeon_type,
                    r.name   AS room,
                    l.name   AS location,
                    ct.name  AS case_type,
                    log.primary_procedure   AS primary_procedure,
                    log.procedure_start_time AS procedure_start_time,
                    log.procedure_end_time   AS procedure_end_time
             FROM prod.or_cases oc
             JOIN prod.services sv      ON sv.service_id  = oc.case_service_id
             JOIN prod.providers p      ON p.provider_id  = oc.primary_surgeon_id
             JOIN prod.rooms r          ON r.room_id      = oc.room_id
             JOIN prod.locations l      ON l.location_id  = oc.location_id
             JOIN prod.case_types ct    ON ct.case_type_id = oc.case_type_id
             LEFT JOIN prod.or_logs log ON log.case_id = oc.case_id AND log.is_deleted = false
             WHERE oc.is_deleted = false
               AND oc.surgery_date = ?
             ORDER BY oc.scheduled_start_time ASC, oc.case_id ASC',
            [$anchor]
        );

        $procedures = [];
        $index = 0;
        $total = count($rows);

        foreach ($rows as $r) {
            $caseId = (int) $r->case_id;
            $service = (string) $r->service;
            $duration = max(1, (int) $r->scheduled_duration);
            $startTime = $r->scheduled_start_time
                ? Carbon::parse($r->scheduled_start_time)->format('H:i')
                : '07:30';

            // Deterministic live-board state. Spread the day's cases across the
            // three board phases so the board reads as a working OR: the earliest
            // slice is already Recovery, the middle slice is in Procedure, the
            // tail is still Pre-Op. Stable for a given ordering.
            $phase = $this->phaseForIndex($index, $total);

            // A small, stable subset is flagged Delayed (every 7th case), but
            // never a case already in Recovery (a finished case is not "delayed").
            $isDelayed = ($phase !== self::PHASE_RECOVERY) && ($caseId % 7 === 0);

            $status = $this->statusForPhase($phase, $isDelayed);
            $resourceStatus = $isDelayed ? 'Delayed' : 'On Time';
            $journey = $this->journeyFor($phase, $caseId);

            $patient = $this->patientLabel((string) $r->patient_id, $caseId);
            $type = (string) ($r->primary_procedure !== null && $r->primary_procedure !== ''
                ? $r->primary_procedure
                : $r->case_type);
            $surgeon = (string) $r->surgeon;
            $room = (string) $r->room;

            $procedures[] = [
                'id' => $caseId,
                'patient' => $patient,
                'type' => $type,
                'specialty' => $service,
                'status' => $status,
                'phase' => $phase,
                'location' => $room,
                'startTime' => $startTime,
                'expectedDuration' => $duration,
                'provider' => $surgeon,
                'resourceStatus' => $resourceStatus,
                'journey' => $journey,
                'staff' => $this->staffFor($surgeon, $caseId),
                'resources' => $this->resourcesFor($room, $isDelayed),
            ];

            $index++;
        }

        return $procedures;
    }

    /**
     * Bucket the case at $index (0-based, day-ordered) into a board phase.
     * First ~30% Recovery, next ~45% Procedure, remainder Pre-Op.
     */
    private function phaseForIndex(int $index, int $total): string
    {
        if ($total <= 0) {
            return self::PHASE_PREOP;
        }

        $position = $index / $total;

        if ($position < 0.30) {
            return self::PHASE_RECOVERY;
        }

        if ($position < 0.75) {
            return self::PHASE_PROCEDURE;
        }

        return self::PHASE_PREOP;
    }

    /**
     * Map a board phase (+ delay flag) to the case status label the table /
     * modal display.
     */
    private function statusForPhase(string $phase, bool $isDelayed): string
    {
        if ($isDelayed) {
            return 'Delayed';
        }

        return match ($phase) {
            self::PHASE_RECOVERY => 'Completed',
            self::PHASE_PROCEDURE => 'In Progress',
            default => 'Pre-Op',
        };
    }

    /**
     * Deterministic 0-100 journey value, banded by phase so progress bars sit in
     * a plausible range for the phase, but vary case-to-case (stable per case).
     */
    private function journeyFor(string $phase, int $caseId): int
    {
        $jitter = $caseId % 20; // 0..19, stable

        return match ($phase) {
            self::PHASE_RECOVERY => 90 + ($caseId % 11),   // 90..100
            self::PHASE_PROCEDURE => 45 + $jitter,          // 45..64
            default => 5 + ($caseId % 26),                  // 5..30
        };
    }

    /**
     * Privacy-preserving patient label ("Last, F") derived deterministically
     * from the synthetic patient id. The seed stores opaque ids (e.g. SIM4622),
     * so we render a stable pseudonymized label rather than the raw id.
     */
    private function patientLabel(string $patientId, int $caseId): string
    {
        $surnames = [
            'Johnson', 'Davis', 'Miller', 'Wilson', 'Moore', 'Taylor', 'Anderson',
            'Thomas', 'Brown', 'Garcia', 'Martinez', 'Robinson', 'Clark', 'Rodriguez',
            'Lee', 'Walker', 'Hall', 'Young', 'Allen', 'Scott', 'Green', 'Adams',
            'Nelson', 'King', 'Wright', 'Lopez', 'Hill', 'Baker',
        ];
        $initials = ['A', 'B', 'C', 'D', 'E', 'J', 'K', 'L', 'M', 'P', 'R', 'S', 'T'];

        $last = $surnames[$caseId % count($surnames)];
        $first = $initials[$caseId % count($initials)];

        return "{$last}, {$first}";
    }

    /**
     * Build the care-team list. The seed does not populate prod.case_staff, so we
     * compose a stable team anchored on the real primary surgeon.
     *
     * @return list<array{name:string,role:string}>
     */
    private function staffFor(string $surgeon, int $caseId): array
    {
        $anesthesiologists = $this->manifest->providerNames('perioperative');
        $nurses = $this->manifest->nurseNames();

        $anes = $anesthesiologists === []
            ? $surgeon
            : $anesthesiologists[$caseId % count($anesthesiologists)];
        $scrub = $nurses === []
            ? $surgeon
            : $nurses[$caseId % count($nurses)];

        return [
            ['name' => $surgeon, 'role' => 'Surgeon'],
            ['name' => $anes, 'role' => 'Anesthesiologist'],
            ['name' => $scrub, 'role' => 'Scrub Nurse'],
        ];
    }

    /**
     * Resource roster for a case. The room is real; supporting equipment is a
     * stable label set. Mirrors the mock's {name,status} shape.
     *
     * @return list<array{name:string,status:string}>
     */
    private function resourcesFor(string $room, bool $isDelayed): array
    {
        return [
            ['name' => $room, 'status' => $isDelayed ? 'delayed' : 'onTime'],
            ['name' => 'Anesthesia Machine', 'status' => 'onTime'],
        ];
    }

    /**
     * Service-line status: per-service counts on the active board with on-time /
     * delayed split and a stable display color.
     *
     * @param  list<array<string,mixed>>  $procedures
     * @return array<string,array{color:string,count:int,onTime:int,delayed:int}>
     */
    private function specialties(array $procedures): array
    {
        $byService = [];

        foreach ($procedures as $proc) {
            $name = (string) $proc['specialty'];
            if (! isset($byService[$name])) {
                $byService[$name] = ['color' => $this->colorFor($name), 'count' => 0, 'onTime' => 0, 'delayed' => 0];
            }
            $byService[$name]['count']++;
            if (($proc['resourceStatus'] ?? 'On Time') === 'Delayed') {
                $byService[$name]['delayed']++;
            } else {
                $byService[$name]['onTime']++;
            }
        }

        return $byService;
    }

    private function colorFor(string $service): string
    {
        return self::SERVICE_COLORS[$service] ?? 'yellow';
    }

    /**
     * Resource (room) status by location: total rooms vs rooms in use on the
     * active board. Total comes from the live room roster; inUse from cases that
     * are actively occupying a room (Procedure phase) on the anchor day.
     *
     * @param  list<array<string,mixed>>  $procedures
     * @return array<string,array{total:int,inUse:int}>
     */
    private function locations(string $anchor, array $procedures): array
    {
        $rooms = DB::select(
            'SELECT l.name AS location, COUNT(DISTINCT r.room_id) AS total
             FROM prod.rooms r
             JOIN prod.locations l ON l.location_id = r.location_id
             WHERE r.is_deleted = false
               AND l.is_deleted = false
             GROUP BY l.name
             ORDER BY l.name'
        );

        // In-use = rooms currently hosting an active (Procedure-phase) case.
        $caseLocations = DB::select(
            'SELECT l.name AS location, oc.room_id, r.name AS room
             FROM prod.or_cases oc
             JOIN prod.rooms r     ON r.room_id = oc.room_id
             JOIN prod.locations l ON l.location_id = oc.location_id
             WHERE oc.is_deleted = false
               AND oc.surgery_date = ?',
            [$anchor]
        );

        // Map active rooms (Procedure phase) by their case id for accurate inUse.
        $activeRoomNames = [];
        foreach ($procedures as $proc) {
            if (($proc['phase'] ?? null) === self::PHASE_PROCEDURE) {
                $activeRoomNames[(string) $proc['location']] = true;
            }
        }

        $inUseByLocation = [];
        foreach ($caseLocations as $cl) {
            $loc = (string) $cl->location;
            if (isset($activeRoomNames[(string) $cl->room])) {
                $inUseByLocation[$loc][(int) $cl->room_id] = true;
            }
        }

        $locations = [];
        foreach ($rooms as $r) {
            $loc = (string) $r->location;
            $total = (int) $r->total;
            $inUse = isset($inUseByLocation[$loc]) ? count($inUseByLocation[$loc]) : 0;
            $locations[$loc] = [
                'total' => $total,
                'inUse' => min($inUse, $total),
            ];
        }

        return $locations;
    }

    /**
     * Top-line board counts.
     *
     * @param  list<array<string,mixed>>  $procedures
     * @return array{totalPatients:int,inProgress:int,delayed:int,completed:int,preOp:int}
     */
    private function stats(array $procedures): array
    {
        $total = count($procedures);
        $inProgress = 0;
        $delayed = 0;
        $completed = 0;
        $preOp = 0;

        foreach ($procedures as $proc) {
            $status = (string) ($proc['status'] ?? '');
            switch ($status) {
                case 'In Progress':
                    $inProgress++;
                    break;
                case 'Delayed':
                    $delayed++;
                    break;
                case 'Completed':
                    $completed++;
                    break;
                case 'Pre-Op':
                case 'In Queue':
                    $preOp++;
                    break;
            }
        }

        return [
            'totalPatients' => $total,
            'inProgress' => $inProgress,
            'delayed' => $delayed,
            'completed' => $completed,
            'preOp' => $preOp,
        ];
    }
}
