<?php

namespace App\Services\Rtdc;

use Illuminate\Support\Facades\DB;

/**
 * Builds the RTDC › Predictions › Risk Assessment payload from the live `prod`
 * schema (DB_SCHEMA=prod).
 *
 * The page (resources/js/Pages/RTDC/Predictions/RiskAssessment.tsx) stratifies
 * the active inpatient census by a transparent, deterministic 30-day
 * discharge / readmission risk proxy. This is decision support only — never an
 * automated discharge action.
 *
 * Risk is a clinically explainable composite (each term is bounded and signed
 * so the drivers panel can be derived from the same arithmetic):
 *
 *   risk = clamp01(
 *       0.18 * clamp(0..2, los_overage_ratio)     // LOS beyond GMLOS for the unit type
 *     + 0.12 * (acuity_tier - 1)                   // acuity_tier 1..4 → 0.00..0.36
 *     + edd_term                                    // 0.22 if EDD passed, 0.10 if EDD today
 *   )
 *
 * where los_overage_ratio = max(0, los_days - gmlos_days) / gmlos_days.
 *
 * Tiers follow the page legend: high ≥ 0.40, moderate 0.20–0.39, low < 0.20.
 *
 * Computation is deterministic and safe on empty tables: every accessor returns
 * zeros / empty collections rather than throwing. ED is included because
 * long-boarding ED admits are a genuine discharge-planning / readmission risk.
 *
 * Returned shape (consumed by RiskAssessment.tsx, all keys optional there):
 *   tiers: array{high:int, moderate:int, low:int}
 *   total: int                       // scored active encounters
 *   averageRisk: float               // 0..1, house-wide mean
 *   watchlist: list<array{...}>      // top-N highest-risk patients
 *   drivers: list<array{...}>        // contributing-factor prevalence
 *   generatedAt: string              // ISO-8601 timestamp
 */
class RiskAssessmentService
{
    /** Tier thresholds (mirror the page legend). */
    private const HIGH_THRESHOLD = 0.40;

    private const MODERATE_THRESHOLD = 0.20;

    /** How many patients to surface on the high-risk watchlist. */
    private const WATCHLIST_LIMIT = 12;

    /** Composite weights (see class docblock). */
    private const W_LOS = 0.18;

    private const W_ACUITY = 0.12;

    private const EDD_PASSED_TERM = 0.22;

    private const EDD_TODAY_TERM = 0.10;

    /** Human-readable label per unit `type`. */
    private const TYPE_LABELS = [
        'ed' => 'Emergency',
        'icu' => 'Critical Care',
        'step_down' => 'Step-down',
        'med_surg' => 'Med/Surg',
        'or' => 'Surgical',
    ];

    /**
     * Full Risk Assessment payload.
     *
     * @return array{
     *     tiers: array{high:int, moderate:int, low:int},
     *     total: int,
     *     averageRisk: float,
     *     watchlist: list<array<string,mixed>>,
     *     drivers: list<array<string,mixed>>,
     *     generatedAt: string
     * }
     */
    public function build(): array
    {
        $scored = $this->scoredEncounters();

        return [
            'tiers' => $this->tiers($scored),
            'total' => count($scored),
            'averageRisk' => $this->averageRisk($scored),
            'watchlist' => $this->watchlist($scored),
            'drivers' => $this->drivers($scored),
            'generatedAt' => now()->toIso8601String(),
        ];
    }

    // -----------------------------------------------------------------------
    // Source query + scoring
    // -----------------------------------------------------------------------

    /**
     * Active inpatient encounters with their risk score and contributing terms.
     * Scoring is done in PHP (not SQL) so the exact weights stay readable and
     * the drivers panel can reuse the same arithmetic.
     *
     * @return list<object{
     *     encounter_id:int, patient_ref:string, abbreviation:string,
     *     unit_name:string, unit_type:string, acuity_tier:int,
     *     los_days:float, gmlos_days:float, days_to_edd:?int,
     *     los_overage:float, los_term:float, acuity_term:float,
     *     edd_term:float, risk:float
     * }>
     */
    private function scoredEncounters(): array
    {
        $rows = DB::select(
            "SELECT
                e.encounter_id,
                e.patient_ref,
                COALESCE(u.abbreviation, u.name) AS abbreviation,
                u.name AS unit_name,
                u.type AS unit_type,
                e.acuity_tier,
                GREATEST(0, EXTRACT(EPOCH FROM (NOW() - e.admitted_at)) / 86400.0) AS los_days,
                g.gmlos_days,
                (e.expected_discharge_date - CURRENT_DATE) AS days_to_edd
             FROM prod.encounters e
             JOIN prod.units u ON u.unit_id = e.unit_id AND u.is_deleted = false
             LEFT JOIN prod.gmlos_references g ON g.unit_type = u.type
             WHERE e.status = 'active'
               AND e.is_deleted = false
               AND e.unit_id IS NOT NULL
               AND e.admitted_at IS NOT NULL"
        );

        $scored = [];
        foreach ($rows as $row) {
            $losDays = (float) $row->los_days;
            $gmlos = $row->gmlos_days !== null ? (float) $row->gmlos_days : null;
            $acuity = (int) $row->acuity_tier;
            $daysToEdd = $row->days_to_edd !== null ? (int) $row->days_to_edd : null;

            // LOS overage relative to the unit-type GMLOS. With no GMLOS
            // reference we cannot judge overage, so the LOS term is zero.
            $losOverage = ($gmlos !== null && $gmlos > 0)
                ? max(0.0, $losDays - $gmlos)
                : 0.0;
            $losRatio = ($gmlos !== null && $gmlos > 0)
                ? min(2.0, $losOverage / $gmlos)
                : 0.0;

            $losTerm = self::W_LOS * $losRatio;
            $acuityTerm = self::W_ACUITY * max(0, $acuity - 1);

            $eddTerm = 0.0;
            if ($daysToEdd !== null) {
                if ($daysToEdd < 0) {
                    $eddTerm = self::EDD_PASSED_TERM;
                } elseif ($daysToEdd === 0) {
                    $eddTerm = self::EDD_TODAY_TERM;
                }
            }

            $risk = min(1.0, $losTerm + $acuityTerm + $eddTerm);

            $scored[] = (object) [
                'encounter_id' => (int) $row->encounter_id,
                'patient_ref' => (string) $row->patient_ref,
                'abbreviation' => (string) $row->abbreviation,
                'unit_name' => (string) $row->unit_name,
                'unit_type' => (string) $row->unit_type,
                'acuity_tier' => $acuity,
                'los_days' => $losDays,
                'gmlos_days' => $gmlos ?? 0.0,
                'days_to_edd' => $daysToEdd,
                'los_overage' => $losOverage,
                'los_term' => $losTerm,
                'acuity_term' => $acuityTerm,
                'edd_term' => $eddTerm,
                'risk' => $risk,
            ];
        }

        // Highest-risk first; stable tiebreak on LOS overage then encounter id.
        usort($scored, static function (object $a, object $b): int {
            return [$b->risk, $b->los_overage, $a->encounter_id]
                <=> [$a->risk, $a->los_overage, $b->encounter_id];
        });

        return $scored;
    }

    // -----------------------------------------------------------------------
    // Aggregates
    // -----------------------------------------------------------------------

    /**
     * @param  list<object>  $scored
     * @return array{high:int, moderate:int, low:int}
     */
    private function tiers(array $scored): array
    {
        $tiers = ['high' => 0, 'moderate' => 0, 'low' => 0];
        foreach ($scored as $row) {
            $tiers[$this->tierFor($row->risk)]++;
        }

        return $tiers;
    }

    /** @param  list<object>  $scored */
    private function averageRisk(array $scored): float
    {
        if ($scored === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($scored as $row) {
            $sum += $row->risk;
        }

        return round($sum / count($scored), 3);
    }

    /**
     * Top-N highest-risk patients with the human-readable signals that earned
     * the score (so the table never relies on color alone).
     *
     * @param  list<object>  $scored
     * @return list<array<string,mixed>>
     */
    private function watchlist(array $scored): array
    {
        $out = [];
        foreach (array_slice($scored, 0, self::WATCHLIST_LIMIT) as $row) {
            $out[] = [
                'id' => 'enc-'.$row->encounter_id,
                'patient' => $row->patient_ref,
                'unit' => $row->abbreviation,
                'unitType' => self::TYPE_LABELS[$row->unit_type] ?? ucfirst(str_replace('_', '-', $row->unit_type)),
                'acuityTier' => $row->acuity_tier,
                'losDays' => round($row->los_days, 1),
                'gmlosDays' => round($row->gmlos_days, 1),
                'overageDays' => round($row->los_overage, 1),
                'daysToEdd' => $row->days_to_edd,
                'risk' => round($row->risk, 2),
                'tier' => $this->tierFor($row->risk),
                'signals' => $this->signalsFor($row),
            ];
        }

        return $out;
    }

    /**
     * Contributing-factor prevalence across the scored census — how many
     * patients each driver flags and its mean contribution to the score.
     * Ordered by descending mean contribution.
     *
     * @param  list<object>  $scored
     * @return list<array<string,mixed>>
     */
    private function drivers(array $scored): array
    {
        $total = count($scored);
        if ($total === 0) {
            return [];
        }

        $losCount = 0;
        $losSum = 0.0;
        $acuityCount = 0;
        $acuitySum = 0.0;
        $eddCount = 0;
        $eddSum = 0.0;

        foreach ($scored as $row) {
            if ($row->los_overage > 0) {
                $losCount++;
                $losSum += $row->los_term;
            }
            if ($row->acuity_tier >= 3) {
                $acuityCount++;
                $acuitySum += $row->acuity_term;
            }
            if ($row->edd_term > 0) {
                $eddCount++;
                $eddSum += $row->edd_term;
            }
        }

        $drivers = [
            [
                'key' => 'los-overage',
                'label' => 'Length of stay beyond GMLOS',
                'detail' => 'Patients exceeding the geometric-mean LOS for their unit type',
                'count' => $losCount,
                'prevalence' => $this->pct($losCount, $total),
                'avgContribution' => $losCount > 0 ? round($losSum / $losCount, 3) : 0.0,
            ],
            [
                'key' => 'high-acuity',
                'label' => 'High acuity (tier 3–4)',
                'detail' => 'Higher-acuity patients carry greater post-discharge risk',
                'count' => $acuityCount,
                'prevalence' => $this->pct($acuityCount, $total),
                'avgContribution' => $acuityCount > 0 ? round($acuitySum / $acuityCount, 3) : 0.0,
            ],
            [
                'key' => 'edd-pressure',
                'label' => 'Expected discharge today or overdue',
                'detail' => 'Discharge target reached or passed without a completed discharge',
                'count' => $eddCount,
                'prevalence' => $this->pct($eddCount, $total),
                'avgContribution' => $eddCount > 0 ? round($eddSum / $eddCount, 3) : 0.0,
            ],
        ];

        usort($drivers, static fn (array $a, array $b): int => $b['avgContribution'] <=> $a['avgContribution']);

        return $drivers;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return 'high'|'moderate'|'low' */
    private function tierFor(float $risk): string
    {
        if ($risk >= self::HIGH_THRESHOLD) {
            return 'high';
        }
        if ($risk >= self::MODERATE_THRESHOLD) {
            return 'moderate';
        }

        return 'low';
    }

    /**
     * Short human-readable signals behind a patient's score.
     *
     * @return list<string>
     */
    private function signalsFor(object $row): array
    {
        $signals = [];
        if ($row->los_overage >= 1.0) {
            $signals[] = '+'.round($row->los_overage, 1).'d over GMLOS';
        }
        if ($row->acuity_tier >= 3) {
            $signals[] = 'Acuity '.$row->acuity_tier;
        }
        if ($row->days_to_edd !== null && $row->days_to_edd < 0) {
            $signals[] = 'EDD overdue';
        } elseif ($row->days_to_edd === 0) {
            $signals[] = 'EDD today';
        }

        return $signals;
    }

    private function pct(int $count, int $total): int
    {
        return $total > 0 ? (int) round($count / $total * 100) : 0;
    }
}
