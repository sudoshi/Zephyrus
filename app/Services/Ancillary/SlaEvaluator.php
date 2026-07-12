<?php

namespace App\Services\Ancillary;

use App\Data\Ancillary\AncillarySlaEvaluation;
use App\Data\Ancillary\FreshnessEnvelope;
use App\Models\Ancillary\AncillaryBreach;
use App\Models\Ancillary\AncillaryMilestone;
use App\Models\Ancillary\AncillaryOrder;
use App\Models\Ancillary\AncillarySlaDefinition;
use App\Services\Mobile\OperationalActivityLedger;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SlaEvaluator
{
    public function __construct(private readonly OperationalActivityLedger $activity) {}

    /** @return list<AncillarySlaEvaluation> */
    public function evaluateOrder(int|AncillaryOrder $order, ?CarbonInterface $evaluationAt = null): array
    {
        $orderId = $order instanceof AncillaryOrder ? (int) $order->getKey() : $order;
        $at = $this->evaluationTime($evaluationAt);

        return DB::transaction(function () use ($orderId, $at): array {
            $order = AncillaryOrder::query()
                ->with('source')
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();
            $openBreaches = AncillaryBreach::query()
                ->with('definition')
                ->where('ancillary_order_id', $orderId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->get();

            $selected = $this->selectedDefinitions($order, $at);
            $openMetricKeys = $openBreaches
                ->map(fn (AncillaryBreach $breach): ?string => $breach->definition?->metric_key)
                ->filter()
                ->all();
            $definitions = $openBreaches
                ->map(fn (AncillaryBreach $breach): ?AncillarySlaDefinition => $breach->definition)
                ->filter()
                ->merge($selected->reject(fn (AncillarySlaDefinition $definition): bool => in_array($definition->metric_key, $openMetricKeys, true)))
                ->unique('ancillary_sla_definition_id')
                ->values();

            return $definitions
                ->map(fn (AncillarySlaDefinition $definition): AncillarySlaEvaluation => $this->evaluateDefinition(
                    $order,
                    $definition,
                    $at,
                    $openBreaches->firstWhere('ancillary_sla_definition_id', $definition->ancillary_sla_definition_id),
                ))
                ->all();
        }, 3);
    }

    /** @return array<string, mixed> */
    public function evaluateOrderSafely(int|AncillaryOrder $order, ?CarbonInterface $evaluationAt = null): array
    {
        $orderId = $order instanceof AncillaryOrder ? (int) $order->getKey() : $order;
        $orderUuid = $order instanceof AncillaryOrder ? $order->order_uuid : null;

        try {
            $evaluations = $this->evaluateOrder($orderId, $evaluationAt);

            return [
                'ok' => true,
                'orderId' => $orderId,
                'orderUuid' => $orderUuid ?? AncillaryOrder::query()->whereKey($orderId)->value('order_uuid'),
                'evaluations' => array_map(fn (AncillarySlaEvaluation $result): array => $result->toArray(), $evaluations),
            ];
        } catch (Throwable $exception) {
            Log::warning('Ancillary SLA order evaluation failed.', [
                'ancillary_order_id' => $orderId,
                'order_uuid' => $orderUuid,
                'exception_class' => $exception::class,
            ]);

            return [
                'ok' => false,
                'orderId' => $orderId,
                'orderUuid' => $orderUuid,
                'errorCode' => 'ancillary_sla_evaluation_failed',
                'exceptionClass' => $exception::class,
                'evaluations' => [],
            ];
        }
    }

    private function evaluateDefinition(
        AncillaryOrder $order,
        AncillarySlaDefinition $definition,
        CarbonImmutable $at,
        ?AncillaryBreach $openBreach,
    ): AncillarySlaEvaluation {
        $freshness = $this->freshness($order, $at);
        $start = $openBreach !== null
            ? AncillaryMilestone::query()->find($openBreach->start_assertion_id)
            : $this->selectedAssertion($order, $definition->start_milestone_code);
        $stop = $this->selectedAssertion($order, $definition->stop_milestone_code);
        $latestBreach = $openBreach ?? AncillaryBreach::query()
            ->where('ancillary_order_id', $order->ancillary_order_id)
            ->where('ancillary_sla_definition_id', $definition->ancillary_sla_definition_id)
            ->orderByDesc('ancillary_breach_id')
            ->first();

        if ($start === null) {
            return $this->result($definition, 'unknown', null, null, $stop, $latestBreach, false, false, $freshness, 'missing_start_assertion');
        }

        $startAt = CarbonImmutable::instance($start->occurred_at)->setTimezone($this->clockTimezone());
        if ($at->lessThan($startAt)) {
            return $this->result($definition, 'not_started', 0.0, $start, $stop, $latestBreach, false, false, $freshness, 'clock_starts_in_future');
        }

        $stopAt = $stop !== null
            ? CarbonImmutable::instance($stop->occurred_at)->setTimezone($this->clockTimezone())
            : null;
        if ($stopAt !== null && $stopAt->lessThan($startAt)) {
            return $this->result($definition, 'unknown', null, $start, $stop, $latestBreach, false, false, $freshness, 'negative_interval');
        }

        $endAt = $stopAt
            ?? ($freshness->status === 'batch' && $freshness->sourceCutoffAt !== null
                ? CarbonImmutable::instance($freshness->sourceCutoffAt)->setTimezone($this->clockTimezone())
                : $at);
        $elapsed = round(($endAt->getTimestamp() - $startAt->getTimestamp()) / 60, 3);
        $breachOpened = false;
        $breachCleared = false;
        $breachThreshold = $definition->breach_minutes;

        if ($latestBreach?->status === 'cleared') {
            return $this->result($definition, 'complete', $elapsed, $start, $stop, $latestBreach, false, false, $freshness, 'completed_after_breach');
        }

        if ($latestBreach?->status === 'open') {
            $latestBreach->update(['last_evaluated_at' => $at]);
            if ($stop !== null) {
                $latestBreach = $this->clearBreach($order, $definition, $latestBreach, $stop, $elapsed, $at);
                $breachCleared = true;
            }
        } elseif ($freshness->status !== 'stale' && $breachThreshold !== null && $elapsed >= $breachThreshold) {
            $latestBreach = $this->openBreach($order, $definition, $start, $elapsed, $at);
            $breachOpened = true;
            if ($stop !== null) {
                $latestBreach = $this->clearBreach($order, $definition, $latestBreach, $stop, $elapsed, $at);
                $breachCleared = true;
            }
        }

        if ($stop !== null) {
            $state = 'complete';
            $reason = $latestBreach !== null ? 'completed_after_breach' : null;
        } elseif ($freshness->status === 'stale') {
            $state = 'unknown';
            $reason = 'stale_source';
        } elseif ($latestBreach?->status === 'open') {
            $state = 'breached';
            $reason = null;
        } elseif ($definition->warning_minutes !== null && $elapsed >= $definition->warning_minutes) {
            $state = 'warning';
            $reason = null;
        } else {
            $state = 'running';
            $reason = null;
        }

        return $this->result(
            $definition,
            $state,
            $elapsed,
            $start,
            $stop,
            $latestBreach,
            $breachOpened,
            $breachCleared,
            $freshness,
            $reason,
        );
    }

    private function openBreach(
        AncillaryOrder $order,
        AncillarySlaDefinition $definition,
        AncillaryMilestone $start,
        float $elapsed,
        CarbonImmutable $at,
    ): AncillaryBreach {
        $startAt = CarbonImmutable::instance($start->occurred_at)->setTimezone($this->clockTimezone());
        $breach = AncillaryBreach::query()->create([
            'breach_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => $order->ancillary_order_id,
            'ancillary_sla_definition_id' => $definition->ancillary_sla_definition_id,
            'status' => 'open',
            'warning_at' => $definition->warning_minutes !== null ? $startAt->addMinutes($definition->warning_minutes) : null,
            'breached_at' => $startAt->addMinutes((int) $definition->breach_minutes),
            'start_assertion_id' => $start->ancillary_milestone_id,
            'elapsed_minutes_at_open' => $elapsed,
            'last_evaluated_at' => $at,
            'metadata' => [
                'clockTimezone' => $this->clockTimezone(),
                'definitionVersion' => $definition->version,
            ],
        ]);
        $activity = $this->recordActivity(
            'ancillary.sla_breached',
            $order,
            $definition,
            $breach,
            $elapsed,
            CarbonImmutable::instance($breach->breached_at),
        );
        $breach->update(['opened_event_uuid' => $activity['event_uuid']]);

        return $breach->refresh();
    }

    private function clearBreach(
        AncillaryOrder $order,
        AncillarySlaDefinition $definition,
        AncillaryBreach $breach,
        AncillaryMilestone $stop,
        float $elapsed,
        CarbonImmutable $at,
    ): AncillaryBreach {
        $clearedAt = CarbonImmutable::instance($stop->occurred_at)->setTimezone($this->clockTimezone());
        $breach->update([
            'status' => 'cleared',
            'cleared_at' => $clearedAt,
            'stop_assertion_id' => $stop->ancillary_milestone_id,
            'elapsed_minutes_at_clear' => $elapsed,
            'last_evaluated_at' => $at,
        ]);
        $activity = $this->recordActivity('ancillary.sla_cleared', $order, $definition, $breach, $elapsed, $clearedAt);
        $breach->update(['cleared_event_uuid' => $activity['event_uuid']]);

        return $breach->refresh();
    }

    /** @return array<string, mixed> */
    private function recordActivity(
        string $eventType,
        AncillaryOrder $order,
        AncillarySlaDefinition $definition,
        AncillaryBreach $breach,
        float $elapsed,
        CarbonImmutable $occurredAt,
    ): array {
        $activity = $this->activity->record($eventType, [
            'idempotency_key' => "{$eventType}:{$breach->breach_uuid}",
            'idempotency_payload' => [
                'breachUuid' => $breach->breach_uuid,
                'definitionUuid' => $definition->definition_uuid,
                'status' => $eventType === 'ancillary.sla_cleared' ? 'cleared' : 'open',
            ],
            'occurred_at' => $occurredAt,
            'source_surface' => 'ancillary_sla',
            'domain' => 'ancillary',
            'scope' => array_filter([
                'unit_id' => $order->unit_id,
            ]),
            'status' => [
                'state' => $eventType === 'ancillary.sla_cleared' ? 'cleared' : 'open',
                'severity' => $eventType === 'ancillary.sla_cleared' ? 'normal' : 'breach',
            ],
            'entities' => [
                ['entity_type' => 'ancillary_order', 'entity_ref' => $order->order_uuid],
                ['entity_type' => 'ancillary_breach', 'entity_ref' => $breach->breach_uuid],
                ['entity_type' => 'ancillary_sla_definition', 'entity_ref' => $definition->definition_uuid],
            ],
            'phi_policy' => [
                'list_safe' => true,
                'push_safe' => true,
                'requires_detail_auth' => false,
            ],
            'payload' => [
                'metricKey' => $definition->metric_key,
                'orderUuid' => $order->order_uuid,
                'breachUuid' => $breach->breach_uuid,
                'definitionUuid' => $definition->definition_uuid,
                'elapsedMinutes' => $elapsed,
                'breachMinutes' => $definition->breach_minutes,
                'sourceCutoffAt' => $order->source_cutoff_at?->toIso8601String(),
            ],
        ]);

        if ($activity === null || ! isset($activity['event_uuid'])) {
            throw new RuntimeException('Ancillary SLA activity was not durably recorded.');
        }

        return $activity;
    }

    private function selectedDefinitions(AncillaryOrder $order, CarbonImmutable $at): Collection
    {
        return AncillarySlaDefinition::query()
            ->where('department', $order->department)
            ->activeAt($at)
            ->get()
            ->filter(fn (AncillarySlaDefinition $definition): bool => $this->matchesPopulation($definition, $order))
            ->groupBy('metric_key')
            ->map(fn (Collection $definitions): AncillarySlaDefinition => $definitions
                ->sortByDesc(fn (AncillarySlaDefinition $definition): string => sprintf(
                    '%04d:%010d:%010d',
                    $this->specificity($definition),
                    $definition->version,
                    $definition->ancillary_sla_definition_id,
                ))
                ->first())
            ->values();
    }

    private function matchesPopulation(AncillarySlaDefinition $definition, AncillaryOrder $order): bool
    {
        if ($definition->priority !== null && $definition->priority !== $order->priority) {
            return false;
        }
        if ($definition->patient_class !== null && $definition->patient_class !== $order->patient_class) {
            return false;
        }

        $population = [
            'department' => $order->department,
            'priority' => $order->priority,
            'patient_class' => $order->patient_class,
            'unit_id' => $order->unit_id,
            ...$order->metadata,
        ];
        foreach ($definition->scope as $key => $expected) {
            $actual = data_get($population, (string) $key);
            if (is_array($expected) ? ! in_array($actual, $expected, true) : $actual != $expected) {
                return false;
            }
        }

        return true;
    }

    private function specificity(AncillarySlaDefinition $definition): int
    {
        return count($definition->scope)
            + ($definition->priority !== null ? 1 : 0)
            + ($definition->patient_class !== null ? 1 : 0);
    }

    private function selectedAssertion(AncillaryOrder $order, string $code): ?AncillaryMilestone
    {
        $id = DB::table('prod.ancillary_current_assertions')
            ->where('ancillary_order_id', $order->ancillary_order_id)
            ->where('milestone_code', $code)
            ->value('ancillary_milestone_id');

        return $id !== null ? AncillaryMilestone::query()->find((int) $id) : null;
    }

    private function freshness(AncillaryOrder $order, CarbonImmutable $at): FreshnessEnvelope
    {
        $cutoff = $order->source_cutoff_at !== null
            ? CarbonImmutable::instance($order->source_cutoff_at)->setTimezone($this->clockTimezone())
            : null;
        if ($cutoff === null) {
            return new FreshnessEnvelope('unknown', $at, null, null, $order->source?->source_name ?? 'Unknown source', 'No source cutoff is available.');
        }

        $lag = max(0, (int) floor(($at->getTimestamp() - $cutoff->getTimestamp()) / 60));
        $warehouse = str_contains(strtolower((string) $order->source?->system_class), 'warehouse')
            || (($order->metadata['feed_mode'] ?? null) === 'warehouse');
        if ($warehouse) {
            return new FreshnessEnvelope('batch', $at, $cutoff, $lag, $order->source?->source_name ?? 'Warehouse', 'Batch-derived clock; cutoff-qualified, not real-time.');
        }

        $freshAfter = max(0, (int) config('integrations.fresh_after_minutes', 15));
        $status = $lag <= $freshAfter ? 'fresh' : 'stale';

        return new FreshnessEnvelope(
            $status,
            $at,
            $cutoff,
            $lag,
            $order->source?->source_name ?? 'Ancillary source',
            $status === 'stale' ? 'Source evidence is outside the operational freshness window.' : null,
        );
    }

    private function result(
        AncillarySlaDefinition $definition,
        string $state,
        ?float $elapsed,
        ?AncillaryMilestone $start,
        ?AncillaryMilestone $stop,
        ?AncillaryBreach $breach,
        bool $opened,
        bool $cleared,
        FreshnessEnvelope $freshness,
        ?string $reason,
    ): AncillarySlaEvaluation {
        return new AncillarySlaEvaluation(
            definitionId: (int) $definition->ancillary_sla_definition_id,
            definitionUuid: $definition->definition_uuid,
            metricKey: $definition->metric_key,
            state: $state,
            elapsedMinutes: $elapsed,
            startAssertionId: $start?->ancillary_milestone_id,
            stopAssertionId: $stop?->ancillary_milestone_id,
            breachId: $breach?->ancillary_breach_id,
            breachOpened: $opened,
            breachCleared: $cleared,
            wasBreached: $breach !== null,
            warningAt: $breach?->warning_at !== null
                ? CarbonImmutable::instance($breach->warning_at)
                : ($start !== null && $definition->warning_minutes !== null
                    ? CarbonImmutable::instance($start->occurred_at)->addMinutes($definition->warning_minutes)
                    : null),
            breachedAt: $breach?->breached_at !== null ? CarbonImmutable::instance($breach->breached_at) : null,
            clearedAt: $breach?->cleared_at !== null ? CarbonImmutable::instance($breach->cleared_at) : null,
            freshness: $freshness,
            reason: $reason,
        );
    }

    private function evaluationTime(?CarbonInterface $evaluationAt): CarbonImmutable
    {
        return ($evaluationAt !== null ? CarbonImmutable::instance($evaluationAt) : CarbonImmutable::now())
            ->setTimezone($this->clockTimezone());
    }

    private function clockTimezone(): string
    {
        return (string) config('integrations.ancillary.clock_timezone', 'UTC');
    }
}
