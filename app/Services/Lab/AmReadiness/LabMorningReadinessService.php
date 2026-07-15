<?php

namespace App\Services\Lab\AmReadiness;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Owns Laboratory AM-readiness forecasting, server-side (P4-2).
 *
 * The service loads the committed synthetic model artifact and forecasts, for a
 * batch of open DECISION-CLASS Laboratory orders (discharge_gate /
 * ed_disposition, per LabDecisionPendingService), the calibrated probability that
 * the order will reach LAB_VERIFIED before the configured morning-rounds cutoff.
 * It returns an opt-in planning forecast per order plus the top contributing
 * operational factors and full model provenance (version, calibration date, the
 * rounds-cutoff policy, feature freshness, synthetic label). React never computes
 * the forecast — it renders what this returns.
 *
 * CRITICAL (§13 + P4-2 acceptance): this forecast is a sort/planning aid ONLY. It
 * is NEVER wired into AncillaryReadinessService; it can never convert an observed
 * blocked lab axis to ready. Observed readiness stays derived from observed state
 * elsewhere, and the forecast is surfaced as a visually/semantically distinct
 * "planning forecast", never an alarm, a breach status, or a clinical
 * recommendation. Missing/stale features yield unavailable/low-confidence, never
 * a fabricated number.
 */
final class LabMorningReadinessService
{
    /** Human-readable labels for the operational contributing factors. */
    private const FACTOR_LABELS = [
        'stage_resulted' => 'Result posted, awaiting verification',
        'stage_received' => 'Specimen received / in analytic processing',
        'stage_collected' => 'Specimen collected / in transit',
        'stage_ordered' => 'Ordered, not yet collected',
        'minutes_to_cutoff_norm' => 'Time remaining before rounds cutoff',
        'shift_am_draw' => 'AM draw wave',
        'family_chemistry' => 'Chemistry workload',
        'family_hematology' => 'Hematology workload',
        'family_coagulation' => 'Coagulation workload',
        'family_other' => 'Other-family workload',
        'patient_emergency' => 'Emergency patient class',
        'patient_inpatient' => 'Inpatient patient class',
    ];

    /** Signed factors that LOWER readiness — surfaced separately so a low forecast is explainable. */
    private const HEADWIND_LABELS = [
        'past_cutoff' => 'Rounds cutoff already elapsed',
        'queue_depth_norm' => 'Deep same-family verification queue',
        'analyzer_downtime' => 'Assigned analyzer in downtime / rerouted',
        'is_off_hours' => 'Off-hours / weekend processing',
        'processing_pressure' => 'Off-hours staffing pressure',
    ];

    public function __construct(private readonly AmReadinessFeatureExtractor $extractor) {}

    public function isAvailable(): bool
    {
        return AmReadinessModelArtifact::loadOrNull() !== null;
    }

    /** @return array<string, mixed>|null model provenance, or null when the artifact is absent */
    public function provenance(): ?array
    {
        return AmReadinessModelArtifact::loadOrNull()?->provenance();
    }

    /**
     * The configured morning-rounds cutoff as a local wall-clock time, resolved
     * to the next occurrence at or after `now`. This is a POLICY value read from
     * config/lab/am_readiness.php, deliberately separate from any SLA clock.
     *
     * @return array{at: CarbonImmutable, label: string, hour: int, minute: int}
     */
    public function roundsCutoff(?CarbonInterface $now = null): array
    {
        $at = $now !== null ? CarbonImmutable::instance($now) : CarbonImmutable::now();
        $hour = (int) config('lab.am_readiness.rounds_cutoff.hour', 8);
        $minute = (int) config('lab.am_readiness.rounds_cutoff.minute', 0);
        $label = (string) config('lab.am_readiness.rounds_cutoff.label', 'Morning rounds');

        $cutoff = $at->setTime($hour, $minute, 0);
        if ($cutoff->lessThanOrEqualTo($at)) {
            $cutoff = $cutoff->addDay();
        }

        return ['at' => $cutoff, 'label' => $label, 'hour' => $hour, 'minute' => $minute];
    }

    /**
     * Forecast a batch of open decision-class Laboratory orders by their order
     * UUIDs. Returns a map of orderUuid → forecast payload for the caller
     * (LabDecisionPendingService / the RTDC huddle) to attach.
     *
     * @param  list<string>  $orderUuids
     * @return array<string, array<string, mixed>>
     */
    public function forecastByOrderUuid(array $orderUuids, ?CarbonInterface $now = null): array
    {
        $artifact = AmReadinessModelArtifact::loadOrNull();
        $orderUuids = array_values(array_filter(array_unique($orderUuids), static fn (mixed $uuid): bool => is_string($uuid) && $uuid !== ''));
        if ($artifact === null || $orderUuids === []) {
            return [];
        }
        $model = $artifact->model();
        $at = $now !== null ? CarbonImmutable::instance($now) : CarbonImmutable::now();
        $cutoff = $this->roundsCutoff($at);
        $observations = $this->observationsFor($orderUuids, $cutoff['at'], $at);

        $forecasts = [];
        foreach ($observations as $orderUuid => $observation) {
            $forecasts[$orderUuid] = $this->forecast($model, $observation, $at, $cutoff);
        }

        return $forecasts;
    }

    /**
     * A house-wide AM-readiness planning SUMMARY for the RTDC morning huddle.
     * Forecasts every open decision-class Laboratory order and returns an
     * aggregate (band counts, mean probability, cutoff, provenance) — never a
     * per-patient roster mutation. This is a distinct planning aid the huddle
     * renders separately from the observed roster/readiness state.
     *
     * @return array<string, mixed>
     */
    public function huddleSummary(?CarbonInterface $now = null): array
    {
        $provenance = $this->provenance();
        $at = $now !== null ? CarbonImmutable::instance($now) : CarbonImmutable::now();
        $cutoff = $this->roundsCutoff($at);
        $cutoffBlock = [
            'roundsCutoffAt' => $cutoff['at']->toAtomString(),
            'roundsCutoffLabel' => $cutoff['label'],
        ];

        if ($provenance === null) {
            return [
                'available' => false,
                ...$cutoffBlock,
                'model' => null,
                'scored' => 0,
                'unavailable' => 0,
                'bands' => ['on_track' => 0, 'at_risk' => 0, 'unlikely' => 0],
                'meanProbability' => null,
                'explanation' => 'The Laboratory AM-readiness planning model artifact is not installed.',
            ];
        }

        $orderUuids = $this->openDecisionClassOrderUuids();
        $forecasts = $this->forecastByOrderUuid($orderUuids, $at);

        $bands = ['on_track' => 0, 'at_risk' => 0, 'unlikely' => 0];
        $probabilities = [];
        $unavailable = 0;
        foreach ($forecasts as $forecast) {
            if ($forecast['availability'] === 'unavailable' || $forecast['probability'] === null) {
                $unavailable++;

                continue;
            }
            $bands[$forecast['band']] = ($bands[$forecast['band']] ?? 0) + 1;
            $probabilities[] = (float) $forecast['probability'];
        }

        return [
            'available' => true,
            ...$cutoffBlock,
            'model' => $provenance,
            'scored' => count($probabilities),
            'unavailable' => $unavailable,
            'bands' => $bands,
            'meanProbability' => $probabilities === [] ? null : round(array_sum($probabilities) / count($probabilities), 4),
            'explanation' => 'Synthetic, calibrated planning forecast of decision-class Laboratory work reaching verification before rounds. A planning aid for the huddle — not the observed readiness state, an alarm, or a clinical recommendation.',
        ];
    }

    /**
     * Open decision-class Laboratory order UUIDs in the current operational
     * cohort — the same governed decision classes the Decision-Pending queue
     * uses, resolved without result/patient content for the huddle aggregate.
     *
     * @return list<string>
     */
    private function openDecisionClassOrderUuids(): array
    {
        return DB::table('prod.ancillary_orders as o')
            ->where('o.department', 'lab')
            ->whereNull('o.terminal_at')
            ->where('o.ordered_at', '>=', CarbonImmutable::now()->subDay())
            ->whereRaw("COALESCE(o.metadata->>'operational_window', 'current') <> 'historical_study_only'")
            ->whereIn(DB::raw("COALESCE(o.metadata->>'decision_class', 'none')"), ['discharge_gate', 'ed_disposition'])
            ->orderBy('o.ancillary_order_id')
            ->limit(500)
            ->pluck('o.order_uuid')
            ->map(fn (mixed $uuid): string => (string) $uuid)
            ->all();
    }

    /**
     * @param  array{at: CarbonImmutable, label: string, hour: int, minute: int}  $cutoff
     * @return array<string, mixed>
     */
    private function forecast(CalibratedLogisticModel $model, AmReadinessObservation $observation, CarbonImmutable $at, array $cutoff): array
    {
        $extracted = $this->extractor->extract($observation, $at);
        $availability = $extracted['availability'];
        $base = [
            'kind' => 'forecast',
            'roundsCutoffAt' => $cutoff['at']->toAtomString(),
            'roundsCutoffLabel' => $cutoff['label'],
        ];

        if ($availability === 'unavailable') {
            return [
                ...$base,
                'availability' => 'unavailable',
                'probability' => null,
                'band' => null,
                'factors' => [],
                'headwinds' => [],
                'missingSignals' => $extracted['missing'],
                'explanation' => 'Live operational signals (queue depth or analyzer state) are missing; no defensible AM-readiness forecast is produced.',
            ];
        }

        $probability = $model->calibratedProbability($extracted['features']);

        return [
            ...$base,
            'availability' => $availability,
            'probability' => round($probability, 4),
            'band' => $this->band($probability),
            'factors' => $this->topContributors($model, $extracted['features'], self::FACTOR_LABELS, positive: true),
            'headwinds' => $this->topContributors($model, $extracted['features'], self::HEADWIND_LABELS, positive: false),
            'missingSignals' => $extracted['missing'],
            'explanation' => $availability === 'low_confidence'
                ? 'Forecast with low confidence: an operational signal is stale. Treat as directional planning only.'
                : 'Calibrated planning forecast of on-time verification before rounds — a sort/planning aid, not an alarm, a breach status, or the observed readiness state.',
        ];
    }

    /**
     * Rank features by signed contribution to the logit for THIS order, keeping
     * either the tailwinds (positive) or headwinds (negative), each with a
     * plain-language label.
     *
     * @param  array<string, float>  $features
     * @param  array<string, string>  $labels
     * @return list<array{feature: string, label: string, contribution: float}>
     */
    private function topContributors(CalibratedLogisticModel $model, array $features, array $labels, bool $positive): array
    {
        $contributions = [];
        foreach ($model->weights as $feature => $weight) {
            if (! array_key_exists($feature, $labels)) {
                continue;
            }
            $value = (float) ($features[$feature] ?? 0.0);
            $contribution = $weight * $value;
            if ($positive ? $contribution <= 0.0 : $contribution >= 0.0) {
                continue;
            }
            $contributions[] = [
                'feature' => $feature,
                'label' => $labels[$feature],
                'contribution' => round($contribution, 4),
            ];
        }
        usort($contributions, static fn (array $a, array $b): int => $positive
            ? $b['contribution'] <=> $a['contribution']
            : $a['contribution'] <=> $b['contribution']);

        return array_slice($contributions, 0, 3);
    }

    private function band(float $probability): string
    {
        return match (true) {
            $probability >= 0.70 => 'on_track',
            $probability >= 0.40 => 'at_risk',
            default => 'unlikely',
        };
    }

    /**
     * Assemble available-at-prediction observations for a batch of open
     * decision-class Laboratory orders. Live same-family decision-class queue
     * depth and analyzer downtime are the operational context; their freshness is
     * derived from the order source cutoff.
     *
     * @param  list<string>  $orderUuids
     * @return array<string, AmReadinessObservation>
     */
    private function observationsFor(array $orderUuids, CarbonImmutable $roundsCutoffAt, CarbonImmutable $at): array
    {
        $rows = DB::table('prod.ancillary_orders as o')
            ->leftJoinSub($this->latestResult(), 'r', 'r.ancillary_order_id', '=', 'o.ancillary_order_id')
            ->whereIn('o.order_uuid', $orderUuids)
            ->where('o.department', 'lab')
            ->get([
                'o.ancillary_order_id', 'o.order_uuid', 'o.priority', 'o.patient_class', 'o.source_cutoff_at',
                'o.current_milestone_code', 'o.metadata as order_metadata',
                'r.analyzer_ref', 'r.result_metadata',
            ]);

        // Live same-family decision-class open-queue depth (available-at-prediction load).
        $queueByFamily = DB::table('prod.ancillary_orders as o')
            ->where('o.department', 'lab')
            ->whereNull('o.terminal_at')
            ->whereRaw("COALESCE(o.metadata->>'operational_window', 'current') <> 'historical_study_only'")
            ->whereRaw("COALESCE(o.metadata->>'decision_class', 'none') <> 'none'")
            ->groupByRaw("COALESCE(o.metadata->>'test_family', 'other')")
            ->selectRaw("COALESCE(o.metadata->>'test_family', 'other') AS test_family, count(*) AS depth")
            ->pluck('depth', 'test_family');

        $observations = [];
        foreach ($rows as $row) {
            $orderMetadata = $this->json($row->order_metadata);
            $resultMetadata = $this->json($row->result_metadata);
            $testFamily = (string) ($orderMetadata['test_family'] ?? 'other');
            $orderUuid = (string) $row->order_uuid;

            $observations[$orderUuid] = new AmReadinessObservation(
                orderId: (int) $row->ancillary_order_id,
                orderUuid: $orderUuid,
                stage: (string) ($row->current_milestone_code ?: 'LAB_ORDERED'),
                testFamily: $testFamily,
                priority: (string) $row->priority,
                patientClass: (string) $row->patient_class,
                isAmDraw: ($orderMetadata['shift'] ?? null) === 'am_draw',
                queueDepth: (int) ($queueByFamily[$testFamily] ?? 0),
                analyzerDowntime: $this->analyzerDowntime($resultMetadata),
                roundsCutoffAt: $roundsCutoffAt->toDateTimeImmutable(),
                signalCutoffAt: $row->source_cutoff_at !== null ? CarbonImmutable::parse($row->source_cutoff_at)->toDateTimeImmutable() : null,
            );
        }

        return $observations;
    }

    private function latestResult(): \Illuminate\Database\Query\Builder
    {
        return DB::table('prod.lab_results as lr')
            ->select('lr.ancillary_order_id', 'lr.analyzer_ref', DB::raw('lr.metadata as result_metadata'))
            ->whereRaw('lr.lab_result_id = (
                SELECT max(inner_lr.lab_result_id) FROM prod.lab_results as inner_lr
                WHERE inner_lr.ancillary_order_id = lr.ancillary_order_id
            )');
    }

    /**
     * Analyzer downtime is only a defensible signal when result metadata is
     * present: an order still awaiting a result has no analyzer state, so the
     * signal is null (missing) and the forecast degrades honestly.
     *
     * @param  array<string, mixed>  $resultMetadata
     */
    private function analyzerDowntime(array $resultMetadata): ?bool
    {
        if ($resultMetadata === []) {
            return null;
        }
        $state = $resultMetadata['analyzer_operational_state'] ?? 'operational';

        return in_array($state, ['downtime', 'rerouted_during_downtime', 'degraded'], true);
    }

    /** @return array<string, mixed> */
    private function json(mixed $value): array
    {
        return is_array($value) ? $value : (json_decode((string) ($value ?? '{}'), true) ?: []);
    }
}
