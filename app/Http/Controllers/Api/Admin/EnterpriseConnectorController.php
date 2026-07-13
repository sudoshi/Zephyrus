<?php

namespace App\Http\Controllers\Api\Admin;

use App\Authorization\GovernedAction;
use App\Http\Controllers\Api\Admin\Concerns\ResolvesIntegrationCorrelation;
use App\Http\Controllers\Controller;
use App\Integrations\Healthcare\Services\EnterpriseConnectorControlService;
use App\Services\Authorization\AdminScopeService;
use App\Services\Governance\GovernedChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EnterpriseConnectorController extends Controller
{
    use ResolvesIntegrationCorrelation;

    public function __construct(
        private readonly EnterpriseConnectorControlService $connectors,
        private readonly GovernedChangeService $governance,
        private readonly AdminScopeService $scopes,
    ) {}

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->connectors->summary()]);
    }

    public function discoverFhir(Request $request): JsonResponse
    {
        $validated = $request->validate(['source_id' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->connectors->queueHealthCheck(
            (int) $validated['source_id'],
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        )], 202);
    }

    public function healthCheck(Request $request, int $source): JsonResponse
    {
        return response()->json(['data' => $this->connectors->queueHealthCheck(
            $source,
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        )], 202);
    }

    public function pollFhir(Request $request, int $source): JsonResponse
    {
        $validated = $request->validate([
            'resource_type' => ['required', Rule::in(['Encounter', 'Location'])],
        ]);

        return response()->json(['data' => $this->connectors->queueFhirPoll(
            $source,
            (string) $validated['resource_type'],
            $request->user()?->getAuthIdentifier(),
            $this->correlationId($request),
        )], 202);
    }

    public function previewReplay(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->connectors->previewReplay($this->replayScope($request))]);
    }

    public function queueReplay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'change_request_uuid' => ['required', 'uuid'],
        ]);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 190 || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1) {
            return response()->json(['error' => [
                'code' => 'idempotency_key_required',
                'message' => 'Idempotency-Key must be 1-190 URL-safe characters.',
            ]], 422);
        }

        $rawScope = $this->replayScope($request);
        $preview = $this->connectors->previewReplay($rawScope);
        $scope = $preview['scope'];
        $payloadHash = $this->governance->hashPayload($scope);
        $subjectId = $this->replaySubjectId($payloadHash);
        $result = $this->governance->executeApproved(
            $request,
            (string) $validated['change_request_uuid'],
            GovernedAction::ExecuteDestructiveReplay,
            'integration_replay',
            $subjectId,
            $payloadHash,
            fn (): array => $this->connectors->queueReplay(
                $rawScope,
                $idempotencyKey,
                $request->user()?->getAuthIdentifier(),
                $this->correlationId($request),
            ),
        );

        return response()->json(['data' => $result], $result['created'] ? 202 : 200);
    }

    public function requestReplay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $preview = $this->connectors->previewReplay($this->replayScope($request));
        $scope = $preview['scope'];
        $payloadHash = $this->governance->hashPayload($scope);
        $change = $this->governance->requestChange(
            $request,
            GovernedAction::ExecuteDestructiveReplay,
            'integration_replay',
            $this->replaySubjectId($payloadHash),
            (string) $validated['reason'],
            $payloadHash,
            $this->replayAuthorizationScope($scope),
        );

        return response()->json(['data' => [
            'changeRequestUuid' => $change->getKey(),
            'action' => $change->action_type->value,
            'status' => 'pending_approval',
            'expiresAt' => $change->expires_at?->toIso8601String(),
            'preview' => $preview,
        ]], 201);
    }

    public function createWritebackDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_id' => ['required', 'integer', 'min:1'],
            'vendor' => ['nullable', 'string', 'max:120'],
            'target_system' => ['nullable', 'string', 'max:120'],
            'resource_type' => ['required', Rule::in(['Task', 'ServiceRequest', 'TransportRequest', 'EvsRequest', 'SecureMessage'])],
            'draft_type' => ['nullable', 'string', 'max:80'],
            'resource_payload' => ['nullable', 'array'],
        ]);

        return response()->json([
            'data' => $this->connectors->createWritebackDraft([
                ...$validated,
                'source_id' => $this->scopes->requireSource(
                    $request,
                    (int) $validated['source_id'],
                )->sourceId,
            ], $request->user()?->id),
        ]);
    }

    /** @return array<string, mixed> */
    private function replayScope(Request $request): array
    {
        return $request->validate([
            'source_id' => ['required', 'integer', 'min:1', Rule::exists(\App\Models\Integration\Source::class, 'source_id')],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'event_types' => ['sometimes', 'array', 'min:1', 'max:5'],
            'event_types.*' => ['string', Rule::in(['EncounterStarted', 'EncounterTransferred', 'EncounterDischarged', 'BedStatusChanged', 'AcuityChanged'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);
    }

    /** @param array<string, mixed> $scope */
    private function replayAuthorizationScope(array $scope): \App\Authorization\AuthorizationScope
    {
        $sourceId = $scope['sourceId'] ?? null;
        abort_unless(is_int($sourceId) && $sourceId > 0, 422);

        return \App\Authorization\AuthorizationScope::facility(
            (int) DB::table('integration.sources')->where('source_id', $sourceId)->value('facility_id'),
        );
    }

    private function replaySubjectId(string $payloadHash): string
    {
        return 'replay:'.substr($payloadHash, 0, 32);
    }
}
