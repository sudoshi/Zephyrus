<?php

namespace App\Services\Ops;

use App\Models\Ops\Approval;
use App\Models\Ops\OperationalAction;
use App\Models\Ops\OperationsNode;
use App\Models\Ops\Recommendation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OperationsRecommendationService
{
    private const TERMINAL_STATUSES = ['completed', 'rejected', 'overridden', 'expired'];

    public function __construct(
        private readonly OperationsGraphProjector $projector,
        private readonly OperationalActionLifecycleService $lifecycle,
    ) {}

    /** @return array<string,mixed> */
    public function generate(bool $rebuildGraph = true): array
    {
        $snapshot = $rebuildGraph ? $this->projector->rebuild() : null;
        $recommendations = collect([
            $this->edBoardingRecommendation(),
            $this->bedPressureRecommendation(),
            $this->blockedBedsRecommendation(),
            $this->orPacuPressureRecommendation(),
            $this->transportRiskRecommendation(),
            $this->barrierRecommendation(),
            $this->staleSourceRecommendation(),
        ])
            ->filter()
            ->map(fn (array $payload): Recommendation => $this->materializeRecommendation($payload))
            ->map(fn (Recommendation $recommendation): array => $this->serializeRecommendation($recommendation))
            ->sortByDesc('score')
            ->values()
            ->all();

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'snapshot' => $snapshot ? $this->projector->serializeSnapshot($snapshot) : null,
            'recommendations' => $recommendations,
            'summary' => [
                'total' => count($recommendations),
                'critical' => collect($recommendations)->where('riskLevel', 'critical')->count(),
                'high' => collect($recommendations)->where('riskLevel', 'high')->count(),
                'draftActions' => collect($recommendations)->sum(
                    fn (array $recommendation): int => collect($recommendation['actions'])
                        ->where('status', 'draft')
                        ->count()
                ),
                'pendingApprovals' => collect($recommendations)->sum(
                    fn (array $recommendation): int => collect($recommendation['actions'])
                        ->sum(
                            fn (array $action): int => collect($action['approvals'])
                                ->where('status', 'pending')
                                ->count()
                        )
                ),
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function edBoardingRecommendation(): ?array
    {
        if (! Schema::hasTable('prod.ed_visits')) {
            return null;
        }

        $visits = DB::table('prod.ed_visits')
            ->where('disposition', 'admitted')
            ->whereNull('bed_assigned_at')
            ->where('is_deleted', false)
            ->orderBy('admit_decision_at')
            ->limit(8)
            ->get();

        if ($visits->isEmpty()) {
            return null;
        }

        $oldestDecision = $visits
            ->pluck('admit_decision_at')
            ->filter()
            ->sort()
            ->first();
        $maxWaitMinutes = $oldestDecision ? now()->diffInMinutes($oldestDecision) : null;
        $risk = $visits->count() >= 6 || ($maxWaitMinutes !== null && $maxWaitMinutes >= 240) ? 'critical' : 'high';

        return [
            'type' => 'ed_boarding',
            'scopeType' => 'hospital',
            'scopeKey' => 'ed',
            'title' => "{$visits->count()} admitted ED patients awaiting bed assignment",
            'rationale' => 'Admitted ED patients without bed assignment increase boarding risk and delay downstream inpatient flow.',
            'confidence' => 0.91,
            'riskLevel' => $risk,
            'expectedImpact' => [
                'metric' => 'ed_boarding',
                'direction' => 'down',
                'estimated_delay_minutes_at_risk' => $maxWaitMinutes,
            ],
            'evidence' => $this->evidence(
                facts: [
                    'boarder_count' => $visits->count(),
                    'max_wait_minutes' => $maxWaitMinutes,
                ],
                nodes: $this->nodesFor('ed_visit', $visits->pluck('ed_visit_id')),
                sourceTables: ['prod.ed_visits', 'prod.bed_requests', 'ops.nodes', 'ops.edges'],
                graphPath: 'ed_visit -> admits_to_unit -> unit',
            ),
            'action' => [
                'type' => 'create_capacity_huddle_item',
                'payload' => [
                    'owner' => 'Capacity huddle',
                    'route' => '/dashboard/emergency',
                    'instruction' => 'Review admitted ED patients without bed assignment and pull forward placement decisions.',
                ],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function bedPressureRecommendation(): ?array
    {
        if (! Schema::hasTable('prod.census_snapshots') || ! Schema::hasTable('prod.bed_requests')) {
            return null;
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
        $pending = (int) DB::table('prod.bed_requests')
            ->where('status', 'pending')
            ->where('is_deleted', false)
            ->count();
        $available = (int) ($capacity->available ?? 0);
        $staffed = (int) ($capacity->staffed ?? 0);
        $occupied = (int) ($capacity->occupied ?? 0);
        $occupancyPct = $staffed > 0 ? (int) round($occupied / $staffed * 100) : 0;
        $netBeds = $available - $pending;

        if ($pending === 0 && $occupancyPct < 85) {
            return null;
        }

        $requests = DB::table('prod.bed_requests')
            ->where('status', 'pending')
            ->where('is_deleted', false)
            ->orderBy('created_at')
            ->limit(8)
            ->get();
        $risk = $netBeds < 0 || $occupancyPct >= 92 ? 'critical' : 'high';

        return [
            'type' => 'bed_pressure',
            'scopeType' => 'hospital',
            'scopeKey' => 'capacity',
            'title' => "{$pending} pending bed requests against {$available} available staffed beds",
            'rationale' => 'Pending placement demand should be reconciled against current staffed capacity before the next bed meeting.',
            'confidence' => 0.88,
            'riskLevel' => $risk,
            'expectedImpact' => [
                'metric' => 'net_beds',
                'direction' => 'up',
                'net_beds' => $netBeds,
                'occupancy_pct' => $occupancyPct,
            ],
            'evidence' => $this->evidence(
                facts: [
                    'pending_bed_requests' => $pending,
                    'available_staffed_beds' => $available,
                    'net_beds' => $netBeds,
                    'occupancy_pct' => $occupancyPct,
                ],
                nodes: [
                    ...$this->nodesFor('bed_request', $requests->pluck('bed_request_id')),
                    ...$this->activeUnitNodes(),
                ],
                sourceTables: ['prod.census_snapshots', 'prod.bed_requests', 'ops.nodes', 'ops.edges'],
                graphPath: 'bed_request -> requests_bed_for -> encounter -> assigned_to_unit -> unit',
            ),
            'action' => [
                'type' => 'review_bed_placement_gap',
                'payload' => [
                    'owner' => 'Bed placement',
                    'route' => '/rtdc/bed-placement',
                    'instruction' => 'Run pending bed requests through RTDC placement recommendations and resolve blocked capacity.',
                ],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function blockedBedsRecommendation(): ?array
    {
        if (! Schema::hasTable('prod.beds')) {
            return null;
        }

        $beds = DB::table('prod.beds')
            ->whereIn('status', ['blocked', 'dirty'])
            ->where('is_deleted', false)
            ->orderByRaw("CASE status WHEN 'dirty' THEN 0 ELSE 1 END")
            ->orderBy('bed_id')
            ->limit(12)
            ->get();

        $latestBlocked = 0;
        if (Schema::hasTable('prod.census_snapshots')) {
            $latestBlocked = (int) (DB::selectOne(<<<'SQL'
                WITH latest AS (
                    SELECT DISTINCT ON (unit_id)
                        unit_id, blocked, captured_at
                    FROM prod.census_snapshots
                    ORDER BY unit_id, captured_at DESC
                )
                SELECT COALESCE(SUM(blocked), 0) AS blocked
                FROM latest
            SQL)->blocked ?? 0);
        }

        if ($beds->isEmpty() && $latestBlocked === 0) {
            return null;
        }

        $activeEvs = 0;
        if (Schema::hasTable('prod.evs_requests')) {
            $activeEvs = (int) DB::table('prod.evs_requests')
                ->whereNotIn('status', ['completed', 'canceled', 'failed'])
                ->where('is_deleted', false)
                ->count();
        }

        $dirtyCount = $beds->where('status', 'dirty')->count();
        $blockedCount = max($beds->where('status', 'blocked')->count(), $latestBlocked);
        $totalAtRisk = $dirtyCount + $blockedCount;
        $risk = $totalAtRisk >= 6 ? 'critical' : 'high';

        return [
            'type' => 'blocked_beds',
            'scopeType' => 'hospital',
            'scopeKey' => 'bed_readiness',
            'title' => "{$totalAtRisk} blocked or dirty beds need readiness action",
            'rationale' => 'Blocked and dirty beds reduce usable staffed capacity and can turn pending bed demand into ED boarding.',
            'confidence' => 0.87,
            'riskLevel' => $risk,
            'expectedImpact' => [
                'metric' => 'available_beds',
                'direction' => 'up',
                'blocked_beds' => $blockedCount,
                'dirty_beds' => $dirtyCount,
                'active_evs_requests' => $activeEvs,
            ],
            'evidence' => $this->evidence(
                facts: [
                    'blocked_bed_records' => $beds->where('status', 'blocked')->count(),
                    'dirty_bed_records' => $dirtyCount,
                    'latest_blocked_census' => $latestBlocked,
                    'active_evs_requests' => $activeEvs,
                ],
                nodes: [
                    ...$this->nodesFor('bed', $beds->pluck('bed_id')),
                    ...$this->activeUnitNodes(),
                ],
                sourceTables: ['prod.beds', 'prod.census_snapshots', 'prod.evs_requests', 'ops.nodes', 'ops.edges'],
                graphPath: 'bed -> contained_by_unit and evs_request -> cleans_bed',
            ),
            'action' => [
                'type' => 'request_evs_bed_readiness',
                'payload' => [
                    'owner' => 'EVS dispatch',
                    'route' => '/evs/requests',
                    'instruction' => 'Prioritize dirty and blocked beds for EVS readiness review and release capacity back to bed placement.',
                ],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function orPacuPressureRecommendation(): ?array
    {
        if (! Schema::hasTable('prod.or_cases') || ! Schema::hasTable('prod.or_logs')) {
            return null;
        }

        $pacuHolds = DB::table('prod.or_logs as logs')
            ->join('prod.or_cases as cases', 'cases.case_id', '=', 'logs.case_id')
            ->where('cases.is_deleted', false)
            ->where('logs.is_deleted', false)
            ->whereNotNull('logs.pacu_in_time')
            ->whereNull('logs.pacu_out_time')
            ->where('logs.pacu_in_time', '<=', now()->subMinutes(75))
            ->orderBy('logs.pacu_in_time')
            ->limit(12)
            ->get(['cases.case_id', 'cases.room_id', 'cases.surgery_date', 'logs.pacu_in_time']);

        $upcomingCases = DB::table('prod.or_cases')
            ->where('is_deleted', false)
            ->whereDate('surgery_date', now()->toDateString())
            ->whereBetween('scheduled_start_time', [now(), now()->addHours(4)])
            ->orderBy('scheduled_start_time')
            ->limit(12)
            ->get(['case_id', 'room_id', 'scheduled_start_time']);

        if ($pacuHolds->isEmpty() && $upcomingCases->count() < 4) {
            return null;
        }

        $oldestHold = $pacuHolds->pluck('pacu_in_time')->filter()->sort()->first();
        $maxHoldMinutes = $oldestHold ? now()->diffInMinutes($oldestHold) : null;
        $risk = $pacuHolds->count() >= 2 || $upcomingCases->count() >= 6 || ($maxHoldMinutes !== null && $maxHoldMinutes >= 180)
            ? 'high'
            : 'medium';

        $caseIds = $pacuHolds->pluck('case_id')
            ->merge($upcomingCases->pluck('case_id'))
            ->unique()
            ->values();

        return [
            'type' => 'or_pacu_pressure',
            'scopeType' => 'perioperative',
            'scopeKey' => 'or_pacu',
            'title' => "{$pacuHolds->count()} PACU holds with {$upcomingCases->count()} upcoming OR cases",
            'rationale' => 'PACU holds and near-term OR demand can cascade into OR delays, turnover pressure, and downstream bed demand.',
            'confidence' => 0.85,
            'riskLevel' => $risk,
            'expectedImpact' => [
                'metric' => 'or_flow_reliability',
                'direction' => 'up',
                'pacu_holds' => $pacuHolds->count(),
                'upcoming_cases_4h' => $upcomingCases->count(),
                'max_hold_minutes' => $maxHoldMinutes,
            ],
            'evidence' => $this->evidence(
                facts: [
                    'pacu_holds' => $pacuHolds->count(),
                    'upcoming_cases_4h' => $upcomingCases->count(),
                    'max_hold_minutes' => $maxHoldMinutes,
                ],
                nodes: $this->nodesFor('or_case', $caseIds),
                sourceTables: ['prod.or_logs', 'prod.or_cases', 'ops.nodes', 'ops.edges'],
                graphPath: 'or_case -> scheduled_in_room -> room with PACU timestamp evidence',
            ),
            'action' => [
                'type' => 'protect_or_pacu_flow',
                'payload' => [
                    'owner' => 'OR board runner',
                    'route' => '/operations/room-status',
                    'instruction' => 'Review PACU holds, downstream bed readiness, and next four hours of OR starts before the next room turnover decision.',
                ],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function transportRiskRecommendation(): ?array
    {
        if (! Schema::hasTable('prod.transport_requests')) {
            return null;
        }

        $requests = DB::table('prod.transport_requests')
            ->whereIn('status', ['requested', 'assigned', 'in_progress'])
            ->where('is_deleted', false)
            ->where(function ($query): void {
                $query->where('priority', 'stat')
                    ->orWhere('needed_at', '<', now());
            })
            ->orderBy('needed_at')
            ->limit(8)
            ->get();

        if ($requests->isEmpty()) {
            return null;
        }

        $overdue = $requests
            ->filter(fn (object $request): bool => $request->needed_at !== null && now()->greaterThan(Carbon::parse($request->needed_at)))
            ->count();
        $risk = $overdue > 0 || $requests->count() >= 4 ? 'high' : 'medium';

        return [
            'type' => 'transport_sla_risk',
            'scopeType' => 'hospital',
            'scopeKey' => 'transport',
            'title' => "{$requests->count()} transport requests at SLA risk",
            'rationale' => 'At-risk transport requests can delay bed turnover, ED decanting, and downstream care progression.',
            'confidence' => 0.86,
            'riskLevel' => $risk,
            'expectedImpact' => [
                'metric' => 'transport_at_risk',
                'direction' => 'down',
                'overdue_requests' => $overdue,
            ],
            'evidence' => $this->evidence(
                facts: [
                    'at_risk_transport_requests' => $requests->count(),
                    'overdue_transport_requests' => $overdue,
                ],
                nodes: $this->nodesFor('transport_request', $requests->pluck('transport_request_id')),
                sourceTables: ['prod.transport_requests', 'ops.nodes', 'ops.edges'],
                graphPath: 'transport_request -> moves_patient_for -> encounter',
            ),
            'action' => [
                'type' => 'escalate_transport_dispatch',
                'payload' => [
                    'owner' => 'Transport dispatch',
                    'route' => '/transport/dispatch',
                    'instruction' => 'Escalate stat and overdue transport requests and rebalance dispatch assignment.',
                ],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function barrierRecommendation(): ?array
    {
        if (! Schema::hasTable('prod.barriers')) {
            return null;
        }

        $barriers = DB::table('prod.barriers')
            ->where('status', 'open')
            ->where('is_deleted', false)
            ->orderBy('opened_at')
            ->limit(8)
            ->get();

        if ($barriers->isEmpty()) {
            return null;
        }

        $unowned = $barriers
            ->filter(fn (object $barrier): bool => blank($barrier->owner ?? null))
            ->count();
        $risk = $unowned > 0 || $barriers->count() >= 6 ? 'high' : 'medium';

        return [
            'type' => 'flow_barrier',
            'scopeType' => 'hospital',
            'scopeKey' => 'barriers',
            'title' => "{$barriers->count()} open patient-flow barriers need owner review",
            'rationale' => 'Open barriers without timely owner action can prevent discharges, bed placement, and care progression.',
            'confidence' => 0.84,
            'riskLevel' => $risk,
            'expectedImpact' => [
                'metric' => 'open_barriers',
                'direction' => 'down',
                'unowned_barriers' => $unowned,
            ],
            'evidence' => $this->evidence(
                facts: [
                    'open_barriers' => $barriers->count(),
                    'unowned_barriers' => $unowned,
                ],
                nodes: $this->nodesFor('barrier', $barriers->pluck('barrier_id')),
                sourceTables: ['prod.barriers', 'ops.nodes', 'ops.edges'],
                graphPath: 'barrier -> blocks_encounter -> encounter and barrier -> impacts_unit -> unit',
            ),
            'action' => [
                'type' => 'assign_barrier_owner',
                'payload' => [
                    'owner' => 'Unit huddles',
                    'route' => '/rtdc/unit-huddle',
                    'instruction' => 'Assign owners and due times for open patient-flow barriers.',
                ],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function staleSourceRecommendation(): ?array
    {
        if (! Schema::hasTable('ops.source_freshness')) {
            return null;
        }

        $sources = DB::table('ops.source_freshness')
            ->whereIn('status', ['warning', 'critical'])
            ->orderByRaw("CASE status WHEN 'critical' THEN 0 ELSE 1 END")
            ->orderBy('checked_at')
            ->limit(8)
            ->get();

        if ($sources->isEmpty()) {
            return null;
        }

        $critical = $sources->where('status', 'critical')->count();

        return [
            'type' => 'stale_source_feed',
            'scopeType' => 'data-quality',
            'scopeKey' => 'source_freshness',
            'title' => "{$sources->count()} source feeds need trust review",
            'rationale' => 'Source freshness degradation should qualify dependent metrics and recommendations before operational use.',
            'confidence' => 0.82,
            'riskLevel' => $critical > 0 ? 'high' : 'medium',
            'expectedImpact' => [
                'metric' => 'data_trust',
                'direction' => 'up',
                'critical_sources' => $critical,
            ],
            'evidence' => $this->evidence(
                facts: [
                    'stale_source_count' => $sources->count(),
                    'critical_source_count' => $critical,
                    'source_keys' => $sources->pluck('source_key')->values()->all(),
                ],
                nodes: [],
                sourceTables: ['ops.source_freshness', 'ops.metric_lineage', 'ops.metric_definitions'],
                graphPath: 'source_freshness -> metric_lineage -> dependent metrics',
            ),
            'action' => [
                'type' => 'review_source_feed_health',
                'payload' => [
                    'owner' => 'Analytics governance',
                    'route' => '/analytics/data-quality',
                    'instruction' => 'Review stale source feeds and qualify dependent metrics before operational huddle use.',
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function materializeRecommendation(array $payload): Recommendation
    {
        $recommendation = Recommendation::query()
            ->where('recommendation_type', $payload['type'])
            ->where('scope_type', $payload['scopeType'])
            ->where('scope_key', $payload['scopeKey'])
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->first()
            ?? Recommendation::query()
                ->where('recommendation_type', $payload['type'])
                ->where('scope_type', $payload['scopeType'])
                ->where('scope_key', $payload['scopeKey'])
                ->whereIn('status', self::TERMINAL_STATUSES)
                ->where('updated_at', '>=', now()->subHours(8))
                ->latest('updated_at')
                ->first() ?? new Recommendation([
                    'recommendation_type' => $payload['type'],
                    'scope_type' => $payload['scopeType'],
                    'scope_key' => $payload['scopeKey'],
                    'status' => 'draft',
                ]);

        if (! $recommendation->exists) {
            $recommendation->recommendation_uuid = (string) Str::uuid();
        }

        $recommendation->fill([
            'title' => $payload['title'],
            'rationale' => $payload['rationale'],
            'confidence' => $payload['confidence'],
            'risk_level' => $payload['riskLevel'],
            'expected_impact' => $payload['expectedImpact'],
            'evidence' => $payload['evidence'],
            'created_by_source' => 'rules:operations_recommendation_service',
        ])->save();

        if (! in_array($recommendation->status, self::TERMINAL_STATUSES, true)) {
            $this->materializeAction($recommendation, $payload['action']);
        }

        return $recommendation->refresh()->load('actions.approvals');
    }

    /** @param array<string,mixed> $actionPayload */
    private function materializeAction(Recommendation $recommendation, array $actionPayload): void
    {
        $action = OperationalAction::query()
            ->where('recommendation_id', $recommendation->recommendation_id)
            ->where('action_type', $actionPayload['type'])
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->first() ?? new OperationalAction([
                'recommendation_id' => $recommendation->recommendation_id,
                'action_type' => $actionPayload['type'],
                'status' => 'draft',
            ]);

        if (! $action->exists) {
            $action->action_uuid = (string) Str::uuid();
        }

        $action->fill([
            'payload' => $actionPayload['payload'],
        ])->save();

        if (blank($action->owner_name)) {
            $action->owner_name = $actionPayload['payload']['owner'] ?? null;
        }

        if ($action->expires_at === null) {
            $action->expires_at = now()->addHours(8);
        }

        $action->save();

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
            'reason' => 'Human approval required before executing graph-backed operational action.',
        ])->save();
    }

    /** @param array<string,mixed> $facts */
    private function evidence(array $facts, array $nodes, array $sourceTables, string $graphPath): array
    {
        return [
            'facts' => $facts,
            'graph_path' => $graphPath,
            'graph_nodes' => array_values($nodes),
            'source_tables' => $sourceTables,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @param Collection<int,mixed> $ids */
    private function nodesFor(string $nodeType, Collection $ids): array
    {
        $keys = $ids
            ->filter()
            ->map(fn (mixed $id): string => "{$nodeType}:{$id}")
            ->values()
            ->all();

        if ($keys === []) {
            return [];
        }

        return OperationsNode::query()
            ->whereIn('canonical_key', $keys)
            ->where('is_active', true)
            ->orderBy('canonical_key')
            ->get()
            ->map(fn (OperationsNode $node): array => $this->nodeEvidence($node))
            ->all();
    }

    private function activeUnitNodes(): array
    {
        return OperationsNode::query()
            ->where('node_type', 'unit')
            ->where('is_active', true)
            ->orderBy('canonical_key')
            ->limit(8)
            ->get()
            ->map(fn (OperationsNode $node): array => $this->nodeEvidence($node))
            ->all();
    }

    private function nodeEvidence(OperationsNode $node): array
    {
        return [
            'graphNodeId' => $node->graph_node_id,
            'canonicalKey' => $node->canonical_key,
            'nodeType' => $node->node_type,
            'displayName' => $node->display_name,
            'status' => $node->status,
            'source' => "{$node->source_schema}.{$node->source_table}",
            'sourcePk' => $node->source_pk,
        ];
    }

    private function serializeRecommendation(Recommendation $recommendation): array
    {
        $confidence = $recommendation->confidence === null ? null : (float) $recommendation->confidence;

        return [
            'recommendationId' => $recommendation->recommendation_id,
            'recommendationUuid' => $recommendation->recommendation_uuid,
            'type' => $recommendation->recommendation_type,
            'scopeType' => $recommendation->scope_type,
            'scopeKey' => $recommendation->scope_key,
            'title' => $recommendation->title,
            'rationale' => $recommendation->rationale,
            'confidence' => $confidence,
            'score' => $this->score($recommendation->risk_level, $confidence),
            'riskLevel' => $recommendation->risk_level,
            'status' => $recommendation->status,
            'expectedImpact' => $recommendation->expected_impact ?? [],
            'evidence' => $recommendation->evidence ?? [],
            'createdBySource' => $recommendation->created_by_source,
            'actions' => $recommendation->actions
                ->map(fn (OperationalAction $action): array => $this->lifecycle->serializeAction($action))
                ->values()
                ->all(),
            'createdAtIso' => $recommendation->created_at?->toIso8601String(),
            'updatedAtIso' => $recommendation->updated_at?->toIso8601String(),
        ];
    }

    private function score(string $riskLevel, ?float $confidence): int
    {
        $base = match ($riskLevel) {
            'critical' => 90,
            'high' => 75,
            'medium' => 60,
            default => 40,
        };

        return min(100, $base + (int) round(($confidence ?? 0) * 10));
    }
}
