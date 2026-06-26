<?php

namespace App\Services\Ops;

use App\Models\Ops\Intervention;
use App\Models\Ops\InterventionMetric;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\OutcomeAttribution;
use App\Models\PdsaCycle;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InterventionAttributionService
{
    private const ACTION_TERMINAL_STATUSES = ['completed'];

    private const PRIMARY_METRIC_BY_TYPE = [
        'bed_pressure' => 'net_beds',
        'blocked_beds' => 'blocked_beds',
        'ed_boarding' => 'ed_boarders',
        'or_pacu_pressure' => 'pacu_holds',
        'transport_sla_risk' => 'transport_at_risk',
        'discharge_barrier' => 'open_barriers',
        'simulation_action_plan' => 'risk_score',
        'create_capacity_huddle_item' => 'ed_boarders',
        'review_bed_placement_gap' => 'net_beds',
        'create_evs_bed_readiness_push' => 'blocked_beds',
        'review_or_pacu_flow' => 'pacu_holds',
        'resolve_transport_sla_risk' => 'transport_at_risk',
        'resolve_discharge_barrier' => 'open_barriers',
        'promote_simulation_action_plan' => 'risk_score',
        'pdsa_cycle' => 'risk_score',
    ];

    private const METRICS = [
        'net_beds' => ['label' => 'Net bed position', 'unit' => 'beds', 'direction' => 'up'],
        'ed_boarders' => ['label' => 'ED boarders', 'unit' => 'patients', 'direction' => 'down'],
        'transport_at_risk' => ['label' => 'Transport moves at risk', 'unit' => 'moves', 'direction' => 'down'],
        'blocked_beds' => ['label' => 'Blocked beds', 'unit' => 'beds', 'direction' => 'down'],
        'pacu_holds' => ['label' => 'PACU holds', 'unit' => 'holds', 'direction' => 'down'],
        'open_barriers' => ['label' => 'Open barriers', 'unit' => 'items', 'direction' => 'down'],
        'occupancy_pct' => ['label' => 'Occupancy', 'unit' => '%', 'direction' => 'neutral'],
        'risk_score' => ['label' => 'Operational risk score', 'unit' => 'score', 'direction' => 'down'],
    ];

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        if (! $this->attributionTablesExist()) {
            return $this->emptyDashboard('Intervention attribution tables have not been migrated yet.');
        }

        $this->sync();

        $interventions = Intervention::query()
            ->with(['recommendation', 'action', 'pdsaCycle', 'metrics', 'attribution'])
            ->orderByRaw('completed_at DESC NULLS LAST')
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get();

        if ($interventions->isEmpty()) {
            return $this->emptyDashboard('No completed actions or active PDSA cycles have been linked to attribution yet.');
        }

        $serialized = $interventions
            ->map(fn (Intervention $intervention): array => $this->serializeIntervention($intervention))
            ->values()
            ->all();
        $primaryMetrics = $interventions
            ->flatMap(fn (Intervention $intervention): Collection => $intervention->metrics->where('is_primary', true));
        $improvedPrimary = $primaryMetrics->where('status', 'success')->count();
        $balancingWarnings = $interventions
            ->flatMap(fn (Intervention $intervention): Collection => $intervention->metrics->where('measure_type', 'balancing'))
            ->whereIn('status', ['warning', 'critical'])
            ->count();
        $netBedGain = $interventions
            ->flatMap(fn (Intervention $intervention): Collection => $intervention->metrics->where('metric_key', 'net_beds'))
            ->sum(fn (InterventionMetric $metric): float => max(0, (float) $metric->delta_value));
        $confidenceScores = $interventions
            ->pluck('attribution')
            ->filter()
            ->map(fn (OutcomeAttribution $attribution): float => (float) $attribution->confidence_score);
        $confidenceScore = $confidenceScores->isNotEmpty() ? round($confidenceScores->avg(), 1) : 0.0;
        $confidenceLevel = $this->confidenceLevel($confidenceScore);

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'summary' => [
                'totalInterventions' => $interventions->count(),
                'completedInterventions' => $interventions->where('status', 'completed')->count(),
                'measuringInterventions' => $interventions->where('status', 'measuring')->count(),
                'primaryOutcomesImproved' => $improvedPrimary,
                'primaryOutcomeCount' => $primaryMetrics->count(),
                'balancingWarnings' => $balancingWarnings,
                'estimatedNetBedGain' => round($netBedGain, 1),
                'confidenceScore' => $confidenceScore,
                'confidenceLevel' => $confidenceLevel,
                'confidenceLanguage' => $this->portfolioConfidenceLanguage($confidenceLevel, $balancingWarnings),
            ],
            'cards' => [
                $this->impactCard('Attributed interventions', (string) $interventions->count(), 'records', 'info', 'Completed actions and PDSA cycles with before/after windows.'),
                $this->impactCard('Estimated net bed gain', $this->formatNumber($netBedGain), 'beds', $netBedGain > 0 ? 'success' : 'neutral', 'Sum of positive net-bed movement across attributed interventions.'),
                $this->impactCard('Primary outcomes improved', "{$improvedPrimary}/{$primaryMetrics->count()}", 'outcomes', $improvedPrimary > 0 ? 'success' : 'warning', 'Primary metrics moving in the intended direction.'),
                $this->impactCard('Attribution confidence', $confidenceLevel, (string) $confidenceScore, $confidenceScore >= 70 ? 'success' : ($confidenceScore >= 50 ? 'warning' : 'neutral'), 'Before/after evidence with balancing-measure caveats.'),
            ],
            'comparisonOptions' => $this->comparisonOptions(),
            'interventions' => $serialized,
        ];
    }

    public function sync(): Collection
    {
        $actionInterventions = $this->syncCompletedActions();
        $pdsaInterventions = $this->syncPdsaCycles();

        return $actionInterventions->merge($pdsaInterventions)->values();
    }

    private function syncCompletedActions(): Collection
    {
        if (! Schema::hasTable('ops.actions')) {
            return collect();
        }

        return OperationalAction::query()
            ->with('recommendation')
            ->whereIn('status', self::ACTION_TERMINAL_STATUSES)
            ->orderByDesc('completed_at')
            ->limit(100)
            ->get()
            ->map(fn (OperationalAction $action): Intervention => $this->materializeForAction($action));
    }

    private function syncPdsaCycles(): Collection
    {
        if (! Schema::hasTable('prod.pdsa_cycles')) {
            return collect();
        }

        return PdsaCycle::query()
            ->whereIn('status', ['active', 'completed'])
            ->where('is_deleted', false)
            ->orderByRaw('completed_at DESC NULLS LAST')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->filter(fn (PdsaCycle $cycle): bool => ! Intervention::query()
                ->where('pdsa_cycle_id', $cycle->pdsa_cycle_id)
                ->exists())
            ->map(fn (PdsaCycle $cycle): Intervention => $this->materializeForPdsaCycle($cycle))
            ->values();
    }

    private function materializeForAction(OperationalAction $action): Intervention
    {
        $action->loadMissing('recommendation');
        $completedAt = $action->completed_at ?? now();
        $startedAt = $action->executed_at ?? $action->assigned_at ?? $action->approved_at ?? $completedAt->copy()->subHour();
        $windows = $this->windowsFor($completedAt);
        $pdsaCycleId = $this->pdsaCycleIdForAction($action);
        $scenarioId = $this->simulationScenarioIdForAction($action);
        $primaryMetric = $this->primaryMetricFor($action->recommendation?->recommendation_type, $action->action_type, $action->recommendation?->expected_impact ?? []);

        /** @var Intervention $intervention */
        $intervention = Intervention::firstOrNew(['action_id' => $action->action_id]);
        if (! $intervention->exists) {
            $intervention->intervention_uuid = (string) Str::uuid();
        }

        $intervention->fill([
            'recommendation_id' => $action->recommendation_id,
            'pdsa_cycle_id' => $pdsaCycleId,
            'simulation_scenario_id' => $scenarioId,
            'intervention_type' => $action->recommendation?->recommendation_type ?? $action->action_type,
            'scope_type' => $action->recommendation?->scope_type ?? 'hospital',
            'scope_key' => $action->recommendation?->scope_key,
            'title' => $action->recommendation?->title ?? Str::headline($action->action_type),
            'status' => 'completed',
            'owner_name' => $action->owner_name ?? data_get($action->payload, 'owner'),
            'hypothesis' => $this->hypothesisFor($primaryMetric),
            'attribution_method' => 'before_after',
            'comparison_strategy' => 'before_after',
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'baseline_started_at' => $windows['baseline_start'],
            'baseline_ended_at' => $windows['baseline_end'],
            'followup_started_at' => $windows['followup_start'],
            'followup_ended_at' => $windows['followup_end'],
            'evidence_payload' => [
                'recommendation_uuid' => $action->recommendation?->recommendation_uuid,
                'action_uuid' => $action->action_uuid,
                'action_type' => $action->action_type,
                'completion_payload' => $action->completion_payload ?? [],
                'expected_impact' => $action->recommendation?->expected_impact ?? [],
            ],
            'stratification_payload' => [
                'scope_type' => $action->recommendation?->scope_type ?? 'hospital',
                'scope_key' => $action->recommendation?->scope_key,
                'owner_name' => $action->owner_name,
            ],
        ])->save();

        $this->materializeMetrics($intervention, $primaryMetric);
        $this->materializeAttribution($intervention);

        return $intervention->refresh()->load(['recommendation', 'action', 'pdsaCycle', 'metrics', 'attribution']);
    }

    private function materializeForPdsaCycle(PdsaCycle $cycle): Intervention
    {
        $completedAt = $cycle->completed_at ?? now();
        $startedAt = $cycle->started_at ?? $completedAt->copy()->subDays(7);
        $windows = $this->windowsFor($completedAt);

        /** @var Intervention $intervention */
        $intervention = Intervention::firstOrNew([
            'pdsa_cycle_id' => $cycle->pdsa_cycle_id,
            'action_id' => null,
        ]);
        if (! $intervention->exists) {
            $intervention->intervention_uuid = (string) Str::uuid();
        }

        $intervention->fill([
            'intervention_type' => 'pdsa_cycle',
            'scope_type' => $cycle->unit_id ? 'unit' : 'hospital',
            'scope_key' => $cycle->unit_id ? (string) $cycle->unit_id : 'improvement',
            'title' => $cycle->title,
            'status' => $cycle->status === 'completed' ? 'completed' : 'measuring',
            'owner_name' => $cycle->owner,
            'hypothesis' => $cycle->objective,
            'attribution_method' => 'before_after',
            'comparison_strategy' => $cycle->unit_id ? 'unit_stratified' : 'before_after',
            'started_at' => $startedAt,
            'completed_at' => $cycle->completed_at,
            'baseline_started_at' => $windows['baseline_start'],
            'baseline_ended_at' => $windows['baseline_end'],
            'followup_started_at' => $windows['followup_start'],
            'followup_ended_at' => $windows['followup_end'],
            'evidence_payload' => [
                'pdsa_cycle_id' => $cycle->pdsa_cycle_id,
                'objective' => $cycle->objective,
                'status' => $cycle->status,
            ],
            'stratification_payload' => [
                'unit_id' => $cycle->unit_id,
                'owner_name' => $cycle->owner,
            ],
        ])->save();

        $this->materializeMetrics($intervention, 'risk_score');
        $this->materializeAttribution($intervention);

        return $intervention->refresh()->load(['pdsaCycle', 'metrics', 'attribution']);
    }

    private function materializeMetrics(Intervention $intervention, string $primaryMetric): void
    {
        $metricKeys = collect([$primaryMetric, 'occupancy_pct', 'transport_at_risk'])
            ->unique()
            ->values();

        foreach ($metricKeys as $metricKey) {
            $definition = self::METRICS[$metricKey] ?? self::METRICS['risk_score'];
            $baselineValue = $this->metricValueAt($metricKey, $intervention->baseline_ended_at ?? now());
            $followupValue = $this->metricValueAt($metricKey, $intervention->followup_ended_at ?? now());
            $delta = $baselineValue === null || $followupValue === null ? null : $followupValue - $baselineValue;
            $deltaPct = $delta !== null && $baselineValue !== null && abs($baselineValue) > 0.0001
                ? ($delta / abs($baselineValue)) * 100
                : null;
            $measureType = $metricKey === $primaryMetric ? 'outcome' : 'balancing';
            $status = $this->metricStatus($metricKey, $definition['direction'], $delta, $followupValue, $measureType);

            InterventionMetric::updateOrCreate(
                [
                    'intervention_id' => $intervention->intervention_id,
                    'metric_key' => $metricKey,
                ],
                [
                    'label' => $definition['label'],
                    'measure_type' => $measureType,
                    'unit' => $definition['unit'],
                    'direction' => $definition['direction'],
                    'baseline_value' => $baselineValue,
                    'followup_value' => $followupValue,
                    'delta_value' => $delta,
                    'delta_pct' => $deltaPct,
                    'status' => $status,
                    'is_primary' => $metricKey === $primaryMetric,
                    'baseline_started_at' => $intervention->baseline_started_at,
                    'baseline_ended_at' => $intervention->baseline_ended_at,
                    'followup_started_at' => $intervention->followup_started_at,
                    'followup_ended_at' => $intervention->followup_ended_at,
                    'source_payload' => [
                        'source_tables' => $this->sourceTablesForMetric($metricKey),
                        'attribution_method' => $intervention->attribution_method,
                        'comparison_strategy' => $intervention->comparison_strategy,
                    ],
                ]
            );
        }
    }

    private function materializeAttribution(Intervention $intervention): void
    {
        $intervention->load('metrics');
        $primary = $intervention->metrics->firstWhere('is_primary', true);
        $balancing = $intervention->metrics->where('measure_type', 'balancing');
        $balancingWarnings = $balancing->whereIn('status', ['warning', 'critical'])->count();
        $primaryImproved = $primary?->status === 'success';
        $confidenceScore = $this->confidenceScore($primary, $balancingWarnings);
        $confidenceLevel = $this->confidenceLevel($confidenceScore);
        $confidenceLanguage = $this->interventionConfidenceLanguage($confidenceLevel, $primaryImproved, $balancingWarnings);
        $summary = $this->executiveSummary($intervention, $primary, $confidenceLevel);

        OutcomeAttribution::updateOrCreate(
            ['intervention_id' => $intervention->intervention_id],
            [
                'attribution_method' => $intervention->attribution_method,
                'comparison_strategy' => $intervention->comparison_strategy,
                'confidence_level' => $confidenceLevel,
                'confidence_score' => $confidenceScore,
                'confidence_language' => $confidenceLanguage,
                'sample_size' => $primary?->baseline_value !== null && $primary?->followup_value !== null ? 2 : 0,
                'balancing_summary' => [
                    'warning_count' => $balancingWarnings,
                    'metrics' => $balancing
                        ->map(fn (InterventionMetric $metric): array => [
                            'metricKey' => $metric->metric_key,
                            'status' => $metric->status,
                            'deltaValue' => $this->nullableFloat($metric->delta_value),
                        ])
                        ->values()
                        ->all(),
                ],
                'caveats' => [
                    'Before/after attribution is directional until matched-unit or matched-weekday comparison is selected.',
                    'Operational data freshness and documentation completeness still affect confidence.',
                ],
                'comparison_options' => $this->comparisonOptions($intervention),
                'executive_summary' => $summary,
                'calculated_at' => now(),
            ]
        );

        $intervention->forceFill([
            'confidence_level' => $confidenceLevel,
            'confidence_language' => $confidenceLanguage,
        ])->save();
    }

    private function metricValueAt(string $metricKey, Carbon $windowEnd): ?float
    {
        return match ($metricKey) {
            'net_beds' => $this->netBedsAt($windowEnd),
            'ed_boarders' => $this->edBoardersAt($windowEnd),
            'transport_at_risk' => $this->transportAtRiskAt($windowEnd),
            'blocked_beds' => $this->capacityAt($windowEnd)['blocked'],
            'pacu_holds' => $this->pacuHoldsAt($windowEnd),
            'open_barriers' => $this->openBarriersAt($windowEnd),
            'occupancy_pct' => $this->capacityAt($windowEnd)['occupancy_pct'],
            'risk_score' => $this->riskScoreAt($windowEnd),
            default => null,
        };
    }

    private function netBedsAt(Carbon $windowEnd): float
    {
        return $this->capacityAt($windowEnd)['available'] - $this->pendingBedRequestsAt($windowEnd);
    }

    /** @return array{staffed:float,occupied:float,available:float,blocked:float,occupancy_pct:float} */
    private function capacityAt(Carbon $windowEnd): array
    {
        if (! Schema::hasTable('prod.census_snapshots')) {
            return ['staffed' => 0.0, 'occupied' => 0.0, 'available' => 0.0, 'blocked' => 0.0, 'occupancy_pct' => 0.0];
        }

        $row = DB::selectOne(<<<'SQL'
            WITH latest AS (
                SELECT DISTINCT ON (unit_id)
                    unit_id, staffed_beds, occupied, available, blocked, captured_at
                FROM prod.census_snapshots
                WHERE captured_at <= ?
                ORDER BY unit_id, captured_at DESC
            )
            SELECT
                COALESCE(SUM(staffed_beds), 0) AS staffed,
                COALESCE(SUM(occupied), 0) AS occupied,
                COALESCE(SUM(available), 0) AS available,
                COALESCE(SUM(blocked), 0) AS blocked
            FROM latest
        SQL, [$windowEnd->toDateTimeString()]);

        $staffed = (float) ($row->staffed ?? 0);
        $occupied = (float) ($row->occupied ?? 0);

        return [
            'staffed' => $staffed,
            'occupied' => $occupied,
            'available' => (float) ($row->available ?? 0),
            'blocked' => (float) ($row->blocked ?? 0),
            'occupancy_pct' => $staffed > 0 ? round($occupied / $staffed * 100, 1) : 0.0,
        ];
    }

    private function pendingBedRequestsAt(Carbon $windowEnd): int
    {
        return $this->countAt('prod.bed_requests', function (Builder $query) use ($windowEnd): void {
            $query->where('status', 'pending')
                ->where('created_at', '<=', $windowEnd)
                ->where('is_deleted', false);
        });
    }

    private function edBoardersAt(Carbon $windowEnd): int
    {
        return $this->countAt('prod.ed_visits', function (Builder $query) use ($windowEnd): void {
            $query->where('disposition', 'admitted')
                ->where('admit_decision_at', '<=', $windowEnd)
                ->where(function (Builder $inner) use ($windowEnd): void {
                    $inner->whereNull('bed_assigned_at')
                        ->orWhere('bed_assigned_at', '>', $windowEnd);
                })
                ->where('is_deleted', false);
        });
    }

    private function transportAtRiskAt(Carbon $windowEnd): int
    {
        return $this->countAt('prod.transport_requests', function (Builder $query) use ($windowEnd): void {
            $query->whereIn('status', ['requested', 'assigned', 'in_progress', 'escalated'])
                ->where('requested_at', '<=', $windowEnd)
                ->where(function (Builder $inner) use ($windowEnd): void {
                    $inner->where('priority', 'stat')
                        ->orWhere('needed_at', '<', $windowEnd);
                })
                ->where('is_deleted', false);
        });
    }

    private function pacuHoldsAt(Carbon $windowEnd): int
    {
        if (! Schema::hasTable('prod.or_logs') || ! Schema::hasTable('prod.or_cases')) {
            return 0;
        }

        return (int) DB::table('prod.or_logs as logs')
            ->join('prod.or_cases as cases', 'cases.case_id', '=', 'logs.case_id')
            ->where('cases.is_deleted', false)
            ->where('logs.is_deleted', false)
            ->whereNotNull('logs.pacu_in_time')
            ->where('logs.pacu_in_time', '<=', $windowEnd->copy()->subMinutes(75))
            ->where(function (Builder $query) use ($windowEnd): void {
                $query->whereNull('logs.pacu_out_time')
                    ->orWhere('logs.pacu_out_time', '>', $windowEnd);
            })
            ->count();
    }

    private function openBarriersAt(Carbon $windowEnd): int
    {
        return $this->countAt('prod.barriers', function (Builder $query) use ($windowEnd): void {
            $query->where('status', 'open')
                ->where('opened_at', '<=', $windowEnd)
                ->where('is_deleted', false);
        });
    }

    private function riskScoreAt(Carbon $windowEnd): float
    {
        $capacity = $this->capacityAt($windowEnd);
        $netBeds = $capacity['available'] - $this->pendingBedRequestsAt($windowEnd);

        return (float) min(100, max(0, round(
            max(0, -$netBeds) * 10
            + min(20, $this->edBoardersAt($windowEnd)) * 2
            + min(20, $this->transportAtRiskAt($windowEnd)) * 2
            + min(20, $capacity['blocked']) * 2
            + min(10, $this->pacuHoldsAt($windowEnd)) * 3
        )));
    }

    private function countAt(string $table, callable $callback): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        $callback($query);

        return (int) $query->count();
    }

    /** @return array{baseline_start:Carbon,baseline_end:Carbon,followup_start:Carbon,followup_end:Carbon} */
    private function windowsFor(Carbon $anchor): array
    {
        return [
            'baseline_start' => $anchor->copy()->subDay(),
            'baseline_end' => $anchor->copy(),
            'followup_start' => $anchor->copy(),
            'followup_end' => now()->greaterThan($anchor) ? now() : $anchor->copy()->addHour(),
        ];
    }

    private function primaryMetricFor(?string $recommendationType, string $actionType, array $expectedImpact): string
    {
        $expectedMetric = data_get($expectedImpact, 'metric');

        if (is_string($expectedMetric) && isset(self::METRICS[$expectedMetric])) {
            return $expectedMetric;
        }

        return self::PRIMARY_METRIC_BY_TYPE[$recommendationType ?? ''] ?? self::PRIMARY_METRIC_BY_TYPE[$actionType] ?? 'risk_score';
    }

    private function pdsaCycleIdForAction(OperationalAction $action): ?int
    {
        $pdsaCycleId = data_get($action->completion_payload, 'pdsa_cycle_id')
            ?? data_get($action->completion_payload, 'pdsaCycleId')
            ?? data_get($action->payload, 'pdsa_cycle_id')
            ?? data_get($action->payload, 'pdsaCycleId');

        if (! $pdsaCycleId || ! Schema::hasTable('prod.pdsa_cycles')) {
            return null;
        }

        return PdsaCycle::query()->whereKey((int) $pdsaCycleId)->exists() ? (int) $pdsaCycleId : null;
    }

    private function simulationScenarioIdForAction(OperationalAction $action): ?int
    {
        $scenarioUuid = data_get($action->payload, 'simulationScenarioUuid')
            ?? data_get($action->completion_payload, 'simulationScenarioUuid');

        if (! is_string($scenarioUuid) || ! Schema::hasTable('ops.simulation_scenarios')) {
            return null;
        }

        $row = DB::table('ops.simulation_scenarios')
            ->where('simulation_scenario_uuid', $scenarioUuid)
            ->first(['simulation_scenario_id']);

        return $row ? (int) $row->simulation_scenario_id : null;
    }

    private function hypothesisFor(string $metricKey): string
    {
        $definition = self::METRICS[$metricKey] ?? self::METRICS['risk_score'];
        $direction = $definition['direction'] === 'up' ? 'increase' : 'reduce';

        return "The intervention should {$direction} {$definition['label']} without worsening balancing measures.";
    }

    private function metricStatus(string $metricKey, string $direction, ?float $delta, ?float $followupValue, string $measureType): string
    {
        if ($delta === null) {
            return 'warning';
        }

        if ($measureType === 'balancing') {
            if ($metricKey === 'occupancy_pct' && $followupValue !== null && $followupValue >= 95) {
                return 'critical';
            }

            if ($delta > 0 && in_array($metricKey, ['transport_at_risk', 'occupancy_pct'], true)) {
                return 'warning';
            }

            return 'neutral';
        }

        return match ($direction) {
            'up' => $delta > 0 ? 'success' : ($delta < 0 ? 'warning' : 'neutral'),
            'down' => $delta < 0 ? 'success' : ($delta > 0 ? 'warning' : 'neutral'),
            default => 'neutral',
        };
    }

    private function confidenceScore(?InterventionMetric $primary, int $balancingWarnings): float
    {
        if (! $primary || $primary->baseline_value === null || $primary->followup_value === null) {
            return 35.0;
        }

        $score = $primary->status === 'success' ? 72.0 : ($primary->status === 'neutral' ? 52.0 : 42.0);
        $score -= min(20, $balancingWarnings * 8);

        return max(20.0, min(90.0, $score));
    }

    private function confidenceLevel(float $score): string
    {
        return match (true) {
            $score >= 75 => 'high',
            $score >= 55 => 'medium',
            $score >= 40 => 'directional',
            default => 'insufficient',
        };
    }

    private function interventionConfidenceLanguage(string $level, bool $primaryImproved, int $balancingWarnings): string
    {
        if ($level === 'insufficient') {
            return 'Insufficient before/after evidence is available for attribution.';
        }

        $movement = $primaryImproved ? 'the primary outcome moved in the intended direction' : 'the primary outcome has not clearly moved in the intended direction';
        $balance = $balancingWarnings > 0 ? "with {$balancingWarnings} balancing warning(s)" : 'without balancing warnings';

        return Str::ucfirst("{$level} confidence: {$movement} {$balance}; this remains directional until a matched comparison is selected.");
    }

    private function portfolioConfidenceLanguage(string $level, int $balancingWarnings): string
    {
        $balance = $balancingWarnings > 0
            ? "{$balancingWarnings} balancing warning(s) require executive review."
            : 'No balancing warnings are currently open.';

        return Str::ucfirst("{$level} confidence portfolio attribution based on before/after windows. {$balance}");
    }

    private function executiveSummary(Intervention $intervention, ?InterventionMetric $primary, string $confidenceLevel): string
    {
        if (! $primary) {
            return "{$intervention->title} is linked, but no primary outcome metric has been materialized yet.";
        }

        $delta = $this->formatNumber((float) $primary->delta_value);

        return "{$intervention->title}: {$primary->label} changed by {$delta} {$primary->unit} with {$confidenceLevel} confidence.";
    }

    /** @return array<int,string> */
    private function sourceTablesForMetric(string $metricKey): array
    {
        return match ($metricKey) {
            'net_beds', 'blocked_beds', 'occupancy_pct', 'risk_score' => ['prod.census_snapshots', 'prod.bed_requests'],
            'ed_boarders' => ['prod.ed_visits'],
            'transport_at_risk' => ['prod.transport_requests'],
            'pacu_holds' => ['prod.or_logs', 'prod.or_cases'],
            'open_barriers' => ['prod.barriers'],
            default => [],
        };
    }

    /** @return array<int,array<string,string>> */
    private function comparisonOptions(?Intervention $intervention = null): array
    {
        $unitScoped = $intervention?->scope_type === 'unit';

        return [
            [
                'key' => 'before_after',
                'label' => 'Before/after window',
                'status' => 'active',
            ],
            [
                'key' => 'unit_stratified',
                'label' => 'Unit-stratified comparison',
                'status' => $unitScoped ? 'available' : 'needs_unit_scope',
            ],
            [
                'key' => 'matched_weekday',
                'label' => 'Matched weekday comparison',
                'status' => 'available_when_history_depth_sufficient',
            ],
        ];
    }

    private function impactCard(string $label, string $value, string $unit, string $status, string $detail): array
    {
        return compact('label', 'value', 'unit', 'status', 'detail');
    }

    /** @return array<string,mixed> */
    private function serializeIntervention(Intervention $intervention): array
    {
        $intervention->loadMissing(['recommendation', 'action', 'pdsaCycle', 'metrics', 'attribution']);

        return [
            'interventionId' => $intervention->intervention_id,
            'interventionUuid' => $intervention->intervention_uuid,
            'recommendationId' => $intervention->recommendation_id,
            'recommendationUuid' => $intervention->recommendation?->recommendation_uuid,
            'actionId' => $intervention->action_id,
            'actionUuid' => $intervention->action?->action_uuid,
            'pdsaCycleId' => $intervention->pdsa_cycle_id,
            'pdsaTitle' => $intervention->pdsaCycle?->title,
            'simulationScenarioId' => $intervention->simulation_scenario_id,
            'type' => $intervention->intervention_type,
            'scopeType' => $intervention->scope_type,
            'scopeKey' => $intervention->scope_key,
            'title' => $intervention->title,
            'status' => $intervention->status,
            'ownerName' => $intervention->owner_name,
            'hypothesis' => $intervention->hypothesis,
            'attributionMethod' => $intervention->attribution_method,
            'comparisonStrategy' => $intervention->comparison_strategy,
            'confidenceLevel' => $intervention->confidence_level,
            'confidenceLanguage' => $intervention->confidence_language,
            'startedAtIso' => $intervention->started_at?->toIso8601String(),
            'completedAtIso' => $intervention->completed_at?->toIso8601String(),
            'windows' => [
                'baselineStartedAtIso' => $intervention->baseline_started_at?->toIso8601String(),
                'baselineEndedAtIso' => $intervention->baseline_ended_at?->toIso8601String(),
                'followupStartedAtIso' => $intervention->followup_started_at?->toIso8601String(),
                'followupEndedAtIso' => $intervention->followup_ended_at?->toIso8601String(),
            ],
            'metrics' => $intervention->metrics
                ->sortByDesc('is_primary')
                ->values()
                ->map(fn (InterventionMetric $metric): array => [
                    'metricId' => $metric->intervention_metric_id,
                    'metricKey' => $metric->metric_key,
                    'label' => $metric->label,
                    'measureType' => $metric->measure_type,
                    'unit' => $metric->unit,
                    'direction' => $metric->direction,
                    'baselineValue' => $this->nullableFloat($metric->baseline_value),
                    'followupValue' => $this->nullableFloat($metric->followup_value),
                    'deltaValue' => $this->nullableFloat($metric->delta_value),
                    'deltaPct' => $this->nullableFloat($metric->delta_pct),
                    'status' => $metric->status,
                    'isPrimary' => $metric->is_primary,
                ])
                ->all(),
            'attribution' => $intervention->attribution ? [
                'confidenceLevel' => $intervention->attribution->confidence_level,
                'confidenceScore' => (float) $intervention->attribution->confidence_score,
                'confidenceLanguage' => $intervention->attribution->confidence_language,
                'sampleSize' => $intervention->attribution->sample_size,
                'balancingSummary' => $intervention->attribution->balancing_summary ?? [],
                'caveats' => $intervention->attribution->caveats ?? [],
                'comparisonOptions' => $intervention->attribution->comparison_options ?? [],
                'executiveSummary' => $intervention->attribution->executive_summary,
                'calculatedAtIso' => $intervention->attribution->calculated_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }

    private function attributionTablesExist(): bool
    {
        return Schema::hasTable('ops.interventions')
            && Schema::hasTable('ops.intervention_metrics')
            && Schema::hasTable('ops.outcome_attribution');
    }

    /** @return array<string,mixed> */
    private function emptyDashboard(string $message): array
    {
        return [
            'generatedAtIso' => now()->toIso8601String(),
            'summary' => [
                'totalInterventions' => 0,
                'completedInterventions' => 0,
                'measuringInterventions' => 0,
                'primaryOutcomesImproved' => 0,
                'primaryOutcomeCount' => 0,
                'balancingWarnings' => 0,
                'estimatedNetBedGain' => 0,
                'confidenceScore' => 0,
                'confidenceLevel' => 'insufficient',
                'confidenceLanguage' => $message,
            ],
            'cards' => [
                $this->impactCard('Attributed interventions', '0', 'records', 'neutral', $message),
                $this->impactCard('Estimated net bed gain', '0', 'beds', 'neutral', 'No measured intervention outcomes yet.'),
                $this->impactCard('Primary outcomes improved', '0/0', 'outcomes', 'neutral', 'No primary outcomes have been measured yet.'),
                $this->impactCard('Attribution confidence', 'insufficient', '0', 'neutral', 'No attribution evidence has been materialized yet.'),
            ],
            'comparisonOptions' => $this->comparisonOptions(),
            'interventions' => [],
        ];
    }
}
