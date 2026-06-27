<?php

declare(strict_types=1);

namespace App\Services\Ed;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the ED Treatment board payload from the live `prod` schema.
 *
 * The treatment cohort is every ED patient who has been seen by a provider
 * (provider_seen_at set) but has not yet departed (departed_at null). For each
 * such visit we surface elapsed-in-treatment, disposition status, and a
 * deterministically enriched treatment room / chief complaint / care team /
 * pending orders (no source system exists for those fields, so they are derived
 * from a stable hash of ed_visit_id — identical on every render, never random).
 *
 * Deterministic and safe on empty tables (returns zeros / empty arrays, never
 * throws). Query idioms mirror App\Services\Dashboard\EdDashboardService.
 *
 * Returns:
 *   kpis      — headline tiles (inTreatment, awaitingDisposition, boarding,
 *               medianTreatmentTime) each with a label + trend hint.
 *   board     — one row per in-treatment visit (sorted by acuity then dwell).
 *   acuityMix — ESI distribution of the treatment cohort (chart series).
 *   meta      — generatedAt + cohort size for the frontend.
 */
class TreatmentService
{
    /** ED unit identifier (prod.units: "Emergency Department", type 'ed'). */
    private const ED_UNIT_ID = 1;

    /** Care areas keyed by ESI band — drives deterministic room assignment. */
    private const AREA_BY_ESI = [
        1 => 'Trauma',
        2 => 'Trauma',
        3 => 'Acute',
        4 => 'Fast Track',
        5 => 'Fast Track',
    ];

    /** Bed counts per care area (caps the deterministic room number). */
    private const AREA_BEDS = [
        'Trauma' => 4,
        'Acute' => 12,
        'Fast Track' => 8,
    ];

    /** Chief-complaint pools by ESI band (clinically plausible, deterministic). */
    private const COMPLAINTS_BY_ESI = [
        1 => ['Cardiac Arrest', 'Major Trauma', 'Respiratory Failure'],
        2 => ['Chest Pain', 'Stroke Symptoms', 'Severe Dyspnea', 'Sepsis'],
        3 => ['Abdominal Pain', 'Fever', 'Flank Pain', 'Headache'],
        4 => ['Laceration', 'Minor Injury', 'Back Pain', 'Sprain'],
        5 => ['Medication Refill', 'Suture Removal', 'Rash', 'Cold Symptoms'],
    ];

    /** Attending pool — deterministic per visit. */
    private const PROVIDERS = [
        'Dr. Smith', 'Dr. Johnson', 'Dr. Brown', 'Dr. Davis', 'Dr. Patel', 'Dr. Nguyen',
    ];

    /** Nurse pool — deterministic per visit. */
    private const NURSES = [
        'RN Carter', 'RN Lopez', 'RN Adams', 'RN Reed', 'RN Khan', 'RN Owens',
    ];

    /**
     * Assemble the full Treatment board payload.
     *
     * @return array{
     *     kpis: array<string,array{value:int|string,label:string,trend:string,context:string}>,
     *     board: list<array<string,mixed>>,
     *     acuityMix: list<array{esi:string,label:string,count:int}>,
     *     meta: array{generatedAt:string,cohortSize:int}
     * }
     */
    public function build(): array
    {
        $now = Carbon::now();

        $rows = $this->treatmentCohort($now);
        $board = $this->board($rows, $now);
        $kpis = $this->kpis($board);
        $acuityMix = $this->acuityMix($board);

        return [
            'kpis' => $kpis,
            'board' => $board,
            'acuityMix' => $acuityMix,
            'meta' => [
                'generatedAt' => $now->toIso8601String(),
                'cohortSize' => count($board),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Cohort query
    // -----------------------------------------------------------------------

    /**
     * Visits in active treatment: provider has been seen, patient not departed.
     *
     * Sorted by acuity (ESI ascending, nulls last) then longest dwell first so
     * the most urgent / longest-waiting patients surface at the top of the board.
     *
     * @return \Illuminate\Support\Collection<int,object>
     */
    private function treatmentCohort(Carbon $now)
    {
        // NB: ed_visits are NOT filtered by unit_id — the seed assigns a spread
        // of unit_ids (and many nulls) to ED arrivals, mirroring how
        // App\Services\Dashboard\EdDashboardService scopes the ED cohort by the
        // disposition/timestamp lifecycle rather than by unit.
        return DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->where('arrived_at', '<=', $now)
            ->whereNotNull('provider_seen_at')
            ->whereNull('departed_at')
            ->orderByRaw('CASE WHEN esi_level IS NULL THEN 9 ELSE esi_level END ASC')
            ->orderBy('provider_seen_at', 'asc')
            ->get([
                'ed_visit_id',
                'patient_ref',
                'esi_level',
                'arrived_at',
                'provider_seen_at',
                'disposition',
                'admit_decision_at',
                'bed_assigned_at',
            ]);
    }

    // -----------------------------------------------------------------------
    // Board rows (live timestamps + deterministic enrichment)
    // -----------------------------------------------------------------------

    /**
     * Map each cohort row to a board entry. Elapsed values are clamped to >= 0
     * (seeded data straddles wall-clock now, so a provider_seen_at can be in the
     * future for not-yet-"happened" rows — clamp keeps the demo sane).
     *
     * @param  \Illuminate\Support\Collection<int,object>  $rows
     * @return list<array<string,mixed>>
     */
    private function board($rows, Carbon $now): array
    {
        $board = [];

        foreach ($rows as $row) {
            $id = (int) $row->ed_visit_id;
            $esi = (int) ($row->esi_level ?? 3);
            $esi = max(1, min(5, $esi));

            $arrived = Carbon::parse($row->arrived_at);
            $seen = Carbon::parse($row->provider_seen_at);

            $treatmentMinutes = max(0, (int) round($seen->diffInMinutes($now, false)));
            $totalLosMinutes = max(0, (int) round($arrived->diffInMinutes($now, false)));

            [$status, $statusTone] = $this->statusFor($row);
            $room = $this->treatmentRoom($id, $esi);
            $complaint = $this->chiefComplaint($id, $esi);
            $provider = self::PROVIDERS[$this->hash($id, 'md') % count(self::PROVIDERS)];
            $nurse = self::NURSES[$this->hash($id, 'rn') % count(self::NURSES)];
            $orders = $this->pendingOrders($id, $esi, $status);

            $board[] = [
                'id' => 'V'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
                'edVisitId' => $id,
                'room' => $room,
                'chiefComplaint' => $complaint,
                'esiLevel' => $esi,
                'treatmentMinutes' => $treatmentMinutes,
                'losMinutes' => $totalLosMinutes,
                'status' => $status,
                'statusTone' => $statusTone,
                'provider' => $provider,
                'nurse' => $nurse,
                'pendingOrders' => $orders,
            ];
        }

        return $board;
    }

    /**
     * Disposition status + a status tone token the frontend maps to a
     * healthcare-* color. Boarding (admitted, no bed) is the worst case.
     *
     * @return array{0:string,1:string} [label, tone]
     */
    private function statusFor(object $row): array
    {
        $disposition = $row->disposition;

        if ($disposition === 'admitted' && $row->bed_assigned_at === null) {
            return ['Boarding', 'critical'];
        }
        if ($disposition === 'admitted') {
            return ['Admitted', 'info'];
        }
        if ($disposition === 'transfer') {
            return ['Transfer Pending', 'warning'];
        }
        if ($disposition === 'discharged') {
            return ['Discharge Ready', 'success'];
        }
        if ($row->admit_decision_at !== null) {
            return ['Admit Decision', 'warning'];
        }

        return ['In Treatment', 'info'];
    }

    // -----------------------------------------------------------------------
    // Deterministic enrichment (stable per ed_visit_id — never random)
    // -----------------------------------------------------------------------

    /**
     * Stable non-negative hash bucket for a visit id + salt. Uses crc32 so the
     * value is identical across requests, processes, and PHP builds.
     */
    private function hash(int $id, string $salt): int
    {
        return crc32($salt.':'.$id) % 100000;
    }

    /**
     * Deterministic treatment-room label (care area + bed number) for a visit.
     * Bed number is bounded by the area's bed count so rooms stay plausible.
     */
    private function treatmentRoom(int $id, int $esi): string
    {
        $area = self::AREA_BY_ESI[$esi] ?? 'Acute';
        $beds = self::AREA_BEDS[$area] ?? 8;
        $bed = ($this->hash($id, 'room') % $beds) + 1;

        return sprintf('%s %02d', $area, $bed);
    }

    /** Deterministic chief complaint drawn from the ESI-appropriate pool. */
    private function chiefComplaint(int $id, int $esi): string
    {
        $pool = self::COMPLAINTS_BY_ESI[$esi] ?? self::COMPLAINTS_BY_ESI[3];

        return $pool[$this->hash($id, 'cc') % count($pool)];
    }

    /**
     * Deterministic pending-orders list. Boarding/admit cases always carry the
     * inpatient-handoff order; everything else gets a stable 0-2 work items.
     *
     * @return list<string>
     */
    private function pendingOrders(int $id, int $esi, string $status): array
    {
        if ($status === 'Boarding' || $status === 'Admit Decision' || $status === 'Admitted') {
            return ['Inpatient bed request', 'Admission orders'];
        }
        if ($status === 'Discharge Ready') {
            return ['Discharge instructions'];
        }

        $catalog = [
            'CBC + BMP',
            'Troponin',
            'CT head',
            'Chest X-ray',
            'IV fluids',
            'Pain management',
            'Wound care',
            'Cardiac monitor',
        ];

        $count = $this->hash($id, 'ordc') % 3; // 0, 1, or 2 orders
        if ($count === 0) {
            return [];
        }

        $orders = [];
        for ($i = 0; $i < $count; $i++) {
            $orders[] = $catalog[$this->hash($id, 'ord'.$i) % count($catalog)];
        }

        return array_values(array_unique($orders));
    }

    // -----------------------------------------------------------------------
    // KPIs
    // -----------------------------------------------------------------------

    /**
     * Headline tiles computed from the board. Trend hints are derived from the
     * counts themselves (deterministic), not historical comparison.
     *
     * @param  list<array<string,mixed>>  $board
     * @return array<string,array{value:int|string,label:string,trend:string,context:string}>
     */
    private function kpis(array $board): array
    {
        $inTreatment = count($board);

        $awaitingDisposition = 0;
        $boarding = 0;
        $times = [];

        foreach ($board as $entry) {
            $times[] = (int) $entry['treatmentMinutes'];

            if ($entry['status'] === 'In Treatment') {
                $awaitingDisposition++;
            }
            if ($entry['status'] === 'Boarding') {
                $boarding++;
            }
        }

        $median = $this->median($times);

        return [
            'inTreatment' => [
                'value' => $inTreatment,
                'label' => 'In Treatment',
                'trend' => $inTreatment > 0 ? 'up' : 'flat',
                'context' => 'Patients with a provider, not yet departed',
            ],
            'awaitingDisposition' => [
                'value' => $awaitingDisposition,
                'label' => 'Awaiting Disposition',
                'trend' => $awaitingDisposition > 0 ? 'down' : 'flat',
                'context' => 'Seen, no disposition decision yet',
            ],
            'boarding' => [
                'value' => $boarding,
                'label' => 'Boarding',
                'trend' => $boarding > 0 ? 'down' : 'flat',
                'context' => 'Admitted, awaiting inpatient bed',
            ],
            'medianTreatmentTime' => [
                'value' => $median,
                'label' => 'Median Treatment Time',
                'trend' => $median > 120 ? 'down' : 'up',
                'context' => 'Minutes since provider first seen',
            ],
        ];
    }

    /**
     * Integer median of a list (0 on empty). Pure, deterministic.
     *
     * @param  list<int>  $values
     */
    private function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (int) $values[$mid];
        }

        return (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }

    // -----------------------------------------------------------------------
    // Acuity mix (chart series)
    // -----------------------------------------------------------------------

    /**
     * ESI distribution of the treatment cohort for the acuity chart. Always
     * returns all five bands (zero-filled) so the chart axis is stable.
     *
     * @param  list<array<string,mixed>>  $board
     * @return list<array{esi:string,label:string,count:int}>
     */
    private function acuityMix(array $board): array
    {
        $labels = [
            1 => 'ESI 1 — Resuscitation',
            2 => 'ESI 2 — Emergent',
            3 => 'ESI 3 — Urgent',
            4 => 'ESI 4 — Less Urgent',
            5 => 'ESI 5 — Non-Urgent',
        ];

        $counts = array_fill(1, 5, 0);
        foreach ($board as $entry) {
            $esi = (int) $entry['esiLevel'];
            if (isset($counts[$esi])) {
                $counts[$esi]++;
            }
        }

        $out = [];
        foreach ($labels as $esi => $label) {
            $out[] = [
                'esi' => 'ESI '.$esi,
                'label' => $label,
                'count' => $counts[$esi],
            ];
        }

        return $out;
    }
}
