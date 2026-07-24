<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\Capability;
use App\Http\Controllers\Controller;
use App\Models\Governance\GovernedChangeRequest;
use App\Services\Authorization\RoleCapabilityService;
use App\Services\Deployment\EnterpriseRegistryImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ENT-REG — governed enterprise registry import and topology governance.
 *
 * Reads (preview, history, pending changes, readiness) require viewEnterpriseSetup;
 * committing an import is a governed change (request → independent decision →
 * exact-hash execution) with step-up enforced inside GovernedChangeService,
 * throttled here, and audited. The per-source required-topology declaration that
 * the readiness gate consumes is a manageEnterpriseSetup + audited write.
 *
 * These routes live under a non-integration prefix so they never enter the
 * api/admin/integrations admin-scope boundary inventory: enterprise topology is
 * cross-tenant and governed by capability, not per-source admin scope.
 *
 * Plan: docs/plans/ADMIN-INTEROPERABILITY-CONTROL-PLANE-PLAN-2026-07-12.md (ENT-REG)
 */
class EnterpriseRegistryController extends Controller
{
    /** Max import payload records per entity kind (defense against oversized uploads). */
    private const MAX_RECORDS_PER_ENTITY = 5000;

    public function __construct(
        private readonly EnterpriseRegistryImportService $import,
        private readonly RoleCapabilityService $authorization,
    ) {}

    /**
     * Governance surface data for the Enterprise Setup page (import readiness,
     * pending governed imports, recent change history). Never mutates.
     *
     * @return array<string, mixed>
     */
    public function overview(Request $request): array
    {
        return [
            'pendingChanges' => $this->pendingChanges($request),
            'changeHistory' => $this->recentChangeHistory(),
            'canManage' => $this->authorization->allows($request->user(), Capability::ManageEnterpriseSetup),
            'entityTypes' => ['organizations', 'markets', 'facilities', 'service_lines', 'locations'],
        ];
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $this->validatePreview($request);

        return response()->json($this->import->preview(
            $validated['payload'],
            $validated['conflict_resolutions'] ?? [],
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePreview($request, requireReason: true);

        $change = $this->import->requestCommit(
            $request,
            $validated['payload'],
            $validated['conflict_resolutions'] ?? [],
            (string) $validated['change_reason'],
        );

        return response()->json([
            'changeRequestUuid' => (string) $change->getKey(),
            'payloadSha256' => (string) $change->payload_sha256,
            'expiresAtIso' => $change->expires_at->toIso8601String(),
        ], 201);
    }

    public function decide(Request $request, string $changeRequestUuid): JsonResponse
    {
        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $this->assertImportChange($changeRequestUuid);

        $decision = app(\App\Services\Governance\GovernedChangeService::class)->decide(
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
        $validated = $this->validatePreview($request);
        $this->assertImportChange($changeRequestUuid);

        $applied = $this->import->applyApproved(
            $request,
            $changeRequestUuid,
            $validated['payload'],
            $validated['conflict_resolutions'] ?? [],
        );

        return response()->json(['applied' => $applied]);
    }

    /**
     * Declare (or clear) the enterprise service lines and locations a source
     * requires. The readiness gate then blocks activation until every declared
     * item resolves against the imported registry. manageEnterpriseSetup + audited.
     */
    public function declareSourceTopology(Request $request, int $source): JsonResponse
    {
        $validated = $request->validate([
            'required_service_line_codes' => ['sometimes', 'array', 'max:200'],
            'required_service_line_codes.*' => ['string', 'regex:/^[A-Za-z0-9_.:\-]{1,80}$/'],
            'required_location_space_codes' => ['sometimes', 'array', 'max:500'],
            'required_location_space_codes.*' => ['string', 'regex:/^[A-Za-z0-9_.:\-]{1,190}$/'],
        ]);

        abort_unless(DB::table('integration.sources')->where('source_id', $source)->exists(), 404);

        $serviceLines = array_values(array_unique($validated['required_service_line_codes'] ?? []));
        $locations = array_values(array_unique($validated['required_location_space_codes'] ?? []));

        DB::table('integration.source_required_topology')->updateOrInsert(
            ['source_id' => $source],
            [
                'required_service_line_codes' => json_encode($serviceLines, JSON_THROW_ON_ERROR),
                'required_location_space_codes' => json_encode($locations, JSON_THROW_ON_ERROR),
                'declared_by_user_id' => $request->user()?->getKey(),
                'updated_at' => now(),
            ],
        );

        return response()->json([
            'sourceId' => $source,
            'requiredServiceLineCodes' => $serviceLines,
            'requiredLocationSpaceCodes' => $locations,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePreview(Request $request, bool $requireReason = false): array
    {
        return $request->validate([
            'payload' => ['required', 'array'],
            'payload.organizations' => ['sometimes', 'array', 'max:'.self::MAX_RECORDS_PER_ENTITY],
            'payload.markets' => ['sometimes', 'array', 'max:'.self::MAX_RECORDS_PER_ENTITY],
            'payload.facilities' => ['sometimes', 'array', 'max:'.self::MAX_RECORDS_PER_ENTITY],
            'payload.service_lines' => ['sometimes', 'array', 'max:'.self::MAX_RECORDS_PER_ENTITY],
            'payload.locations' => ['sometimes', 'array', 'max:'.self::MAX_RECORDS_PER_ENTITY],
            'payload.spaces' => ['sometimes', 'array', 'max:'.self::MAX_RECORDS_PER_ENTITY],
            'conflict_resolutions' => ['sometimes', 'array', 'max:1000'],
            'conflict_resolutions.*' => ['in:adopt,skip'],
            'change_reason' => [$requireReason ? 'required' : 'sometimes', 'string', 'min:10', 'max:500'],
        ]);
    }

    private function assertImportChange(string $changeRequestUuid): void
    {
        $change = GovernedChangeRequest::query()->whereKey($changeRequestUuid)->firstOrFail();
        abort_unless($change->action_type->value === 'apply_enterprise_registry_import', 404);
    }

    /** @return list<array<string, mixed>> */
    private function pendingChanges(Request $request): array
    {
        return GovernedChangeRequest::query()
            ->with(['author:id,name,username', 'decision', 'executions'])
            ->where('action_type', 'apply_enterprise_registry_import')
            ->where('expires_at', '>', now())
            ->orderByDesc('requested_at')
            ->limit(50)
            ->get()
            ->filter(fn (GovernedChangeRequest $change): bool => ! $change->executions->contains('outcome', 'success'))
            ->map(fn (GovernedChangeRequest $change): array => [
                'changeRequestUuid' => (string) $change->getKey(),
                'reason' => (string) $change->reason,
                'payloadSha256' => (string) $change->payload_sha256,
                'requestedAtIso' => $change->requested_at->toIso8601String(),
                'expiresAtIso' => $change->expires_at->toIso8601String(),
                'author' => $change->author?->only(['id', 'name', 'username']),
                'authoredByCurrentUser' => (int) $change->author_user_id === (int) $request->user()?->getKey(),
                'decision' => $change->decision === null ? null : [
                    'decision' => (string) $change->decision->decision,
                    'reason' => (string) $change->decision->reason,
                    'decidedAtIso' => $change->decision->decided_at->toIso8601String(),
                ],
                'summary' => [
                    'create' => (int) ($change->metadata['create'] ?? 0),
                    'update' => (int) ($change->metadata['update'] ?? 0),
                    'noChange' => (int) ($change->metadata['no_change'] ?? 0),
                    'readinessScore' => (int) ($change->metadata['readiness_score'] ?? 0),
                ],
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function recentChangeHistory(): array
    {
        return DB::table('hosp_org.enterprise_change_history')
            ->orderByDesc('recorded_at')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => [
                'entityType' => (string) $row->entity_type,
                'naturalKey' => (string) $row->entity_natural_key,
                'changeKind' => (string) $row->change_kind,
                'sourceOfTruth' => (string) $row->source_of_truth,
                'changedFields' => array_values((array) json_decode((string) $row->changed_fields, true) ?: []),
                'recordedAtIso' => $row->recorded_at,
                'governedChangeRequestUuid' => $row->governed_change_request_uuid,
            ])
            ->all();
    }
}
