<?php

namespace App\Services\Operations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the live OR Room-Status board payload for resources/js/Pages/Operations/
 * RoomStatus.jsx, matching the exact shape of the inline `mockRooms` array that
 * page consumed (number/status/currentCase/nextCase/timeRemaining/turnoverTime),
 * computed from the seeded `prod` schema.
 *
 * Sources: prod.rooms, prod.or_cases, prod.or_logs, prod.providers, prod.services.
 *
 * Design notes:
 *  - Deterministic and safe on empty tables (returns an empty rooms list, never
 *    throws). Respects soft deletes (is_deleted = false) everywhere.
 *  - Timezone-safe anchoring: the app runs in UTC while the DB session is local,
 *    so Carbon::today() can disagree with the DB CURRENT_DATE the seeder uses.
 *    We therefore anchor the operative day on MAX(surgery_date) from or_cases —
 *    the most recent fully-seeded OR day — rather than the app's wall-clock date.
 *  - Simulated operative clock: the seeded demo day's cases run a fixed daytime
 *    span. We project the real time-of-day onto the anchor day so the board feels
 *    live, but clamp to a representative mid-operative time (10:30) when the real
 *    time falls outside operating hours, so the board never renders dead.
 *  - prod.case_staff and prod.case_safety_notes are not populated for the demo,
 *    so the staff/resources/notes/alerts sub-objects (which the modal renders)
 *    are synthesized deterministically from the case's real surgeon + case_id.
 *    Patient labels use the de-identified patient_id token (e.g. SIM4622) — never
 *    a real name.
 */
class RoomStatusService
{
    /** Off-hours fallback clock (mid-operative snapshot) on the anchor day. */
    private const CLAMP_HOUR = 10;

    private const CLAMP_MINUTE = 30;

    /** Max gap (minutes) before the next case that still counts as "turnover". */
    private const TURNOVER_WINDOW_MIN = 45;

    /** Elapsed/scheduled ratio beyond which an in-progress case reads as delayed. */
    private const DELAY_RATIO = 1.15;

    /**
     * @return array{rooms:list<array<string,mixed>>,generatedAt:string}
     */
    public function build(): array
    {
        $now = Carbon::now();

        $anchorDate = DB::table('prod.or_cases')
            ->where('is_deleted', false)
            ->max('surgery_date');

        if ($anchorDate === null) {
            return ['rooms' => [], 'generatedAt' => $now->toIso8601String()];
        }

        $day = Carbon::parse($anchorDate)->startOfDay();

        $rooms = DB::table('prod.rooms')
            ->where('is_deleted', false)
            ->where('active_status', true)
            ->where('type', 'OR')
            ->orderBy('room_id')
            ->get(['room_id', 'name']);

        if ($rooms->isEmpty()) {
            return ['rooms' => [], 'generatedAt' => $now->toIso8601String()];
        }

        $cases = DB::table('prod.or_cases as c')
            ->join('prod.or_logs as l', function ($join) {
                $join->on('l.case_id', '=', 'c.case_id')->where('l.is_deleted', false);
            })
            ->join('prod.providers as p', 'p.provider_id', '=', 'c.primary_surgeon_id')
            ->join('prod.services as s', 's.service_id', '=', 'c.case_service_id')
            ->where('c.surgery_date', $anchorDate)
            ->where('c.is_deleted', false)
            ->orderBy('c.room_id')
            ->orderBy('l.or_in_time')
            ->get([
                'c.case_id',
                'c.room_id',
                'c.patient_id',
                'c.scheduled_start_time',
                'c.scheduled_duration',
                'p.name as surgeon',
                's.name as service',
                'l.or_in_time',
                'l.procedure_start_time',
                'l.procedure_end_time',
                'l.or_out_time',
                'l.primary_procedure',
            ]);

        $clock = $this->simulatedClock($now, $day, $cases);
        $byRoom = $cases->groupBy('room_id');

        $out = [];
        foreach ($rooms as $room) {
            /** @var Collection<int,object> $roomCases */
            $roomCases = ($byRoom[$room->room_id] ?? collect())->values();
            $out[] = $this->room($room, $roomCases, $clock);
        }

        return ['rooms' => $out, 'generatedAt' => $now->toIso8601String()];
    }

    /**
     * Project the real time-of-day onto the anchor day, clamped into the
     * operative span so the board never reads dead off-hours.
     *
     * @param  Collection<int,object>  $cases
     */
    private function simulatedClock(Carbon $now, Carbon $day, Collection $cases): Carbon
    {
        $clock = $day->copy()->setTime(
            (int) $now->format('G'),
            (int) $now->format('i'),
            (int) $now->format('s')
        );

        $ins = $cases->pluck('or_in_time')->filter()->map(fn ($t) => Carbon::parse($t));
        $outs = $cases->pluck('or_out_time')->filter()->map(fn ($t) => Carbon::parse($t));

        $spanStart = $ins->min();
        $spanEnd = $outs->max();

        if ($spanStart === null || $spanEnd === null) {
            return $clock;
        }

        // Keep at least one live case before the day fully winds down.
        $busyEnd = $spanEnd->copy()->subMinutes(30);
        if ($busyEnd->lessThanOrEqualTo($spanStart)) {
            $busyEnd = $spanEnd->copy();
        }

        if ($clock->lessThan($spanStart) || $clock->greaterThan($busyEnd)) {
            return $day->copy()->setTime(self::CLAMP_HOUR, self::CLAMP_MINUTE);
        }

        return $clock;
    }

    /**
     * @param  Collection<int,object>  $roomCases
     * @return array<string,mixed>
     */
    private function room(object $room, Collection $roomCases, Carbon $clock): array
    {
        $base = [
            'number' => (int) $room->room_id,
            'status' => 'available',
            'currentCase' => null,
            'nextCase' => null,
            'timeRemaining' => null,
            'turnoverTime' => null,
        ];

        if ($roomCases->isEmpty()) {
            return $base;
        }

        $current = null;
        foreach ($roomCases as $c) {
            if ($c->or_in_time !== null && $c->or_out_time !== null
                && $clock->betweenIncluded(Carbon::parse($c->or_in_time), Carbon::parse($c->or_out_time))) {
                $current = $c;
                break;
            }
        }

        if ($current !== null) {
            return $this->withCurrentCase($base, $current, $clock);
        }

        return $this->withoutCurrentCase($base, $roomCases, $clock);
    }

    /**
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>
     */
    private function withCurrentCase(array $base, object $current, Carbon $clock): array
    {
        $start = Carbon::parse($current->scheduled_start_time);
        $dur = (int) $current->scheduled_duration;
        $procStart = $current->procedure_start_time !== null
            ? Carbon::parse($current->procedure_start_time)
            : Carbon::parse($current->or_in_time);

        $elapsed = max(0, (int) round($procStart->diffInMinutes($clock, false)));
        $remaining = max(0, $dur - $elapsed);
        $delayed = $dur > 0 && $elapsed > $dur * self::DELAY_RATIO;

        $base['status'] = $delayed ? 'delayed' : 'in_progress';
        $base['timeRemaining'] = $remaining;
        $base['currentCase'] = [
            'patient' => (string) $current->patient_id,
            'procedure' => (string) $current->primary_procedure,
            'provider' => (string) $current->surgeon,
            'startTime' => $this->fmt($current->scheduled_start_time),
            'expectedEndTime' => $start->copy()->addMinutes($dur)->format('H:i'),
            'expectedDuration' => $dur,
            'elapsed' => $elapsed,
            'staff' => $this->staff($current),
            'resources' => $this->resources(),
            'notes' => '',
            'alerts' => [],
        ];

        return $base;
    }

    /**
     * Room is between cases: classify as turnover (a case just finished and the
     * next starts within the turnover window) or available, attaching the next
     * scheduled case when one exists.
     *
     * @param  array<string,mixed>  $base
     * @param  Collection<int,object>  $roomCases
     * @return array<string,mixed>
     */
    private function withoutCurrentCase(array $base, Collection $roomCases, Carbon $clock): array
    {
        $next = null;
        foreach ($roomCases as $c) {
            if ($c->or_in_time !== null && Carbon::parse($c->or_in_time)->greaterThan($clock)) {
                $next = $c;
                break;
            }
        }

        $lastDone = null;
        foreach ($roomCases as $c) {
            if ($c->or_out_time !== null && Carbon::parse($c->or_out_time)->lessThanOrEqualTo($clock)) {
                $lastDone = $c;
            }
        }

        if ($next !== null) {
            $minsToNext = (int) round($clock->diffInMinutes(Carbon::parse($next->or_in_time), false));
            if ($lastDone !== null && $minsToNext > 0 && $minsToNext <= self::TURNOVER_WINDOW_MIN) {
                $base['status'] = 'turnover';
                $base['turnoverTime'] = $minsToNext;
            }
            $base['nextCase'] = [
                'startTime' => $this->fmt($next->scheduled_start_time),
                'procedure' => (string) $next->primary_procedure,
            ];
        }

        return $base;
    }

    private function fmt(?string $ts): ?string
    {
        return $ts !== null ? Carbon::parse($ts)->format('H:i') : null;
    }

    /**
     * Deterministic care-team roster (case_staff is unseeded for the demo). The
     * surgeon is the real case surgeon; the rest are stable picks keyed on case_id.
     *
     * @return list<array{name:string,role:string}>
     */
    private function staff(object $c): array
    {
        $anesthesiologists = ['Dr. Patel', 'Dr. Nguyen', 'Dr. Garcia', 'Dr. Cohen'];
        $nurses = ['Mary Johnson', 'Sarah Lee', 'Emily Davis', 'Anna Martins'];
        $i = (int) $c->case_id % 4;

        return [
            ['name' => (string) $c->surgeon, 'role' => 'Surgeon'],
            ['name' => $anesthesiologists[$i], 'role' => 'Anesthesiologist'],
            ['name' => $nurses[$i], 'role' => 'Scrub Nurse'],
        ];
    }

    /**
     * @return list<array{name:string,status:string}>
     */
    private function resources(): array
    {
        return [
            ['name' => 'OR Table', 'status' => 'in_progress'],
            ['name' => 'Anesthesia Machine', 'status' => 'in_progress'],
            ['name' => 'Surgical Tools', 'status' => 'in_progress'],
        ];
    }
}
