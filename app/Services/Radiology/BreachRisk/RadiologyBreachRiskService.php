<?php

namespace App\Services\Radiology\BreachRisk;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Owns Radiology breach-risk scoring, server-side (P4-1).
 *
 * The service loads the committed synthetic model artifact, scores a batch of
 * open imaging orders from their available-at-prediction features, and returns
 * an opt-in planning score per order plus the top contributing operational
 * factors and full model provenance (version, calibration date, feature
 * freshness, synthetic label). React never computes risk — it renders what this
 * returns. The output is a sort/planning aid only: no alarm severity, no colored
 * breach treatment, and no clinical recommendation. Missing/stale features yield
 * unavailable/low-confidence, never a fabricated score.
 */
final class RadiologyBreachRiskService
{
    /** Human-readable labels for the operational contributing factors. */
    private const FACTOR_LABELS = [
        'is_off_hours' => 'Off-hours / weekend window',
        'scanner_down' => 'Assigned-modality scanner downtime',
        'queue_depth_norm' => 'Deep same-modality queue',
        'staffing_pressure' => 'Off-hours staffing pressure',
        'priority_stat' => 'STAT ordering priority',
        'priority_urgent' => 'Urgent ordering priority',
        'patient_emergency' => 'Emergency patient class',
        'patient_inpatient' => 'Inpatient patient class',
        'stage_age_norm' => 'Elapsed current-stage age',
        'modality_ct' => 'CT modality load',
        'modality_mri' => 'MRI modality load',
        'modality_us' => 'US modality load',
        'modality_other' => 'Other-modality load',
    ];

    public function __construct(private readonly BreachRiskFeatureExtractor $extractor) {}

    public function isAvailable(): bool
    {
        return BreachRiskModelArtifact::loadOrNull() !== null;
    }

    /** @return array<string, mixed>|null model provenance, or null when the artifact is absent */
    public function provenance(): ?array
    {
        return BreachRiskModelArtifact::loadOrNull()?->provenance();
    }

    /**
     * Score a batch of open imaging orders by their order ids. Returns a map of
     * orderId → score payload for the caller (RadiologyWorklistService) to attach.
     *
     * @param  list<int>  $orderIds
     * @return array<int, array<string, mixed>>
     */
    public function scoreOrders(array $orderIds, ?CarbonInterface $now = null): array
    {
        $artifact = BreachRiskModelArtifact::loadOrNull();
        if ($artifact === null || $orderIds === []) {
            return [];
        }
        $model = $artifact->model();
        $at = $now !== null ? CarbonImmutable::instance($now) : CarbonImmutable::now();
        $observations = $this->observationsFor($orderIds, $at);

        $scores = [];
        foreach ($observations as $orderId => $observation) {
            $scores[$orderId] = $this->score($model, $observation, $at);
        }

        return $scores;
    }

    private function score(CalibratedLogisticModel $model, BreachRiskObservation $observation, CarbonImmutable $at): array
    {
        $extracted = $this->extractor->extract($observation, $at);
        $availability = $extracted['availability'];

        if ($availability === 'unavailable') {
            return [
                'availability' => 'unavailable',
                'probability' => null,
                'band' => null,
                'factors' => [],
                'missingSignals' => $extracted['missing'],
                'explanation' => 'Live operational signals (queue depth or scanner state) are missing; no defensible score is produced.',
            ];
        }

        $probability = $model->calibratedProbability($extracted['features']);
        $band = $this->band($probability);

        return [
            'availability' => $availability,
            'probability' => round($probability, 4),
            'band' => $band,
            'factors' => $this->topFactors($model, $extracted['features']),
            'missingSignals' => $extracted['missing'],
            'explanation' => $availability === 'low_confidence'
                ? 'Scored with low confidence: an operational signal is stale. Treat as directional only.'
                : 'Calibrated planning score from current operational load; a sort aid, not an alarm.',
        ];
    }

    /**
     * Rank the operational features by their signed contribution to the logit
     * for THIS order, returning the top drivers with a plain-language label.
     *
     * @param  array<string, float>  $features
     * @return list<array{feature: string, label: string, contribution: float}>
     */
    private function topFactors(CalibratedLogisticModel $model, array $features): array
    {
        $contributions = [];
        foreach ($model->weights as $feature => $weight) {
            $value = (float) ($features[$feature] ?? 0.0);
            $contribution = $weight * $value;
            if ($contribution <= 0.0 || ! array_key_exists($feature, self::FACTOR_LABELS)) {
                continue;
            }
            $contributions[] = [
                'feature' => $feature,
                'label' => self::FACTOR_LABELS[$feature],
                'contribution' => round($contribution, 4),
            ];
        }
        usort($contributions, static fn (array $a, array $b): int => $b['contribution'] <=> $a['contribution']);

        return array_slice($contributions, 0, 3);
    }

    private function band(float $probability): string
    {
        return match (true) {
            $probability >= 0.60 => 'high',
            $probability >= 0.30 => 'moderate',
            default => 'low',
        };
    }

    /**
     * Assemble available-at-prediction observations for a batch of open imaging
     * orders. Same-modality queue depth and scanner-down state are the live
     * operational context; their freshness is derived from the order source
     * cutoff and the scanner update time.
     *
     * @param  list<int>  $orderIds
     * @return array<int, BreachRiskObservation>
     */
    private function observationsFor(array $orderIds, CarbonImmutable $at): array
    {
        $rows = DB::table('prod.ancillary_orders as o')
            ->leftJoin('prod.rad_exams as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->whereIn('o.ancillary_order_id', $orderIds)
            ->where('o.department', 'rad')
            ->get([
                'o.ancillary_order_id', 'o.order_uuid', 'o.ordered_at', 'o.current_milestone_at',
                'o.priority', 'o.patient_class', 'o.source_cutoff_at', 'x.modality_code', 'x.rad_scanner_id',
            ]);

        // Live same-modality open-queue depth (available-at-prediction operational load).
        $queueByModality = DB::table('prod.ancillary_orders as o')
            ->join('prod.rad_exams as x', 'x.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->where('o.department', 'rad')
            ->whereNull('o.terminal_at')
            ->whereNotNull('x.modality_code')
            ->groupBy('x.modality_code')
            ->pluck(DB::raw('count(*) as depth'), 'x.modality_code');

        $scannerDown = $this->scannerDownState($at);

        $observations = [];
        foreach ($rows as $row) {
            $modality = $row->modality_code;
            $orderId = (int) $row->ancillary_order_id;
            $observations[$orderId] = new BreachRiskObservation(
                orderId: $orderId,
                orderUuid: (string) $row->order_uuid,
                orderedAt: CarbonImmutable::parse($row->ordered_at),
                stageStartedAt: $row->current_milestone_at !== null ? CarbonImmutable::parse($row->current_milestone_at) : null,
                modality: $modality,
                priority: (string) $row->priority,
                patientClass: (string) $row->patient_class,
                queueDepth: $modality !== null ? (int) ($queueByModality[$modality] ?? 0) : null,
                scannerDown: $modality !== null ? (bool) ($scannerDown[$modality] ?? false) : null,
                signalCutoffAt: $row->source_cutoff_at !== null ? CarbonImmutable::parse($row->source_cutoff_at) : null,
            );
        }

        return $observations;
    }

    /**
     * Modality → is any scanner of that modality in operational downtime right now.
     *
     * @return array<string, bool>
     */
    private function scannerDownState(CarbonImmutable $at): array
    {
        return DB::table('prod.rad_scanners as s')
            ->where('s.status', '!=', 'retired')
            ->selectRaw("s.modality_code, bool_or(
                s.status = 'downtime' OR EXISTS (
                    SELECT 1 FROM prod.rad_scanner_downtimes d
                    WHERE d.rad_scanner_id = s.rad_scanner_id
                      AND d.status IN ('scheduled', 'active')
                      AND d.starts_at <= ?
                      AND (d.ends_at IS NULL OR d.ends_at > ?)
                )) AS any_down", [$at, $at])
            ->groupBy('s.modality_code')
            ->pluck('any_down', 's.modality_code')
            ->map(fn (mixed $value): bool => (bool) $value)
            ->all();
    }
}
