<?php

namespace App\Services\Ops;

use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\Recommendation;
use App\Models\Ops\SimulationResult;
use App\Models\Ops\SimulationRun;
use App\Models\Ops\SimulationScenario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class OperationsSimulationService
{
    private const TERMINAL_ACTION_STATUSES = ['completed', 'rejected', 'overridden', 'expired'];

    public function __construct(private readonly OperationsGraphProjector $projector) {}

    /** @return array<string,mixed> */
    public function runCapacityWorkbench(?int $actorUserId = null): array
    {
        $snapshot = $this->projector->rebuild();
        $baseline = $this->baselinePayload();
        $run = SimulationRun::create([
            'simulation_run_uuid' => (string) Str::uuid(),
            'baseline_snapshot_id' => $snapshot->state_snapshot_id,
            'scope_type' => 'hospital',
            'scope_key' => 'capacity_workbench',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
            'baseline_payload' => $baseline,
            'summary_payload' => [],
            'created_by_user_id' => $actorUserId,
        ]);

        $scenarios = collect($this->scenarioDefinitions($baseline))
            ->map(function (array $scenario) use ($run, $baseline): array {
                $scenarioModel = SimulationScenario::create([
                    'simulation_scenario_uuid' => (string) Str::uuid(),
                    'simulation_run_id' => $run->simulation_run_id,
                    'scenario_key' => $scenario['key'],
                    'title' => $scenario['title'],
                    'assumption' => $scenario['assumption'],
                    'status' => 'modeled',
                    'intervention_payload' => $scenario,
                ]);

                $this->persistResults($scenarioModel, $baseline, $scenario);

                return $this->serializeScenario($scenarioModel->refresh()->load('results'));
            })
            ->sortBy([
                ['riskScore', 'asc'],
                ['netBedForecast', 'desc'],
            ])
            ->values();

        $best = $scenarios->firstWhere('key', '<>', 'no_action') ?? $scenarios->first();
        $summary = [
            'simulationRunId' => $run->simulation_run_id,
            'simulationRunUuid' => $run->simulation_run_uuid,
            'baselineSnapshotId' => $snapshot->state_snapshot_id,
            'scenarioCount' => $scenarios->count(),
            'currentNetBeds' => $baseline['current_net_beds'],
            'bestScenarioKey' => $best['key'] ?? null,
            'bestNetBeds' => $best['netBedForecast'] ?? $baseline['current_net_beds'],
            'bestRiskScore' => $best['riskScore'] ?? $baseline['risk_score'],
            'promotionAvailable' => $best !== null && ($best['key'] ?? null) !== 'no_action',
        ];

        $run->forceFill(['summary_payload' => $summary])->save();

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'run' => [
                'simulationRunId' => $run->simulation_run_id,
                'simulationRunUuid' => $run->simulation_run_uuid,
                'status' => $run->status,
                'baselineSnapshotId' => $snapshot->state_snapshot_id,
                'baselineSnapshotUuid' => $snapshot->snapshot_uuid,
                'startedAtIso' => $run->started_at?->toIso8601String(),
                'completedAtIso' => $run->completed_at?->toIso8601String(),
            ],
            'baseline' => $baseline,
            'summary' => $summary,
            'scenarios' => $scenarios->all(),
        ];
    }

    /** @return array<string,mixed> */
    public function promoteScenario(SimulationScenario $scenario, ?int $actorUserId = null): array
    {
        if ($scenario->scenario_key === 'no_action') {
            throw new RuntimeException('The no-action scenario cannot be promoted.');
        }

        $scenario->loadMissing(['run', 'results']);
        $recommendation = Recommendation::firstOrNew([
            'recommendation_type' => 'simulation_action_plan',
            'scope_type' => 'simulation',
            'scope_key' => $scenario->simulation_scenario_uuid,
        ]);

        if (! $recommendation->exists) {
            $recommendation->recommendation_uuid = (string) Str::uuid();
            $recommendation->status = 'draft';
        }

        $results = $scenario->results->keyBy('metric_key');
        $recommendation->fill([
            'title' => "Promote simulation plan: {$scenario->title}",
            'rationale' => $scenario->assumption,
            'confidence' => 0.8000,
            'risk_level' => ((float) ($results['risk_score']?->projected_value ?? 100)) >= 70 ? 'high' : 'medium',
            'expected_impact' => [
                'net_beds_delta' => (float) ($results['net_beds']?->delta_value ?? 0),
                'risk_score_delta' => (float) ($results['risk_score']?->delta_value ?? 0),
                'metric_count' => $scenario->results->count(),
            ],
            'evidence' => [
                'simulation_run_uuid' => $scenario->run?->simulation_run_uuid,
                'simulation_scenario_uuid' => $scenario->simulation_scenario_uuid,
                'baseline_snapshot_id' => $scenario->run?->baseline_snapshot_id,
                'source_tables' => ['ops.simulation_runs', 'ops.simulation_scenarios', 'ops.simulation_results', 'ops.state_snapshots'],
                'facts' => $this->scenarioFacts($scenario),
                'graph_path' => 'state_snapshot -> simulation_scenario -> promoted action plan',
                'generated_at' => now()->toIso8601String(),
            ],
            'created_by_source' => 'simulation:operations_simulation_service',
        ])->save();

        $action = OperationalAction::query()
            ->where('recommendation_id', $recommendation->recommendation_id)
            ->where('action_type', 'promote_simulation_action_plan')
            ->whereNotIn('status', self::TERMINAL_ACTION_STATUSES)
            ->first() ?? new OperationalAction([
                'recommendation_id' => $recommendation->recommendation_id,
                'action_type' => 'promote_simulation_action_plan',
                'status' => 'draft',
            ]);

        if (! $action->exists) {
            $action->action_uuid = (string) Str::uuid();
        }

        $action->fill([
            'owner_name' => 'Operations command team',
            'expires_at' => now()->addHours(8),
            'payload' => [
                'owner' => 'Operations command team',
                'route' => '/analytics/workbench',
                'instruction' => "Review and stage the {$scenario->title} simulation plan in the next capacity huddle.",
                'simulationScenarioUuid' => $scenario->simulation_scenario_uuid,
                'interventions' => $scenario->intervention_payload['interventions'] ?? [],
            ],
        ])->save();

        $approval = Approval::query()
            ->where('action_id', $action->action_id)
            ->whereIn('status', ['pending', 'approved'])
            ->first() ?? new Approval([
                'action_id' => $action->action_id,
                'status' => 'pending',
            ]);

        if (! $approval->exists) {
            $approval->approval_uuid = (string) Str::uuid();
            $approval->requested_at = now();
        }

        $approval->fill([
            'requested_by_user_id' => $actorUserId,
            'reason' => 'Human approval required before executing promoted simulation action plan.',
        ])->save();

        $scenario->forceFill([
            'status' => 'promoted',
            'promoted_at' => now(),
            'promoted_recommendation_id' => $recommendation->recommendation_id,
        ])->save();

        return [
            'scenario' => $this->serializeScenario($scenario->refresh()->load(['results', 'promotedRecommendation.actions.approvals'])),
            'recommendation' => [
                'recommendationId' => $recommendation->recommendation_id,
                'recommendationUuid' => $recommendation->recommendation_uuid,
                'status' => $recommendation->status,
                'title' => $recommendation->title,
                'actions' => $recommendation->actions()
                    ->with('approvals')
                    ->get()
                    ->map(fn (OperationalAction $action): array => [
                        'actionId' => $action->action_id,
                        'actionUuid' => $action->action_uuid,
                        'status' => $action->status,
                        'type' => $action->action_type,
                        'approvals' => $action->approvals
                            ->map(fn (Approval $approval): array => [
                                'approvalId' => $approval->approval_id,
                                'approvalUuid' => $approval->approval_uuid,
                                'status' => $approval->status,
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function baselinePayload(): array
    {
        $capacity = $this->capacityBaseline();
        $pendingBeds = $this->countRows('prod.bed_requests', fn ($query) => $query
            ->where('status', 'pending')
            ->where('is_deleted', false));
        $edBoarding = $this->countRows('prod.ed_visits', fn ($query) => $query
            ->where('disposition', 'admitted')
            ->whereNull('bed_assigned_at')
            ->where('is_deleted', false));
        $transportAtRisk = $this->countRows('prod.transport_requests', fn ($query) => $query
            ->whereIn('status', ['requested', 'assigned', 'in_progress', 'escalated'])
            ->where('is_deleted', false)
            ->where(function ($inner): void {
                $inner->where('priority', 'stat')->orWhere('needed_at', '<', now());
            }));
        $activeEvs = $this->countRows('prod.evs_requests', fn ($query) => $query
            ->whereNotIn('status', ['completed', 'canceled', 'failed'])
            ->where('is_deleted', false));
        $evsAtRisk = $this->countRows('prod.evs_requests', fn ($query) => $query
            ->whereNotIn('status', ['completed', 'canceled', 'failed'])
            ->where('is_deleted', false)
            ->where(function ($inner): void {
                $inner->where('priority', 'stat')->orWhere('needed_at', '<', now());
            }));
        $dirtyBeds = $this->countRows('prod.beds', fn ($query) => $query
            ->whereIn('status', ['dirty', 'blocked'])
            ->where('is_deleted', false));
        $weightedDischarges = $this->weightedDischarges();
        $pacuHolds = $this->pacuHolds();
        $upcomingOrCases = $this->upcomingOrCases();
        $currentNetBeds = $capacity['available'] - $pendingBeds;

        $baseline = [
            'staffed_beds' => $capacity['staffed'],
            'occupied_beds' => $capacity['occupied'],
            'available_beds' => $capacity['available'],
            'blocked_beds' => $capacity['blocked'],
            'pending_bed_requests' => $pendingBeds,
            'current_net_beds' => $currentNetBeds,
            'ed_boarders' => $edBoarding,
            'transport_at_risk' => $transportAtRisk,
            'active_evs_requests' => $activeEvs,
            'evs_at_risk' => $evsAtRisk,
            'dirty_or_blocked_beds' => $dirtyBeds,
            'weighted_discharges' => $weightedDischarges,
            'pacu_holds' => $pacuHolds,
            'upcoming_or_cases_4h' => $upcomingOrCases,
        ];
        $baseline['risk_score'] = $this->riskScore($baseline);

        return $baseline;
    }

    /** @return array<int,array<string,mixed>> */
    private function scenarioDefinitions(array $baseline): array
    {
        $evsRelease = min(4, max(1, $baseline['dirty_or_blocked_beds']));
        $dischargePull = min(3, max(1, (int) round($baseline['weighted_discharges'])));
        $transportReduction = min($baseline['transport_at_risk'], max(1, (int) ceil($baseline['transport_at_risk'] * 0.5)));
        $pacuReduction = min($baseline['pacu_holds'], 2);
        $flexBeds = min(4, max(2, $baseline['blocked_beds'] + 2));

        return [
            $this->scenario('no_action', 'No action baseline', 'Continue current operations without additional intervention.', []),
            $this->scenario('evs_acceleration', 'Accelerate EVS bed readiness', 'Prioritize EVS turns for dirty or blocked beds with immediate bed-placement value.', [
                'net_beds' => $evsRelease,
                'dirty_or_blocked_beds' => -$evsRelease,
                'evs_at_risk' => -min($baseline['evs_at_risk'], $evsRelease),
            ], ['EVS dispatch focuses on beds most likely to unlock admitted patients.']),
            $this->scenario('pull_forward_discharges', 'Pull forward likely discharges', 'Convert weighted discharge opportunity into earlier physical departure.', [
                'net_beds' => $dischargePull,
                'ed_boarders' => -min($baseline['ed_boarders'], $dischargePull),
            ], ['Unit huddles review discharge barriers and route transport before noon.']),
            $this->scenario('transport_reassignment', 'Reassign transport escalation pool', 'Reduce overdue and stat transport risk by rebalancing internal and vendor capacity.', [
                'transport_at_risk' => -$transportReduction,
                'net_beds' => $transportReduction > 0 ? 1 : 0,
            ], ['Transport dispatch moves stat and overdue requests to the escalation pool.']),
            $this->scenario('flex_staffed_capacity', 'Open flex staffed capacity', 'Temporarily flex staffing and bed readiness to return constrained capacity to service.', [
                'net_beds' => $flexBeds,
                'blocked_beds' => -min($baseline['blocked_beds'], $flexBeds),
            ], ['House supervisor confirms staffing and safety constraints before opening beds.']),
            $this->scenario('protect_or_pacu_flow', 'Protect OR and PACU flow', 'Act on PACU holds and upcoming room starts before OR delays cascade into bed demand.', [
                'pacu_holds' => -$pacuReduction,
                'net_beds' => $pacuReduction > 0 ? 1 : 0,
            ], ['OR board runner reviews PACU holds and downstream bed readiness.']),
            $this->scenario('combined_capacity_plan', 'Combined capacity plan', 'Bundle EVS acceleration, discharge pull-forward, transport reassignment, flex capacity, and OR/PACU protection.', [
                'net_beds' => min(10, $evsRelease + $dischargePull + $flexBeds + ($transportReduction > 0 ? 1 : 0) + ($pacuReduction > 0 ? 1 : 0)),
                'dirty_or_blocked_beds' => -$evsRelease,
                'ed_boarders' => -min($baseline['ed_boarders'], $dischargePull + 2),
                'transport_at_risk' => -$transportReduction,
                'evs_at_risk' => -min($baseline['evs_at_risk'], $evsRelease),
                'pacu_holds' => -$pacuReduction,
                'blocked_beds' => -min($baseline['blocked_beds'], $flexBeds),
            ], ['Capacity huddle approves one bundled plan with named owners and due times.']),
        ];
    }

    /** @return array<string,mixed> */
    private function scenario(string $key, string $title, string $assumption, array $effects, array $interventions = []): array
    {
        return compact('key', 'title', 'assumption', 'effects', 'interventions');
    }

    private function persistResults(SimulationScenario $scenario, array $baseline, array $scenarioPayload): void
    {
        $effects = $scenarioPayload['effects'] ?? [];
        $projected = [
            'net_beds' => $baseline['current_net_beds'] + ($effects['net_beds'] ?? 0),
            'ed_boarders' => max(0, $baseline['ed_boarders'] + ($effects['ed_boarders'] ?? 0)),
            'transport_at_risk' => max(0, $baseline['transport_at_risk'] + ($effects['transport_at_risk'] ?? 0)),
            'dirty_or_blocked_beds' => max(0, $baseline['dirty_or_blocked_beds'] + ($effects['dirty_or_blocked_beds'] ?? 0)),
            'evs_at_risk' => max(0, $baseline['evs_at_risk'] + ($effects['evs_at_risk'] ?? 0)),
            'pacu_holds' => max(0, $baseline['pacu_holds'] + ($effects['pacu_holds'] ?? 0)),
            'blocked_beds' => max(0, $baseline['blocked_beds'] + ($effects['blocked_beds'] ?? 0)),
        ];
        $projected['risk_score'] = $this->riskScore(array_merge($baseline, [
            'current_net_beds' => $projected['net_beds'],
            'ed_boarders' => $projected['ed_boarders'],
            'transport_at_risk' => $projected['transport_at_risk'],
            'dirty_or_blocked_beds' => $projected['dirty_or_blocked_beds'],
            'evs_at_risk' => $projected['evs_at_risk'],
            'pacu_holds' => $projected['pacu_holds'],
            'blocked_beds' => $projected['blocked_beds'],
        ]));

        foreach ([
            ['net_beds', $baseline['current_net_beds'], $projected['net_beds'], 'beds', $projected['net_beds'] < 0 ? 'critical' : ($projected['net_beds'] <= 3 ? 'warning' : 'success')],
            ['ed_boarders', $baseline['ed_boarders'], $projected['ed_boarders'], 'patients', $projected['ed_boarders'] > 0 ? 'warning' : 'success'],
            ['transport_at_risk', $baseline['transport_at_risk'], $projected['transport_at_risk'], 'moves', $projected['transport_at_risk'] > 0 ? 'warning' : 'success'],
            ['dirty_or_blocked_beds', $baseline['dirty_or_blocked_beds'], $projected['dirty_or_blocked_beds'], 'beds', $projected['dirty_or_blocked_beds'] > 0 ? 'warning' : 'success'],
            ['evs_at_risk', $baseline['evs_at_risk'], $projected['evs_at_risk'], 'requests', $projected['evs_at_risk'] > 0 ? 'warning' : 'success'],
            ['pacu_holds', $baseline['pacu_holds'], $projected['pacu_holds'], 'holds', $projected['pacu_holds'] > 0 ? 'warning' : 'success'],
            ['risk_score', $baseline['risk_score'], $projected['risk_score'], 'score', $projected['risk_score'] >= 70 ? 'critical' : ($projected['risk_score'] >= 45 ? 'warning' : 'success')],
        ] as [$key, $base, $value, $unit, $status]) {
            SimulationResult::create([
                'simulation_scenario_id' => $scenario->simulation_scenario_id,
                'metric_key' => $key,
                'baseline_value' => $base,
                'projected_value' => $value,
                'delta_value' => $value - $base,
                'unit' => $unit,
                'status' => $status,
                'result_payload' => [
                    'direction' => in_array($key, ['net_beds'], true) ? 'up' : 'down',
                    'effect_source' => $scenarioPayload['key'],
                ],
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function serializeScenario(SimulationScenario $scenario): array
    {
        $scenario->loadMissing('results');
        $results = $scenario->results->keyBy('metric_key');
        $netBeds = (float) ($results['net_beds']?->projected_value ?? 0);
        $riskScore = (float) ($results['risk_score']?->projected_value ?? 100);

        return [
            'scenarioId' => $scenario->simulation_scenario_id,
            'scenarioUuid' => $scenario->simulation_scenario_uuid,
            'key' => $scenario->scenario_key,
            'title' => $scenario->title,
            'assumption' => $scenario->assumption,
            'status' => $scenario->status,
            'netBedForecast' => (int) round($netBeds),
            'riskScore' => (int) round($riskScore),
            'promotedAtIso' => $scenario->promoted_at?->toIso8601String(),
            'promotedRecommendationId' => $scenario->promoted_recommendation_id,
            'interventions' => $scenario->intervention_payload['interventions'] ?? [],
            'effects' => $scenario->intervention_payload['effects'] ?? [],
            'route' => '/analytics/workbench',
            'resultMetrics' => $scenario->results
                ->sortBy('metric_key')
                ->map(fn (SimulationResult $result): array => [
                    'metricKey' => $result->metric_key,
                    'baselineValue' => (float) $result->baseline_value,
                    'projectedValue' => (float) $result->projected_value,
                    'deltaValue' => (float) $result->delta_value,
                    'unit' => $result->unit,
                    'status' => $result->status,
                ])
                ->values()
                ->all(),
            'actionPlanDraft' => [
                'owner' => $scenario->scenario_key === 'protect_or_pacu_flow' ? 'OR board runner' : 'Operations command team',
                'approvalRequired' => $scenario->scenario_key !== 'no_action',
                'route' => '/analytics/workbench',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function capacityBaseline(): array
    {
        if (! Schema::hasTable('prod.census_snapshots')) {
            return ['staffed' => 0, 'occupied' => 0, 'available' => 0, 'blocked' => 0];
        }

        $capacity = DB::selectOne(<<<'SQL'
            WITH latest AS (
                SELECT DISTINCT ON (unit_id)
                    unit_id, staffed_beds, occupied, available, blocked, captured_at
                FROM prod.census_snapshots
                ORDER BY unit_id, captured_at DESC
            )
            SELECT
                COALESCE(SUM(staffed_beds), 0) AS staffed,
                COALESCE(SUM(occupied), 0) AS occupied,
                COALESCE(SUM(available), 0) AS available,
                COALESCE(SUM(blocked), 0) AS blocked
            FROM latest
        SQL);

        return [
            'staffed' => (int) ($capacity->staffed ?? 0),
            'occupied' => (int) ($capacity->occupied ?? 0),
            'available' => (int) ($capacity->available ?? 0),
            'blocked' => (int) ($capacity->blocked ?? 0),
        ];
    }

    private function countRows(string $table, callable $callback): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        $callback($query);

        return (int) $query->count();
    }

    private function weightedDischarges(): float
    {
        if (! Schema::hasTable('prod.rtdc_predictions')) {
            return 0;
        }

        return (float) DB::table('prod.rtdc_predictions')
            ->whereDate('service_date', now()->toDateString())
            ->where('is_deleted', false)
            ->sum('discharges_weighted');
    }

    private function pacuHolds(): int
    {
        if (! Schema::hasTable('prod.or_logs') || ! Schema::hasTable('prod.or_cases')) {
            return 0;
        }

        return (int) DB::table('prod.or_logs as logs')
            ->join('prod.or_cases as cases', 'cases.case_id', '=', 'logs.case_id')
            ->where('cases.is_deleted', false)
            ->where('logs.is_deleted', false)
            ->whereNotNull('logs.pacu_in_time')
            ->whereNull('logs.pacu_out_time')
            ->where('logs.pacu_in_time', '<=', now()->subMinutes(75))
            ->count();
    }

    private function upcomingOrCases(): int
    {
        if (! Schema::hasTable('prod.or_cases')) {
            return 0;
        }

        return (int) DB::table('prod.or_cases')
            ->where('is_deleted', false)
            ->whereDate('surgery_date', now()->toDateString())
            ->whereBetween('scheduled_start_time', [now(), now()->addHours(4)])
            ->count();
    }

    private function riskScore(array $payload): int
    {
        return min(100, max(0, (int) round(
            max(0, -($payload['current_net_beds'] ?? 0)) * 10
            + min(20, $payload['ed_boarders'] ?? 0) * 2
            + min(20, $payload['transport_at_risk'] ?? 0) * 2
            + min(20, $payload['dirty_or_blocked_beds'] ?? 0) * 2
            + min(20, $payload['evs_at_risk'] ?? 0) * 2
            + min(10, $payload['pacu_holds'] ?? 0) * 3
            + min(10, $payload['blocked_beds'] ?? 0) * 1.5
        )));
    }

    /** @return array<string,mixed> */
    private function scenarioFacts(SimulationScenario $scenario): array
    {
        return $scenario->results
            ->mapWithKeys(fn (SimulationResult $result): array => [
                $result->metric_key => [
                    'baseline' => (float) $result->baseline_value,
                    'projected' => (float) $result->projected_value,
                    'delta' => (float) $result->delta_value,
                    'unit' => $result->unit,
                ],
            ])
            ->all();
    }
}
