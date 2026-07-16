<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\Capability;
use App\Http\Controllers\Controller;
use App\Models\Governance\GovernedChangeRequest;
use App\Models\Ops\MetricDefinition;
use App\Services\Authorization\RoleCapabilityService;
use App\Services\Governance\CockpitThresholdPolicyService;
use App\Services\Governance\GovernedChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADM-POLICY — the governed cockpit threshold policy surface. Every effective
 * threshold is a versioned policy (owner, scope, unit, direction, validation
 * constraints, effective date, reason); changes flow request → independent
 * decision → execution with step-up, and rollback appends a new version
 * referencing a prior one. Read access never mutates anything.
 */
class CockpitPolicyController extends Controller
{
    public function __construct(
        private readonly CockpitThresholdPolicyService $thresholds,
        private readonly GovernedChangeService $governance,
        private readonly RoleCapabilityService $authorization,
    ) {}

    public function index(Request $request): Response
    {
        $selectedMetric = $request->query('metric');
        $selectedMetric = is_string($selectedMetric) && preg_match('/^[A-Za-z0-9_.:-]{1,160}$/', $selectedMetric) === 1
            ? $selectedMetric
            : null;

        $definitions = MetricDefinition::query()->orderBy('metric_key')->get();
        $duplicates = $this->thresholds->duplicateKeyReport();
        $flaggedKeys = collect($duplicates)
            ->flatMap(fn (array $group): array => array_column($group['members'], 'metricKey'))
            ->unique()
            ->values();

        return Inertia::render('Admin/CockpitThresholds', [
            'definitions' => $definitions->map(function (MetricDefinition $definition) use ($flaggedKeys): array {
                $effective = $this->thresholds->effectiveVersion($definition);

                return [
                    'metricKey' => (string) $definition->metric_key,
                    'label' => (string) $definition->label,
                    'domain' => (string) $definition->domain,
                    'scope' => $definition->facility_key ?? 'house',
                    'status' => (bool) ($definition->is_active ?? true) ? 'active' : 'inactive',
                    'policy' => $this->thresholds->policyFromDefinition($definition),
                    'flagged' => $flaggedKeys->contains((string) $definition->metric_key),
                    'currentVersion' => $effective === null ? null : [
                        'versionNumber' => (int) $effective->version_number,
                        'changeKind' => (string) $effective->change_kind,
                        'effectiveAtIso' => $effective->effective_at?->toIso8601String(),
                    ],
                ];
            })->values()->all(),
            'duplicates' => $duplicates,
            'filters' => [
                'domains' => $definitions->pluck('domain')->unique()->sort()->values()->all(),
                'scopes' => $definitions->map(fn (MetricDefinition $definition): string => $definition->facility_key ?? 'house')->unique()->sort()->values()->all(),
                'statuses' => ['active', 'inactive'],
            ],
            'selectedMetric' => $selectedMetric,
            'selectedMetricHistory' => $selectedMetric !== null
                && MetricDefinition::query()->where('metric_key', $selectedMetric)->exists()
                ? $this->thresholds->history($selectedMetric)
                : [],
            'pendingChanges' => $this->pendingChanges($request),
            'canManage' => $this->authorization->allows($request->user(), Capability::ManageCockpitPolicy),
        ]);
    }

    public function preview(Request $request, string $metricKey): JsonResponse
    {
        $validated = $this->validatedProposal($request);

        return response()->json($this->thresholds->preview(
            $metricKey,
            $validated['updates'] ?? [],
            $validated['rollback_to_version_number'] ?? null,
        ));
    }

    public function store(Request $request, string $metricKey): JsonResponse
    {
        $validated = $this->validatedProposal($request, requireReason: true);

        $result = $this->thresholds->requestChange(
            $request,
            $metricKey,
            $validated['updates'] ?? [],
            (string) $validated['change_reason'],
            $validated['rollback_to_version_number'] ?? null,
        );

        return response()->json([
            'changeRequestUuid' => (string) $result['change']->getKey(),
            'proposalVersionNumber' => (int) $result['version']->version_number,
            'policySha256' => (string) $result['version']->policy_sha256,
            'expiresAtIso' => $result['change']->expires_at->toIso8601String(),
        ], 201);
    }

    public function decide(Request $request, string $changeRequestUuid): JsonResponse
    {
        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $this->assertChangeAction($changeRequestUuid, 'apply_cockpit_threshold_policy');

        $decision = $this->governance->decide(
            $request,
            $changeRequestUuid,
            (bool) $validated['approve'],
            (string) $validated['reason'],
        );

        return response()->json([
            'changeRequestUuid' => $changeRequestUuid,
            'decision' => $decision->decision,
            'decidedAtIso' => $decision->decided_at->toIso8601String(),
        ]);
    }

    public function apply(Request $request, string $changeRequestUuid): JsonResponse
    {
        $this->assertChangeAction($changeRequestUuid, 'apply_cockpit_threshold_policy');
        $applied = $this->thresholds->applyApproved($request, $changeRequestUuid);

        return response()->json([
            'metricKey' => (string) $applied->metric_key,
            'appliedVersionNumber' => (int) $applied->version_number,
            'changeKind' => (string) $applied->change_kind,
            'rolledBackToVersionId' => $applied->rolled_back_to_version_id,
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedProposal(Request $request, bool $requireReason = false): array
    {
        return $request->validate([
            'updates' => ['sometimes', 'array:owner,unit,direction,target,ok_edge,warn_edge,crit_edge,refresh_secs,alert_template,is_active'],
            'updates.owner' => ['sometimes', 'nullable', 'string', 'max:160'],
            'updates.unit' => ['sometimes', 'nullable', 'string', 'max:40'],
            'updates.direction' => ['sometimes', 'in:up,down,neutral'],
            'updates.target' => ['sometimes', 'nullable', 'numeric'],
            'updates.ok_edge' => ['sometimes', 'nullable', 'numeric'],
            'updates.warn_edge' => ['sometimes', 'nullable', 'numeric'],
            'updates.crit_edge' => ['sometimes', 'nullable', 'numeric'],
            'updates.refresh_secs' => ['sometimes', 'integer', 'min:30', 'max:86400'],
            'updates.alert_template' => ['sometimes', 'nullable', 'string', 'max:500'],
            'updates.is_active' => ['sometimes', 'boolean'],
            'rollback_to_version_number' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'change_reason' => [$requireReason ? 'required' : 'sometimes', 'string', 'min:10', 'max:500'],
        ]);
    }

    private function assertChangeAction(string $changeRequestUuid, string $expectedAction): void
    {
        $change = GovernedChangeRequest::query()->whereKey($changeRequestUuid)->firstOrFail();
        abort_unless($change->action_type->value === $expectedAction, 404);
    }

    /** @return list<array<string, mixed>> */
    private function pendingChanges(Request $request): array
    {
        return GovernedChangeRequest::query()
            ->with(['author:id,name,username', 'decision', 'executions'])
            ->where('action_type', 'apply_cockpit_threshold_policy')
            ->where('expires_at', '>', now())
            ->orderByDesc('requested_at')
            ->limit(50)
            ->get()
            ->filter(fn (GovernedChangeRequest $change): bool => ! $change->executions->contains('outcome', 'success'))
            ->map(fn (GovernedChangeRequest $change): array => [
                'changeRequestUuid' => (string) $change->getKey(),
                'metricKey' => (string) $change->subject_id,
                'reason' => (string) $change->reason,
                'requestedAtIso' => $change->requested_at->toIso8601String(),
                'expiresAtIso' => $change->expires_at->toIso8601String(),
                'author' => $change->author?->only(['id', 'name', 'username']),
                'authoredByCurrentUser' => (int) $change->author_user_id === (int) $request->user()?->getKey(),
                'decision' => $change->decision === null ? null : [
                    'decision' => (string) $change->decision->decision,
                    'reason' => (string) $change->decision->reason,
                    'decidedAtIso' => $change->decision->decided_at->toIso8601String(),
                ],
                'changedFields' => array_values((array) ($change->metadata['changed_fields'] ?? [])),
                'proposalVersionNumber' => isset($change->metadata['policy_version']) ? (int) $change->metadata['policy_version'] : null,
                'rollbackToVersionNumber' => isset($change->metadata['rolled_back_to_version']) ? (int) $change->metadata['rolled_back_to_version'] : null,
            ])
            ->values()
            ->all();
    }
}
