<?php

// app/Services/CommandCenterDataService.php

namespace App\Services;

/**
 * Builds the Hospital Operations Command Center payload.
 *
 * This phase synthesizes a realistic, internally-consistent representative
 * dataset (no DB dependency) shaped exactly like the frontend Zod contract
 * (resources/js/types/commandCenter.ts), using camelCase keys.
 *
 * LIVE-DATA SEAM: a follow-up phase replaces the synthesis in build() with
 * real aggregate queries over prod.census_snapshots / encounters / beds /
 * operational_events / rtdc_predictions. The returned shape MUST NOT change.
 */
class CommandCenterDataService
{
    /** @return array<string,mixed> */
    public function build(): array
    {
        $units = $this->unitCensus();
        $occupancyPct = 88;
        $boarding = 4;
        $pendingAdmits = 11;
        $netBeds = -3;

        $strain = $this->strain($occupancyPct, $boarding, $pendingAdmits);

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'strain' => $strain,
            'heroMetrics' => $this->heroMetrics($occupancyPct, $netBeds, $boarding),
            'capacity' => $this->capacityBand($units),
            'flow' => $this->flowBand(),
            'outcomes' => $this->outcomesBand(),
            'forecast' => $this->forecastBand($netBeds),
            'forecastDetail' => $this->forecastDetail($netBeds, $units),
            'unitCensus' => $units,
            'objectives' => $this->objectives(),
        ];
    }

    /** @return array<string,mixed> */
    private function metric(
        string $key, string $label, float|int $value, string $unit, string $display,
        float|int|null $target, ?string $targetDisplay, string $status,
        ?array $points, string $direction, bool $goodWhenDown, ?string $drillHref, string $definition,
    ): array {
        return [
            'key' => $key, 'label' => $label, 'value' => $value, 'unit' => $unit, 'display' => $display,
            'target' => $target, 'targetDisplay' => $targetDisplay, 'status' => $status,
            'trajectory' => $points === null ? null
                : ['points' => $points, 'direction' => $direction, 'goodWhenDown' => $goodWhenDown],
            'drillHref' => $drillHref, 'definition' => $definition,
        ];
    }

    /** @return array<string,mixed> */
    private function strain(int $occupancyPct, int $boarding, int $pendingAdmits): array
    {
        // Composite strain score 0..4 from the three drivers.
        $score = 0;
        $score += $occupancyPct >= 92 ? 2 : ($occupancyPct >= 85 ? 1 : 0);
        $score += $boarding >= 6 ? 1 : 0;
        $score += $pendingAdmits >= 10 ? 1 : 0;
        $level = max(0, min(4, $score));
        $status = $level >= 3 ? 'critical' : ($level >= 2 ? 'warning' : 'success');

        return [
            'level' => $level,
            'label' => "Surge Level {$level}",
            'status' => $status,
            'previousLevel' => max(0, $level - 1),
            'drivers' => [
                ['label' => 'Occupancy', 'value' => "{$occupancyPct}%", 'status' => $occupancyPct >= 85 ? 'warning' : 'success'],
                ['label' => 'ED boarding', 'value' => (string) $boarding, 'status' => $boarding >= 6 ? 'critical' : 'warning'],
                ['label' => 'Pending admits', 'value' => (string) $pendingAdmits, 'status' => $pendingAdmits >= 10 ? 'warning' : 'success'],
            ],
            'updatedAtIso' => now()->toIso8601String(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function heroMetrics(int $occupancyPct, int $netBeds, int $boarding): array
    {
        return [
            $this->metric('occupancy', 'Occupancy', $occupancyPct, '%', "{$occupancyPct}%",
                85, '≤85%', 'warning', [82, 84, 85, 87, 88], 'up', true, '/rtdc/bed-tracking',
                'Staffed beds occupied as a percent of staffed capacity. Safe zone ≤85%.'),
            $this->metric('net_beds', 'Net Bed Position', $netBeds, 'beds', (string) $netBeds,
                0, '≥0', 'critical', [2, 1, 0, -2, -3], 'down', false, '/rtdc/predictions/demand',
                'Projected available minus projected demand over the next 4–8h.'),
            $this->metric('ed_boarding', 'ED Boarding', $boarding, 'pts', (string) $boarding,
                4, '<4', 'warning', [6, 5, 5, 4, 4], 'down', true, '/dashboard/emergency',
                'Admitted ED patients awaiting an inpatient bed. Target boarding time <4h.'),
            $this->metric('dc_ready', 'Discharges Ready', 9, 'pts', '9',
                null, 'DBN 25%', 'success', [5, 6, 7, 8, 9], 'up', false, '/rtdc/bed-placement',
                'Patients with completed discharge orders awaiting departure.'),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function unitCensus(): array
    {
        $rows = [
            ['unitId' => 1, 'name' => '5 East', 'type' => 'Med-Surg', 'staffed' => 30, 'occupied' => 27, 'blocked' => 1],
            ['unitId' => 2, 'name' => '6 West', 'type' => 'Med-Surg', 'staffed' => 28, 'occupied' => 26, 'blocked' => 0],
            ['unitId' => 3, 'name' => 'MICU', 'type' => 'ICU', 'staffed' => 16, 'occupied' => 15, 'blocked' => 1],
            ['unitId' => 4, 'name' => 'SICU', 'type' => 'ICU', 'staffed' => 14, 'occupied' => 11, 'blocked' => 0],
            ['unitId' => 5, 'name' => 'Telemetry', 'type' => 'Step-down', 'staffed' => 24, 'occupied' => 22, 'blocked' => 2],
            ['unitId' => 6, 'name' => 'PCU', 'type' => 'Step-down', 'staffed' => 20, 'occupied' => 16, 'blocked' => 0],
        ];

        return array_map(function (array $r): array {
            $available = max(0, $r['staffed'] - $r['occupied'] - $r['blocked']);
            $occPct = (int) round(($r['occupied'] / max(1, $r['staffed'])) * 100);
            $status = $occPct >= 92 ? 'critical' : ($occPct >= 85 ? 'warning' : 'success');

            return [
                'unitId' => $r['unitId'], 'name' => $r['name'], 'type' => $r['type'],
                'staffed' => $r['staffed'], 'occupied' => $r['occupied'], 'blocked' => $r['blocked'],
                'available' => $available, 'occupancyPct' => $occPct,
                'acuityAdjustedPct' => min(100, $occPct + 3), 'status' => $status,
            ];
        }, $rows);
    }

    /** @return array<string,mixed> */
    private function capacityBand(array $units): array
    {
        $available = array_sum(array_column($units, 'available'));
        $blocked = array_sum(array_column($units, 'blocked'));

        return [
            'key' => 'capacity', 'title' => 'Capacity', 'summary' => '88% occupied house-wide',
            'drillHref' => '/rtdc/bed-tracking', 'drillLabel' => 'open RTDC',
            'metrics' => [
                $this->metric('available_beds', 'Available', $available, 'beds', (string) $available,
                    null, null, 'success', [10, 12, 13, 14, (int) $available], 'up', false, '/rtdc/bed-tracking',
                    'Staffed, unoccupied, unblocked beds available now.'),
                $this->metric('blocked_beds', 'Blocked', $blocked, 'beds', (string) $blocked,
                    0, '0', $blocked > 4 ? 'warning' : 'success', [3, 4, 4, 5, (int) $blocked], 'flat', true,
                    '/rtdc/bed-tracking', 'Beds offline due to staffing, environmental, or isolation barriers.'),
                $this->metric('acuity_adjusted', 'Acuity-Adjusted', 92, '%', '92%',
                    null, null, 'warning', [88, 90, 91, 92, 92], 'up', true, '/rtdc/bed-tracking',
                    'Capacity adjusted for current patient acuity mix.'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function flowBand(): array
    {
        return [
            'key' => 'flow', 'title' => 'Flow', 'summary' => 'ED · Inpatient · OR throughput',
            'drillHref' => '/dashboard/emergency', 'drillLabel' => 'open ED',
            'metrics' => [],
            'subgroups' => [
                ['key' => 'ed', 'label' => 'Emergency', 'metrics' => [
                    $this->metric('ed_d2p', 'Door-to-Provider', 17, 'min', '17m', 20, '<20m', 'success',
                        [22, 20, 19, 18, 17], 'down', true, '/dashboard/emergency', 'Median arrival to provider evaluation.'),
                    $this->metric('ed_lwbs', 'LWBS', 0.9, '%', '0.9%', 2, '<2%', 'success',
                        [1.4, 1.2, 1.1, 1.0, 0.9], 'down', true, '/dashboard/emergency', 'Left without being seen.'),
                    $this->metric('ed_los', 'ED LOS (disch)', 138, 'min', '138m', 150, '<150m', 'success',
                        [150, 145, 142, 140, 138], 'down', true, '/dashboard/emergency', 'Median ED length of stay, discharged patients.'),
                ]],
                ['key' => 'ip', 'label' => 'Inpatient', 'metrics' => [
                    $this->metric('adm_to_bed', 'Admit→Bed', 47, 'min', '47m', 60, '<60m', 'success',
                        [62, 58, 52, 49, 47], 'down', true, '/rtdc/bed-placement', 'Admit decision to bed assigned.'),
                    $this->metric('dbn', 'Discharge by Noon', 18, '%', '18%', 25, '25%', 'warning',
                        [10, 13, 15, 17, 18], 'up', false, '/rtdc/bed-placement', 'Percent of discharges completed before noon.'),
                ]],
                ['key' => 'or', 'label' => 'Operating Room', 'metrics' => [
                    $this->metric('fcots', 'First-Case On-Time', 82, '%', '82%', 85, '≥85%', 'warning',
                        [76, 78, 80, 81, 82], 'up', false, '/dashboard/perioperative', 'First cases starting on time (15-min grace).'),
                    $this->metric('block_util', 'Block Utilization', 76, '%', '76%', 80, '80%', 'warning',
                        [71, 73, 74, 75, 76], 'up', false, '/dashboard/perioperative', 'Used block minutes / allocated block minutes.'),
                    $this->metric('turnover', 'Turnover', 31, 'min', '31m', 25, '<25m', 'warning',
                        [35, 34, 33, 32, 31], 'down', true, '/dashboard/perioperative', 'Median room turnover time.'),
                    $this->metric('cancellations', 'Same-Day Cxl', 2, 'cases', '2', null, null, 'success',
                        [4, 3, 3, 2, 2], 'down', true, '/dashboard/perioperative', 'Day-of-surgery cancellations.'),
                ]],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function outcomesBand(): array
    {
        return [
            'key' => 'outcomes', 'title' => 'Outcomes', 'summary' => 'Safety & efficiency results',
            'drillHref' => '/dashboard/improvement', 'drillLabel' => 'open Improvement',
            'metrics' => [
                $this->metric('readmission', '30-Day Readmission', 12.1, '%', '12.1%', 11, '<11%', 'warning',
                    [13.0, 12.7, 12.4, 12.2, 12.1], 'down', true, '/dashboard/improvement', '30-day all-cause readmission rate.'),
                $this->metric('los_gmlos', 'LOS / GMLOS', 1.10, 'x', '1.10', 1.0, '1.00', 'warning',
                    [1.18, 1.15, 1.13, 1.11, 1.10], 'down', true, '/dashboard/improvement', 'Observed LOS vs geometric-mean LOS.'),
                $this->metric('excess_days', 'Excess Bed-Days', 142, 'days', '142', null, null, 'warning',
                    [190, 175, 160, 150, 142], 'down', true, '/dashboard/improvement', 'Avoidable bed-days vs GMLOS this period.'),
                $this->metric('diversion', 'Diversion Hours', 0, 'h', '0h', 0, '0h', 'success',
                    [2, 1, 1, 0, 0], 'down', true, '/dashboard/improvement', 'Capacity-related ED diversion hours.'),
                $this->metric('pdsa_active', 'Active PDSA', 5, 'cycles', '5', null, null, 'info',
                    [3, 4, 4, 5, 5], 'up', false, '/dashboard/improvement', 'Improvement cycles in progress.'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function forecastBand(int $netBeds): array
    {
        return [
            'key' => 'forecast', 'title' => 'Forecast', 'summary' => 'Next 24–48h projection',
            'drillHref' => '/rtdc/predictions/demand', 'drillLabel' => 'open Predictions',
            'metrics' => [
                $this->metric('pred_discharges', 'Discharges 24h', 22, 'pts', '22', null, null, 'info',
                    [18, 19, 20, 21, 22], 'up', false, '/rtdc/predictions/discharge', 'Predicted discharges in the next 24h.'),
                $this->metric('pred_arrivals', 'ED Arrivals 24h', 60, 'pts', '60', null, null, 'info',
                    [54, 56, 58, 59, 60], 'up', false, '/rtdc/predictions/demand', 'Predicted ED arrivals in the next 24h.'),
                $this->metric('net_beds_fc', 'Net Beds (proj)', $netBeds, 'beds', (string) $netBeds,
                    0, '≥0', 'critical', [1, 0, -1, -2, $netBeds], 'down', false, '/rtdc/predictions/demand',
                    'Projected supply minus demand at the next demand peak.'),
                $this->metric('surge_prob', 'Surge Probability', 38, '%', '38%', null, null, 'warning',
                    [25, 30, 33, 36, 38], 'up', true, '/rtdc/predictions/demand', 'Modeled probability of a surge event in 24h.'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function forecastDetail(int $netBeds, array $units): array
    {
        $curve = [];
        $base = 88;
        for ($h = 0; $h <= 24; $h += 2) {
            $occ = $base + (int) round(4 * sin($h / 4));
            $curve[] = ['hourOffset' => $h, 'occupancyPct' => $occ, 'lowerPct' => $occ - 3, 'upperPct' => $occ + 3];
        }

        return [
            'predictedDischarges24h' => 22, 'predictedDischarges48h' => 41,
            'predictedEdArrivals' => 60, 'predictedAdmissions' => 18,
            'netBedPosition' => $netBeds, 'surgeProbabilityPct' => 38,
            'occupancyCurve' => $curve,
            'netBedByUnit' => array_map(
                fn (array $u): array => ['unitId' => $u['unitId'], 'name' => $u['name'],
                    'net' => $u['available'] - ($u['blocked'] + 1)],
                $units,
            ),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function objectives(): array
    {
        return [
            ['key' => 'flow', 'title' => 'Improve access & flow', 'keyResults' => [
                ['label' => 'ED boarding', 'current' => 168, 'target' => 120, 'baseline' => 192,
                    'progressPct' => 33, 'status' => 'warning', 'display' => '168→<120 min'],
                ['label' => 'Discharge by noon', 'current' => 18, 'target' => 25, 'baseline' => 10,
                    'progressPct' => 53, 'status' => 'warning', 'display' => '18%→25%'],
            ]],
            ['key' => 'or', 'title' => 'Maximize surgical throughput', 'keyResults' => [
                ['label' => 'First-case on-time', 'current' => 82, 'target' => 85, 'baseline' => 76,
                    'progressPct' => 67, 'status' => 'warning', 'display' => '82%→85%'],
                ['label' => 'Block utilization', 'current' => 76, 'target' => 80, 'baseline' => 71,
                    'progressPct' => 56, 'status' => 'warning', 'display' => '76%→80%'],
            ]],
            ['key' => 'beddays', 'title' => 'Eliminate avoidable bed-days', 'keyResults' => [
                ['label' => 'LOS / GMLOS', 'current' => 110, 'target' => 100, 'baseline' => 118,
                    'progressPct' => 44, 'status' => 'warning', 'display' => '1.10→1.00'],
            ]],
        ];
    }
}
