<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\Capability;
use App\Http\Controllers\Controller;
use App\Models\Governance\GovernedChangeRequest;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Authorization\RoleCapabilityService;
use App\Services\Eddy\EddyProviderPolicyService;
use App\Services\Governance\AiProviderPolicyService;
use App\Services\Governance\GovernedChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADM-POLICY — /admin/ai-providers. Governs the Zephyrus/Eddy provider policy:
 * model/provider capability, fallback order, cost limits, PHI eligibility,
 * region residency, and surface routing, all through the versioned, dual-
 * controlled AiProviderPolicyService. The dry-run simulator accepts ONLY a
 * surface descriptor — no prompt text and no patient content — and stores
 * nothing beyond a non-content user-audit event.
 */
class AiProviderPolicyController extends Controller
{
    public function __construct(
        private readonly AiProviderPolicyService $aiPolicy,
        private readonly EddyProviderPolicyService $eddyPolicy,
        private readonly GovernedChangeService $governance,
        private readonly RoleCapabilityService $authorization,
        private readonly UserAuditRecorder $audit,
    ) {}

    public function index(Request $request): Response
    {
        $effective = $this->aiPolicy->effectiveVersion();

        return Inertia::render('Admin/AiProviders', [
            'document' => $this->aiPolicy->currentDocument(),
            'catalog' => [
                'surfaces' => EddyProviderPolicyService::SURFACES,
                'modes' => EddyProviderPolicyService::MODES,
                'capabilities' => EddyProviderPolicyService::CAPABILITIES,
                'entitlements' => EddyProviderPolicyService::ENTITLEMENTS,
                'transports' => EddyProviderPolicyService::TRANSPORTS,
                'surfaceRequirements' => $this->eddyPolicy->surfaceRequirements(),
            ],
            'readiness' => $this->eddyPolicy->readiness(),
            'currentVersion' => $effective === null ? null : [
                'versionNumber' => (int) $effective->version_number,
                'changeKind' => (string) $effective->change_kind,
                'policySha256' => (string) $effective->policy_sha256,
                'effectiveAtIso' => $effective->effective_at?->toIso8601String(),
            ],
            'versions' => $this->aiPolicy->history(),
            'drift' => $this->aiPolicy->drift(),
            'guardrails' => [
                'cloudKillSwitchEnabled' => ! (bool) config('eddy.allow_cloud', false),
                'monthlyBudgetUsd' => (float) config('eddy.budget.monthly_usd', 0),
                'budgetCutoffThreshold' => (float) config('eddy.budget.cutoff_threshold', 0),
                'phiDetectionEnabled' => (bool) config('eddy.phi.detection_enabled', true),
                'phiBlockOnDetection' => (bool) config('eddy.phi.block_on_detection', true),
            ],
            'pendingChanges' => $this->pendingChanges($request),
            'canManage' => $this->authorization->allows($request->user(), Capability::ManageAiGovernance),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $this->validatedProposal($request);

        return response()->json($this->aiPolicy->preview(
            $validated['document'] ?? null,
            $validated['rollback_to_version_number'] ?? null,
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedProposal($request, requireReason: true);

        $result = $this->aiPolicy->requestChange(
            $request,
            $validated['document'] ?? null,
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
        $this->assertChangeAction($changeRequestUuid);

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
        $this->assertChangeAction($changeRequestUuid);
        $applied = $this->aiPolicy->applyApproved($request, $changeRequestUuid);

        return response()->json([
            'policyKey' => (string) $applied->policy_key,
            'appliedVersionNumber' => (int) $applied->version_number,
            'changeKind' => (string) $applied->change_kind,
            'rolledBackToVersionId' => $applied->rolled_back_to_version_id,
        ]);
    }

    /**
     * Dry-run route simulation. The request contract is a surface descriptor
     * only: prompt text, message bodies, and patient identifiers are rejected
     * outright, and the audited evidence carries routing metadata only.
     */
    public function simulate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'surface' => ['required', 'string', 'in:'.implode(',', EddyProviderPolicyService::SURFACES)],
            'message' => ['prohibited'],
            'prompt' => ['prohibited'],
            'content' => ['prohibited'],
            'patient' => ['prohibited'],
            'patient_id' => ['prohibited'],
            'context' => ['prohibited'],
        ]);

        $simulation = $this->aiPolicy->simulateRoute((string) $validated['surface']);

        $this->audit->record('administration.ai_provider.route_simulated', 'administration', 'success', [
            'request' => $request,
            'target_type' => 'ai_provider_policy',
            'target_id' => AiProviderPolicyService::POLICY_KEY,
            'metadata' => [
                'surface' => (string) $validated['surface'],
                'provider_mode' => $simulation['provider_mode'] ?? null,
                'selected_profile_id' => $simulation['selected_profile']['profile_id'] ?? null,
                'fallback_used' => (bool) ($simulation['fallback_used'] ?? false),
                'will_call_paid_provider' => (bool) ($simulation['will_call_paid_provider'] ?? false),
            ],
        ]);

        return response()->json($simulation);
    }

    /** @return array<string, mixed> */
    private function validatedProposal(Request $request, bool $requireReason = false): array
    {
        return $request->validate([
            'document' => ['sometimes', 'nullable', 'array:profiles,surfaces'],
            'document.profiles' => ['sometimes', 'array', 'max:20'],
            'document.profiles.*' => ['array'],
            'document.surfaces' => ['sometimes', 'array', 'max:'.count(EddyProviderPolicyService::SURFACES)],
            'document.surfaces.*' => ['array'],
            'rollback_to_version_number' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'change_reason' => [$requireReason ? 'required' : 'sometimes', 'string', 'min:10', 'max:500'],
        ]);
    }

    private function assertChangeAction(string $changeRequestUuid): void
    {
        $change = GovernedChangeRequest::query()->whereKey($changeRequestUuid)->firstOrFail();
        abort_unless($change->action_type->value === 'apply_ai_provider_policy', 404);
    }

    /** @return list<array<string, mixed>> */
    private function pendingChanges(Request $request): array
    {
        return GovernedChangeRequest::query()
            ->with(['author:id,name,username', 'decision', 'executions'])
            ->where('action_type', 'apply_ai_provider_policy')
            ->where('expires_at', '>', now())
            ->orderByDesc('requested_at')
            ->limit(50)
            ->get()
            ->filter(fn (GovernedChangeRequest $change): bool => ! $change->executions->contains('outcome', 'success'))
            ->map(fn (GovernedChangeRequest $change): array => [
                'changeRequestUuid' => (string) $change->getKey(),
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
                'changedSections' => array_values((array) ($change->metadata['changed_fields'] ?? [])),
                'proposalVersionNumber' => isset($change->metadata['policy_version']) ? (int) $change->metadata['policy_version'] : null,
                'rollbackToVersionNumber' => isset($change->metadata['rolled_back_to_version']) ? (int) $change->metadata['rolled_back_to_version'] : null,
            ])
            ->values()
            ->all();
    }
}
