<?php

namespace App\Http\Controllers\Api\CarePathways;

use App\Authorization\Capability;
use App\Http\Controllers\Controller;
use App\Services\Authorization\RoleCapabilityService;
use App\Services\CarePathways\CatalogGovernanceReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CatalogGovernanceController extends Controller
{
    public function __construct(
        private readonly CatalogGovernanceReadService $catalog,
        private readonly RoleCapabilityService $authorization,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate($this->releaseRules());
        $payload = $this->catalog->summary($validated['release_uuid'] ?? null);
        if ($payload === null) {
            return $this->notFound();
        }

        $user = $request->user();
        $payload['data']['authorization'] = [
            'view_catalog' => $this->authorization->allows($user, Capability::ViewCarePathwayCatalog),
            'adopt_source' => $this->authorization->allows($user, Capability::AdoptCarePathwaySource),
            'author_content' => $this->authorization->allows($user, Capability::AuthorCarePathwayContent),
            'review_evidence' => $this->authorization->allows($user, Capability::ReviewCarePathwayEvidence),
            'approve_clinical' => $this->authorization->allows($user, Capability::ApproveCarePathwayClinical),
            'activate_catalog' => $this->authorization->allows($user, Capability::ActivateCarePathwayCatalog),
            'view_encounter_pathway' => $this->authorization->allows($user, Capability::ViewEncounterCarePathway),
            'manage_instances' => $this->authorization->allows($user, Capability::ManageCarePathwayInstances),
        ];

        return response()->json($payload);
    }

    public function pathways(Request $request): JsonResponse
    {
        $validated = $request->validate($this->paginationRules() + $this->releaseRules() + [
            'q' => ['nullable', 'string', 'max:200'],
            'drg' => ['nullable', 'regex:/^[0-9]{1,3}$/'],
            'mdc' => ['nullable', 'string', 'max:120'],
            'service_line' => ['nullable', 'string', 'max:160'],
            'evidence_state' => ['nullable', Rule::in(['verified', 'limitations'])],
            'disposition' => ['nullable', Rule::in(['signoff', 'specialist_review', 'redesign'])],
            'institutional_approval_status' => ['nullable', Rule::in(['not_reviewed', 'in_review', 'approved', 'rejected', 'withdrawn'])],
            'activation_status' => ['nullable', Rule::in(['inactive', 'active', 'withdrawn'])],
        ]);

        return $this->payload($this->catalog->pathways($validated));
    }

    public function version(Request $request, string $versionUuid): JsonResponse
    {
        $validated = $request->validate($this->releaseRules());

        return $this->payload($this->catalog->version($versionUuid, $validated['release_uuid'] ?? null));
    }

    public function claims(Request $request, string $versionUuid): JsonResponse
    {
        $validated = $request->validate($this->paginationRules(100) + $this->releaseRules() + [
            'source_field' => ['nullable', 'string', 'max:160'],
            'claim_type' => ['nullable', 'string', 'max:160'],
        ]);

        return $this->payload($this->catalog->claims($versionUuid, $validated));
    }

    public function sources(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'cited');
        $validated = $request->validate($this->paginationRules() + $this->releaseRules() + [
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['current', 'superseded', 'retracted', 'unknown'])],
            'cited' => ['nullable', 'boolean'],
            'verified_before' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->payload($this->catalog->sources($validated));
    }

    public function source(Request $request, string $sourceUuid): JsonResponse
    {
        $validated = $request->validate($this->releaseRules());

        return $this->payload($this->catalog->source($sourceUuid, $validated['release_uuid'] ?? null));
    }

    public function controls(Request $request): JsonResponse
    {
        $validated = $request->validate($this->paginationRules(100) + $this->releaseRules() + [
            'status' => ['nullable', Rule::in(['passed', 'accepted_discrepancy', 'failed', 'not_applicable'])],
        ]);

        return $this->payload($this->catalog->controls($validated));
    }

    public function reviews(Request $request): JsonResponse
    {
        $validated = $request->validate($this->paginationRules(100) + $this->releaseRules() + [
            'decision' => ['nullable', Rule::in(['approved', 'changes_requested', 'rejected', 'abstained'])],
        ]);

        return $this->payload($this->catalog->reviews($validated));
    }

    public function approvals(Request $request): JsonResponse
    {
        $validated = $request->validate($this->paginationRules(100) + $this->releaseRules() + [
            'decision' => ['nullable', Rule::in(['approved', 'rejected', 'withdrawn'])],
        ]);

        return $this->payload($this->catalog->approvals($validated));
    }

    public function events(Request $request): JsonResponse
    {
        $validated = $request->validate($this->paginationRules(100) + $this->releaseRules() + [
            'event_type' => ['nullable', 'string', 'max:160'],
        ]);

        return $this->payload($this->catalog->events($validated));
    }

    /** @return array<string, array<int, mixed>> */
    private function releaseRules(): array
    {
        return [
            'release_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function paginationRules(int $maximum = 50): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$maximum],
        ];
    }

    private function normalizeBooleanQuery(Request $request, string $key): void
    {
        $value = $request->query($key);
        if (! is_string($value)) {
            return;
        }

        $normalized = strtolower($value);
        if (in_array($normalized, ['true', 'false'], true)) {
            $request->merge([$key => $normalized === 'true']);
        }
    }

    /** @param array<string, mixed>|null $payload */
    private function payload(?array $payload): JsonResponse
    {
        return $payload === null ? $this->notFound() : response()->json($payload);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'not_found',
                'message' => 'The requested care-pathway governance resource was not found.',
            ],
        ], 404);
    }
}
