<?php

declare(strict_types=1);

namespace App\Services\Ed;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the ED Triage board payload from the live `prod.ed_visits` table.
 *
 * The triage board surfaces the cohort of patients currently in the department
 * (arrived, not yet dispositioned, not yet departed), ordered by acuity (ESI)
 * then by how long they have been waiting. KPI tiles summarise the queue;
 * supporting series describe the ESI mix and the longest current waits.
 *
 * Fields with no source system in prod.ed_visits — chief complaint, triage
 * room, and patient display name — are DETERMINISTICALLY enriched per
 * ed_visit_id (a stable CRC32 hash, never random) so the demo is stable across
 * page loads. Query idioms mirror App\Services\Dashboard\EdDashboardService.
 *
 * Deterministic and safe on empty tables: every accessor returns zeros / empty
 * arrays rather than throwing. is_deleted = false is enforced everywhere.
 */
class TriageService
{
    /** ED unit identifier (prod.units: "Emergency Department", type 'ed'). */
    private const ED_UNIT_ID = 1;

    /**
     * ESI -> presentation metadata. The targetMinutes column encodes the
     * door-to-provider expectation per acuity tier (ESI-1 is immediate).
     *
     * @var array<int,array{label:string,targetMinutes:int}>
     */
    private const ESI_META = [
        1 => ['label' => 'Resuscitation', 'targetMinutes' => 0],
        2 => ['label' => 'Emergent', 'targetMinutes' => 15],
        3 => ['label' => 'Urgent', 'targetMinutes' => 30],
        4 => ['label' => 'Less Urgent', 'targetMinutes' => 60],
        5 => ['label' => 'Non-Urgent', 'targetMinutes' => 120],
    ];

    /**
     * Deterministic chief-complaint pools keyed by ESI level. A stable hash of
     * ed_visit_id selects one entry, so a given visit always shows the same
     * clinically-plausible complaint for its acuity.
     *
     * @var array<int,list<string>>
     */
    private const COMPLAINTS = [
        1 => ['Cardiac Arrest', 'Major Trauma', 'Respiratory Failure', 'Unresponsive'],
        2 => ['Chest Pain', 'Stroke Symptoms', 'Severe Dyspnea', 'Sepsis', 'Altered Mental Status'],
        3 => ['Abdominal Pain', 'Fever', 'Flank Pain', 'Severe Headache', 'Vomiting'],
        4 => ['Laceration', 'Minor Injury', 'Back Pain', 'Sprain', 'Ear Pain'],
        5 => ['Medication Refill', 'Suture Removal', 'Rash', 'Cold Symptoms', 'Dental Pain'],
    ];

    /** Deterministic triage-room labels. */
    private const TRIAGE_ROOMS = ['Triage A', 'Triage B', 'Triage C', 'Fast Track 1', 'Fast Track 2'];

    /**
     * Assemble the full Triage board payload.
     *
     * @return array{
     *     kpis: array<string,int>,
     *     esiBreakdown: list<array{esi:int,label:string,count:int,targetMinutes:int,overTarget:int,medianWaitMinutes:?int}>,
     *     longestWaits: list<array<string,mixed>>,
     *     queue: list<array<string,mixed>>,
     *     generatedAt: string
     * }
     */
    public function build(): array
    {
        $now = $this->anchorNow();

        $queue = $this->triageQueue($now);
        $esiBreakdown = $this->esiBreakdown($queue);
        $longestWaits = $this->longestWaits($queue);
        $kpis = $this->kpis($queue, $now);

        return [
            'kpis' => $kpis,
            'esiBreakdown' => $esiBreakdown,
            'longestWaits' => $longestWaits,
            'queue' => $queue,
            'generatedAt' => $now->toIso8601String(),
        ];
    }

    // -----------------------------------------------------------------------
    // Time anchor
    // -----------------------------------------------------------------------

    /**
     * The reference "now" for wait-timer math.
     *
     * The seeded ED cohort is anchored to a rolling window whose arrivals can
     * sit slightly ahead of wall-clock time; using bare Carbon::now() would
     * filter the entire active cohort out (arrived_at <= now drops everything).
     * We therefore anchor to the latest arrival in the table when it is in the
     * future, and fall back to wall-clock now for an empty / fully-historical
     * table. Deterministic for a given dataset, never throws.
     */
    private function anchorNow(): Carbon
    {
        $wallNow = Carbon::now();

        $latest = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->whereNull('departed_at')
            ->max('arrived_at');

        if ($latest === null) {
            return $wallNow;
        }

        $latestCarbon = Carbon::parse($latest);

        return $latestCarbon->greaterThan($wallNow) ? $latestCarbon : $wallNow;
    }

    // -----------------------------------------------------------------------
    // Queue (patients currently in the department)
    // -----------------------------------------------------------------------

    /**
     * In-department cohort ordered by acuity then by descending wait, each row
     * enriched with a deterministic chief complaint, triage room, and display
     * name. Status is derived from real timestamps (triage / provider state).
     *
     * @return list<array{
     *     id:string,
     *     patientRef:string,
     *     patientName:string,
     *     esi:int,
     *     esiLabel:string,
     *     chiefComplaint:string,
     *     triageRoom:string,
     *     arrivedAt:string,
     *     waitMinutes:int,
     *     doorToTriage:int|null,
     *     status:string,
     *     statusTone:string,
     *     overTarget:bool
     * }>
     */
    private function triageQueue(Carbon $now): array
    {
        $rows = DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->whereNull('departed_at')
            ->whereNull('disposition')
            ->get([
                'ed_visit_id',
                'patient_ref',
                'esi_level',
                'arrived_at',
                'triaged_at',
                'provider_seen_at',
            ]);

        $queue = [];
        foreach ($rows as $row) {
            $id = (int) $row->ed_visit_id;
            $esi = $row->esi_level === null ? 3 : max(1, min(5, (int) $row->esi_level));
            $meta = self::ESI_META[$esi];

            $arrived = Carbon::parse($row->arrived_at);
            $waitMinutes = max(0, (int) round($arrived->diffInMinutes($now, false)));

            $doorToTriage = $row->triaged_at !== null
                ? max(0, (int) round($arrived->diffInMinutes(Carbon::parse($row->triaged_at), false)))
                : null;

            [$status, $statusTone] = $this->statusFor($row, $waitMinutes, $meta['targetMinutes']);

            $queue[] = [
                'id' => 'V'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
                'patientRef' => (string) $row->patient_ref,
                'patientName' => $this->patientName($id),
                'esi' => $esi,
                'esiLabel' => $meta['label'],
                'chiefComplaint' => $this->chiefComplaint($id, $esi),
                'triageRoom' => $this->triageRoom($id),
                'arrivedAt' => $arrived->toIso8601String(),
                'waitMinutes' => $waitMinutes,
                'doorToTriage' => $doorToTriage,
                'status' => $status,
                'statusTone' => $statusTone,
                'overTarget' => $esi > 1 && $waitMinutes > $meta['targetMinutes'],
            ];
        }

        // Acuity ascending (ESI-1 first), then longest wait first.
        usort($queue, static function (array $a, array $b): int {
            return $a['esi'] <=> $b['esi']
                ?: $b['waitMinutes'] <=> $a['waitMinutes'];
        });

        return $queue;
    }

    /**
     * Workflow status + tone derived from real triage / provider timestamps.
     *
     * @param  object{triaged_at:?string,provider_seen_at:?string}  $row
     * @return array{0:string,1:string} [label, tone] where tone is one of
     *                                  critical|warning|success|info
     */
    private function statusFor(object $row, int $waitMinutes, int $targetMinutes): array
    {
        if ($row->triaged_at === null) {
            // Not yet triaged — the highest-priority queue state.
            return ['Awaiting Triage', 'critical'];
        }

        if ($row->provider_seen_at === null) {
            // Triaged, still waiting on a provider; escalate tone past target.
            $tone = ($targetMinutes > 0 && $waitMinutes > $targetMinutes) ? 'warning' : 'info';

            return ['Awaiting Provider', $tone];
        }

        return ['In Treatment', 'success'];
    }

    // -----------------------------------------------------------------------
    // ESI breakdown + longest waits
    // -----------------------------------------------------------------------

    /**
     * ESI-level counts across the active queue, one entry per level 1-5 so the
     * chart always renders a full axis even when a tier is empty.
     *
     * @param  list<array{esi:int}>  $queue
     * @return list<array{esi:int,label:string,count:int,targetMinutes:int}>
     */
    private function esiBreakdown(array $queue): array
    {
        $counts = array_fill(1, 5, 0);
        $breaches = array_fill(1, 5, 0);
        $waits = array_fill(1, 5, []);
        foreach ($queue as $row) {
            $counts[$row['esi']]++;
            $waits[$row['esi']][] = (int) $row['waitMinutes'];
            if ($row['overTarget']) {
                $breaches[$row['esi']]++;
            }
        }

        $out = [];
        foreach (self::ESI_META as $esi => $meta) {
            $out[] = [
                'esi' => $esi,
                'label' => $meta['label'],
                'count' => $counts[$esi],
                'targetMinutes' => $meta['targetMinutes'],
                // Per-tier accountability: how many are over THEIR tier's
                // door-to-provider target, and the tier's median current wait —
                // "2 Emergent over 15m" is the actionable read, not raw counts.
                'overTarget' => $breaches[$esi],
                'medianWaitMinutes' => $this->median($waits[$esi]),
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1
            ? $values[$mid]
            : (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }

    /**
     * The five longest-waiting patients in the queue (already sorted by acuity
     * then wait; here we re-rank purely by wait time for the watch-list).
     *
     * @param  list<array<string,mixed>>  $queue
     * @return list<array{id:string,patientName:string,esi:int,esiLabel:string,chiefComplaint:string,waitMinutes:int,overTarget:bool}>
     */
    private function longestWaits(array $queue): array
    {
        $byWait = $queue;
        usort($byWait, static fn (array $a, array $b): int => $b['waitMinutes'] <=> $a['waitMinutes']);

        return array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'patientName' => $row['patientName'],
                'esi' => $row['esi'],
                'esiLabel' => $row['esiLabel'],
                'chiefComplaint' => $row['chiefComplaint'],
                'waitMinutes' => $row['waitMinutes'],
                'overTarget' => $row['overTarget'],
            ],
            array_slice($byWait, 0, 5)
        );
    }

    // -----------------------------------------------------------------------
    // KPI tiles
    // -----------------------------------------------------------------------

    /**
     * Headline tiles for the triage board.
     *
     * @param  list<array<string,mixed>>  $queue
     * @return array{
     *     inQueue:int,
     *     awaitingTriage:int,
     *     awaitingProvider:int,
     *     highAcuity:int,
     *     longestWaitMinutes:int,
     *     medianDoorToTriage:int,
     *     overTarget:int
     * }
     */
    private function kpis(array $queue, Carbon $now): array
    {
        $inQueue = count($queue);
        $awaitingTriage = 0;
        $awaitingProvider = 0;
        $highAcuity = 0;
        $longestWait = 0;
        $overTarget = 0;

        foreach ($queue as $row) {
            if ($row['status'] === 'Awaiting Triage') {
                $awaitingTriage++;
            } elseif ($row['status'] === 'Awaiting Provider') {
                $awaitingProvider++;
            }
            if ($row['esi'] <= 2) {
                $highAcuity++;
            }
            if ($row['waitMinutes'] > $longestWait) {
                $longestWait = $row['waitMinutes'];
            }
            if ($row['overTarget']) {
                $overTarget++;
            }
        }

        return [
            'inQueue' => $inQueue,
            'awaitingTriage' => $awaitingTriage,
            'awaitingProvider' => $awaitingProvider,
            'highAcuity' => $highAcuity,
            'longestWaitMinutes' => $longestWait,
            'medianDoorToTriage' => $this->medianDoorToTriage($now),
            'overTarget' => $overTarget,
        ];
    }

    /**
     * Median door-to-triage (minutes) over arrivals in the last 24h. Returns 0
     * when no triaged visits exist in the window. Safe on empty tables.
     */
    private function medianDoorToTriage(Carbon $now): int
    {
        $window = $now->copy()->subHours(24);

        $median = DB::selectOne(
            'SELECT CAST(percentile_cont(0.5) WITHIN GROUP (
                        ORDER BY EXTRACT(EPOCH FROM triaged_at - arrived_at) / 60
                    ) FILTER (WHERE triaged_at IS NOT NULL) AS integer) AS d2t
             FROM prod.ed_visits
             WHERE is_deleted = false
               AND arrived_at >= ?
               AND arrived_at <= ?',
            [$window->toDateTimeString(), $now->toDateTimeString()]
        );

        return max(0, (int) ($median->d2t ?? 0));
    }

    // -----------------------------------------------------------------------
    // Deterministic enrichment (stable per ed_visit_id, never random)
    // -----------------------------------------------------------------------

    /**
     * Stable non-negative hash for a visit id, optionally salted so different
     * attributes draw from independent but reproducible sequences.
     */
    private function hash(int $id, string $salt): int
    {
        return (int) (crc32($salt.':'.$id) & 0x7FFFFFFF);
    }

    /** Deterministic chief complaint plausible for the visit's acuity. */
    private function chiefComplaint(int $id, int $esi): string
    {
        $pool = self::COMPLAINTS[$esi];

        return $pool[$this->hash($id, 'complaint') % count($pool)];
    }

    /** Deterministic triage-room assignment. */
    private function triageRoom(int $id): string
    {
        return self::TRIAGE_ROOMS[$this->hash($id, 'room') % count(self::TRIAGE_ROOMS)];
    }

    /**
     * Deterministic, de-identified display name. ed_visits carries no PII, so a
     * stable initial + masked id keeps the board human-readable without
     * inventing real identities.
     */
    private function patientName(int $id): string
    {
        $initials = range('A', 'Z');
        $first = $initials[$this->hash($id, 'first') % 26];
        $last = $initials[$this->hash($id, 'last') % 26];

        return sprintf('Patient %s.%s. #%04d', $first, $last, $id % 10000);
    }
}
