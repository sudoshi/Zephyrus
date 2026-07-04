<?php

namespace App\Services\Ed;

use App\Services\Rtdc\HouseCensusService;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Zephyrus 2.0 P7 (ED) — the live NEDOCS composite (National ED Overcrowding
 * Score), retiring the seeded demo constant.
 *
 * Weiss et al. (2004), the canonical formula:
 *
 *   NEDOCS = −20
 *          + 85.8 · (total ED patients / ED bed capacity)
 *          + 600  · (ED admits / hospital bed capacity)
 *          + 13.4 · (ventilated ED patients)
 *          + 0.93 · (longest admit-hold, hours)
 *          + 5.64 · (last bedded patient's waiting-room time, hours)
 *
 * clamped to the reported 0–200 scale. Coefficients are named constants so the
 * score is auditable against the literature. Every input comes from live
 * prod.ed_visits / the house census / the manifest — nothing is fabricated.
 */
class NedocsService
{
    private const C_INTERCEPT = -20.0;

    private const C_CROWDING = 85.8;

    private const C_ADMITS = 600.0;

    private const C_VENT = 13.4;

    private const C_LONGEST_ADMIT = 0.93;

    private const C_LAST_BED = 5.64;

    private const SCALE_MAX = 200.0;

    /** ED visits arriving further back than this are no longer "in department". */
    private const LOOKBACK_HOURS = 36;

    public function __construct(
        private readonly HospitalManifest $manifest,
        private readonly HouseCensusService $house,
    ) {}

    /**
     * The clamped 0–200 composite. Returns null only when the ED bed capacity
     * is unknown (the crowding term would divide by zero) — the caller then
     * skips the tile rather than showing a bogus score.
     */
    public function score(): ?float
    {
        $inputs = $this->inputs();

        if ($inputs === null) {
            return null;
        }

        $raw = self::C_INTERCEPT
            + self::C_CROWDING * ($inputs['total_patients'] / $inputs['ed_beds'])
            + self::C_ADMITS * ($inputs['admits'] / max(1, $inputs['hospital_beds']))
            + self::C_VENT * $inputs['ventilated']
            + self::C_LONGEST_ADMIT * $inputs['longest_admit_hrs']
            + self::C_LAST_BED * $inputs['last_bed_hrs'];

        return round(max(0.0, min(self::SCALE_MAX, $raw)));
    }

    /** The Weiss band label for a score, per the published cut points. */
    public function band(float $score): string
    {
        return match (true) {
            $score <= 20 => 'Not busy',
            $score <= 60 => 'Busy',
            $score <= 100 => 'Extremely busy',
            $score <= 140 => 'Overcrowded',
            $score <= 180 => 'Severe',
            default => 'Dangerous',
        };
    }

    /**
     * The five live NEDOCS inputs + their derived score, for the ED drill.
     *
     * @return array{
     *     total_patients:int, ed_beds:int, admits:int, hospital_beds:int,
     *     ventilated:int, longest_admit_hrs:float, last_bed_hrs:float
     * }|null
     */
    public function inputs(): ?array
    {
        $edBeds = $this->edBedCapacity();

        if ($edBeds === null || $edBeds <= 0) {
            return null;
        }

        $now = Carbon::now();
        $nowTs = $now->toDateTimeString();
        $since = $now->copy()->subHours(self::LOOKBACK_HOURS)->toDateTimeString();

        // Bind PHP now() as a ?::timestamp rather than calling SQL now(): the
        // columns are `timestamp` (no zone) written in the app timezone, and
        // the DB session timezone may differ — SQL now() would skew the
        // elapsed by that offset.
        $census = DB::selectOne(
            'SELECT
                 COUNT(*) FILTER (WHERE departed_at IS NULL) AS total_patients,
                 COUNT(*) FILTER (WHERE departed_at IS NULL AND is_ventilated = true) AS ventilated,
                 COUNT(*) FILTER (
                     WHERE disposition = ?
                       AND bed_assigned_at IS NULL
                       AND departed_at IS NULL
                 ) AS admits,
                 MAX(EXTRACT(EPOCH FROM (?::timestamp - admit_decision_at)) / 3600) FILTER (
                     WHERE disposition = ?
                       AND bed_assigned_at IS NULL
                       AND departed_at IS NULL
                       AND admit_decision_at IS NOT NULL
                 ) AS longest_admit_hrs
             FROM prod.ed_visits
             WHERE is_deleted = false
               AND arrived_at >= ?',
            ['admitted', $nowTs, 'admitted', $since]
        );

        $lastBed = DB::selectOne(
            'SELECT EXTRACT(EPOCH FROM (bed_assigned_at - arrived_at)) / 3600 AS last_bed_hrs
             FROM prod.ed_visits
             WHERE is_deleted = false
               AND bed_assigned_at IS NOT NULL
               AND bed_assigned_at >= arrived_at
             ORDER BY bed_assigned_at DESC
             LIMIT 1'
        );

        return [
            'total_patients' => (int) ($census->total_patients ?? 0),
            'ed_beds' => $edBeds,
            'admits' => (int) ($census->admits ?? 0),
            'hospital_beds' => $this->hospitalBedCapacity(),
            'ventilated' => (int) ($census->ventilated ?? 0),
            'longest_admit_hrs' => round((float) ($census->longest_admit_hrs ?? 0), 2),
            'last_bed_hrs' => round((float) ($lastBed->last_bed_hrs ?? 0), 2),
        ];
    }

    private function edBedCapacity(): ?int
    {
        $ed = $this->manifest->unit('ED');

        $capacity = $ed['nedocs_bed_capacity'] ?? $ed['staffed_bed_count'] ?? null;

        return $capacity !== null ? (int) $capacity : null;
    }

    private function hospitalBedCapacity(): int
    {
        // Weiss "number of hospital beds" is the facility's FIXED bed capacity,
        // not the live staffed census — the census fluctuates and can exceed
        // licensed beds when snapshot data double-counts, which silently
        // deflates the admits term. Use the manifest's licensed count; only
        // fall back to the census read if the manifest is somehow missing it.
        $licensed = (int) ($this->manifest->facility()['licensed_beds'] ?? 0);

        if ($licensed > 0) {
            return $licensed;
        }

        try {
            $staffed = (int) ($this->house->houseTotals()['staffedBeds'] ?? 0);
        } catch (\Throwable) {
            $staffed = 0;
        }

        return $staffed > 0 ? $staffed : 500;
    }
}
