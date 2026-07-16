<?php

namespace App\Http\Controllers\Api\Admin;

use App\Authorization\GovernedAction;
use App\Http\Controllers\Controller;
use App\Models\Governance\GovernedChangeRequest;
use App\Security\ClinicalPayloads\ClinicalPayloadException;
use App\Security\ClinicalPayloads\ClinicalPayloadLifecycleService;
use App\Security\ClinicalPayloads\ClinicalPayloadQuarantineService;
use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use App\Services\Authorization\AdminScopeService;
use App\Services\Governance\GovernanceViolation;
use App\Services\Governance\GovernedChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ClinicalPayloadGovernanceController extends Controller
{
    private const HOLD_REASON_PATTERN = '/^[a-z][a-z0-9._-]{2,119}$/';

    public function __construct(
        private readonly GovernedChangeService $governance,
        private readonly ClinicalPayloadQuarantineService $quarantines,
        private readonly ClinicalPayloadLifecycleService $lifecycle,
        private readonly ClinicalPayloadStore $payloads,
        private readonly AdminScopeService $scopes,
    ) {}

    public function requestQuarantineRelease(Request $request, int $source, int $quarantine): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $record = $this->quarantine($source, $quarantine);
        if ((string) $record->status !== 'open') {
            throw new ClinicalPayloadException('clinical_payload_quarantine_state_invalid');
        }
        $payload = $this->payloadObject($source, (int) $record->payload_object_id);
        $action = GovernedAction::ReleaseQuarantinedPayload;
        $this->assertNoOpenChange($action, 'payload_quarantine', (string) $record->quarantine_uuid);
        $change = $this->governance->requestChange(
            $request,
            $action,
            'payload_quarantine',
            (string) $record->quarantine_uuid,
            (string) $validated['reason'],
            $this->governance->hashPayload($this->quarantineContract($record, $payload, 'release')),
            $this->scopes->requireSource($request, $source)->authorizationScope(),
            ['source_id' => $source, 'quarantine_id' => $quarantine, 'operation' => 'release'],
        );

        return $this->createdChange($change, ['quarantineUuid' => (string) $record->quarantine_uuid]);
    }

    public function executeQuarantineRelease(
        Request $request,
        string $changeRequestUuid,
        int $source,
        int $quarantine,
    ): JsonResponse {
        $record = $this->quarantine($source, $quarantine);
        $payload = $this->payloadObject($source, (int) $record->payload_object_id);
        $result = $this->governance->executeApproved(
            $request,
            $changeRequestUuid,
            GovernedAction::ReleaseQuarantinedPayload,
            'payload_quarantine',
            (string) $record->quarantine_uuid,
            $this->governance->hashPayload($this->quarantineContract($record, $payload, 'release')),
            function (GovernedChangeRequest $approved) use ($request, $record, $source): array {
                $this->quarantines->release(
                    (int) $record->payload_quarantine_id,
                    $source,
                    (string) $approved->getKey(),
                    (int) $request->user()->getAuthIdentifier(),
                );

                return ['quarantineUuid' => (string) $record->quarantine_uuid, 'status' => 'released'];
            },
        );

        return response()->json(['data' => $result]);
    }

    public function requestHold(Request $request, int $source, int $object): JsonResponse
    {
        $validated = $request->validate([
            'operation' => ['required', 'in:apply,release'],
            'hold_reason_code' => ['required', 'string', 'regex:'.self::HOLD_REASON_PATTERN],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $payload = $this->payloadObject($source, $object);
        $operation = (string) $validated['operation'];
        $this->assertHoldState($payload, $operation);
        $action = $operation === 'apply'
            ? GovernedAction::ApplyClinicalPayloadHold
            : GovernedAction::ReleaseClinicalPayloadHold;
        $this->assertNoOpenChange($action, 'clinical_payload', (string) $payload->payload_uuid);
        $reasonCode = (string) $validated['hold_reason_code'];
        $change = $this->governance->requestChange(
            $request,
            $action,
            'clinical_payload',
            (string) $payload->payload_uuid,
            (string) $validated['reason'],
            $this->governance->hashPayload($this->objectContract($payload, $operation, reasonCode: $reasonCode)),
            $this->scopes->requireSource($request, $source)->authorizationScope(),
            [
                'source_id' => $source,
                'object_id' => $object,
                'operation' => $operation,
                'hold_reason_code' => $reasonCode,
            ],
        );

        return $this->createdChange($change, [
            'objectUuid' => (string) $payload->payload_uuid,
            'operation' => $operation,
        ]);
    }

    public function executeHold(
        Request $request,
        string $changeRequestUuid,
        int $source,
        int $object,
    ): JsonResponse {
        $change = GovernedChangeRequest::query()->whereKey($changeRequestUuid)->firstOrFail();
        if (! in_array($change->action_type, [
            GovernedAction::ApplyClinicalPayloadHold,
            GovernedAction::ReleaseClinicalPayloadHold,
        ], true)) {
            throw new GovernanceViolation('approved_payload_mismatch', 'The approved request is not a clinical-payload hold action.');
        }
        $operation = $change->action_type === GovernedAction::ApplyClinicalPayloadHold ? 'apply' : 'release';
        $reasonCode = (string) data_get($change->metadata, 'hold_reason_code', '');
        if (preg_match(self::HOLD_REASON_PATTERN, $reasonCode) !== 1
            || data_get($change->metadata, 'operation') !== $operation) {
            throw new GovernanceViolation('approved_payload_mismatch', 'The approved hold contract metadata is invalid.');
        }
        $payload = $this->payloadObject($source, $object);
        $this->assertHoldState($payload, $operation);
        $result = $this->governance->executeApproved(
            $request,
            $changeRequestUuid,
            $change->action_type,
            'clinical_payload',
            (string) $payload->payload_uuid,
            $this->governance->hashPayload($this->objectContract($payload, $operation, reasonCode: $reasonCode)),
            function (GovernedChangeRequest $approved) use ($request, $payload, $source, $object, $operation, $reasonCode): array {
                if ($operation === 'apply') {
                    $this->payloads->applyLegalHold(
                        $object,
                        $source,
                        $reasonCode,
                        (int) $request->user()->getAuthIdentifier(),
                        (string) $approved->getKey(),
                    );
                } else {
                    $this->payloads->releaseLegalHold(
                        $object,
                        $source,
                        $reasonCode,
                        (int) $request->user()->getAuthIdentifier(),
                        (string) $approved->getKey(),
                    );
                }

                return [
                    'objectUuid' => (string) $payload->payload_uuid,
                    'legalHold' => $operation === 'apply',
                    'status' => (string) $payload->status,
                ];
            },
        );

        return response()->json(['data' => $result]);
    }

    public function requestObjectPurge(Request $request, int $source, int $object): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $payload = $this->payloadObject($source, $object);
        $this->assertObjectPurgeState($payload);
        $this->assertNoOpenChange(GovernedAction::PurgeClinicalPayload, 'clinical_payload', (string) $payload->payload_uuid);
        $change = $this->governance->requestChange(
            $request,
            GovernedAction::PurgeClinicalPayload,
            'clinical_payload',
            (string) $payload->payload_uuid,
            (string) $validated['reason'],
            $this->governance->hashPayload($this->objectContract($payload, 'purge')),
            $this->scopes->requireSource($request, $source)->authorizationScope(),
            ['source_id' => $source, 'object_id' => $object, 'operation' => 'purge'],
        );

        return $this->createdChange($change, ['objectUuid' => (string) $payload->payload_uuid]);
    }

    public function executeObjectPurge(
        Request $request,
        string $changeRequestUuid,
        int $source,
        int $object,
    ): JsonResponse {
        $payload = $this->payloadObject($source, $object);
        $contract = $this->objectContract($payload, 'purge', $changeRequestUuid);
        $result = $this->governance->executeApprovedFailClosed(
            $request,
            $changeRequestUuid,
            GovernedAction::PurgeClinicalPayload,
            'clinical_payload',
            (string) $payload->payload_uuid,
            $this->governance->hashPayload($contract),
            fn (GovernedChangeRequest $approved): array => [
                ...$this->lifecycle->purge(
                    $object,
                    $source,
                    (string) $approved->getKey(),
                    (int) $request->user()->getAuthIdentifier(),
                ),
                'objectUuid' => (string) $payload->payload_uuid,
            ],
        );

        return response()->json(['data' => $result]);
    }

    public function requestIntegrityRecovery(Request $request, int $source, int $object): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $payload = $this->payloadObject($source, $object);
        if ((string) $payload->status !== 'integrity_failed') {
            throw new ClinicalPayloadException('clinical_payload_integrity_recovery_state_invalid');
        }
        $action = GovernedAction::RecoverClinicalPayloadIntegrity;
        $this->assertNoOpenChange($action, 'clinical_payload', (string) $payload->payload_uuid);
        $change = $this->governance->requestChange(
            $request,
            $action,
            'clinical_payload',
            (string) $payload->payload_uuid,
            (string) $validated['reason'],
            $this->governance->hashPayload($this->objectContract($payload, 'recover_integrity')),
            $this->scopes->requireSource($request, $source)->authorizationScope(),
            ['source_id' => $source, 'object_id' => $object, 'operation' => 'recover_integrity'],
        );

        return $this->createdChange($change, ['objectUuid' => (string) $payload->payload_uuid]);
    }

    public function executeIntegrityRecovery(
        Request $request,
        string $changeRequestUuid,
        int $source,
        int $object,
    ): JsonResponse {
        $payload = $this->payloadObject($source, $object);
        $result = $this->governance->executeApproved(
            $request,
            $changeRequestUuid,
            GovernedAction::RecoverClinicalPayloadIntegrity,
            'clinical_payload',
            (string) $payload->payload_uuid,
            $this->governance->hashPayload($this->objectContract($payload, 'recover_integrity')),
            fn (GovernedChangeRequest $approved): array => [
                ...$this->payloads->recoverIntegrity(
                    $object,
                    $source,
                    (int) $request->user()->getAuthIdentifier(),
                    (string) $approved->getKey(),
                ),
                'objectUuid' => (string) $payload->payload_uuid,
            ],
        );

        return response()->json(['data' => $result]);
    }

    public function requestQuarantinePurge(Request $request, int $source, int $quarantine): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $record = $this->quarantine($source, $quarantine);
        $payload = $this->payloadObject($source, (int) $record->payload_object_id);
        $this->assertQuarantinePurgeState($record, $payload);
        $action = GovernedAction::PurgeQuarantinedPayload;
        $this->assertNoOpenChange($action, 'payload_quarantine', (string) $record->quarantine_uuid);
        $change = $this->governance->requestChange(
            $request,
            $action,
            'payload_quarantine',
            (string) $record->quarantine_uuid,
            (string) $validated['reason'],
            $this->governance->hashPayload($this->quarantineContract($record, $payload, 'purge')),
            $this->scopes->requireSource($request, $source)->authorizationScope(),
            ['source_id' => $source, 'quarantine_id' => $quarantine, 'operation' => 'purge'],
        );

        return $this->createdChange($change, ['quarantineUuid' => (string) $record->quarantine_uuid]);
    }

    public function executeQuarantinePurge(
        Request $request,
        string $changeRequestUuid,
        int $source,
        int $quarantine,
    ): JsonResponse {
        $record = $this->quarantine($source, $quarantine);
        $payload = $this->payloadObject($source, (int) $record->payload_object_id);
        $contract = $this->quarantineContract($record, $payload, 'purge', $changeRequestUuid);
        $result = $this->governance->executeApprovedFailClosed(
            $request,
            $changeRequestUuid,
            GovernedAction::PurgeQuarantinedPayload,
            'payload_quarantine',
            (string) $record->quarantine_uuid,
            $this->governance->hashPayload($contract),
            function (GovernedChangeRequest $approved) use ($request, $record, $source): array {
                $this->quarantines->purge(
                    (int) $record->payload_quarantine_id,
                    $source,
                    (string) $approved->getKey(),
                    (int) $request->user()->getAuthIdentifier(),
                );

                return ['quarantineUuid' => (string) $record->quarantine_uuid, 'status' => 'purged'];
            },
        );

        return response()->json(['data' => $result]);
    }

    private function payloadObject(int $sourceId, int $payloadObjectId): object
    {
        $record = DB::table('raw.payload_objects')
            ->where('payload_object_id', $payloadObjectId)
            ->where('source_id', $sourceId)
            ->first();
        if ($record === null) {
            throw new ClinicalPayloadException('clinical_payload_authority_mismatch');
        }

        return $record;
    }

    private function quarantine(int $sourceId, int $quarantineId): object
    {
        $record = DB::table('raw.payload_quarantines')
            ->where('payload_quarantine_id', $quarantineId)
            ->where('source_id', $sourceId)
            ->first();
        if ($record === null) {
            throw new ClinicalPayloadException('clinical_payload_quarantine_missing');
        }

        return $record;
    }

    private function assertHoldState(object $payload, string $operation): void
    {
        if (in_array((string) $payload->status, ['deletion_pending', 'deleted'], true)
            || ($operation === 'apply' && (bool) $payload->legal_hold)
            || ($operation === 'release' && ! (bool) $payload->legal_hold)) {
            throw new ClinicalPayloadException('clinical_payload_hold_state_invalid');
        }
    }

    private function assertObjectPurgeState(object $payload): void
    {
        if ((bool) $payload->legal_hold) {
            throw new ClinicalPayloadException('clinical_payload_legal_hold_active');
        }
        if ((string) $payload->status === 'quarantined') {
            throw new ClinicalPayloadException('clinical_payload_quarantine_active');
        }
        if ((string) $payload->status === 'deleted') {
            throw new ClinicalPayloadException('clinical_payload_deleted');
        }
        if ($this->lifecycle->deletionBlockers((int) $payload->payload_object_id) !== []) {
            throw new ClinicalPayloadException('clinical_payload_deletion_blocked');
        }
    }

    private function assertQuarantinePurgeState(object $quarantine, object $payload): void
    {
        if ((string) $quarantine->status !== 'open'
            || ! in_array((string) $payload->status, ['quarantined', 'deletion_pending'], true)) {
            throw new ClinicalPayloadException('clinical_payload_quarantine_state_invalid');
        }
        if ((bool) $payload->legal_hold) {
            throw new ClinicalPayloadException('clinical_payload_legal_hold_active');
        }
        if ($this->lifecycle->deletionBlockers((int) $payload->payload_object_id) !== []) {
            throw new ClinicalPayloadException('clinical_payload_deletion_blocked');
        }
    }

    private function assertNoOpenChange(GovernedAction $action, string $subjectType, string $subjectId): void
    {
        $exists = GovernedChangeRequest::query()
            ->where('action_type', $action->value)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('expires_at', '>', now())
            ->whereDoesntHave('executions', fn ($query) => $query->where('outcome', 'success'))
            ->where(function ($query): void {
                $query->whereDoesntHave('decision')
                    ->orWhereHas('decision', fn ($decision) => $decision->where('decision', 'approved'));
            })
            ->exists();
        if ($exists) {
            throw new GovernanceViolation('change_request_already_open', 'An unexpired governed change already controls this exact action and subject.');
        }
    }

    /** @return array<string, bool|int|string|null|list<string>> */
    private function objectContract(
        object $payload,
        string $operation,
        ?string $governedChangeUuid = null,
        ?string $reasonCode = null,
    ): array {
        $status = (string) $payload->status;
        if ($status === 'deletion_pending' && $governedChangeUuid !== null) {
            $status = (string) (DB::table('raw.payload_object_events')
                ->where('payload_object_id', $payload->payload_object_id)
                ->where('event_type', 'purge_marked')
                ->where('governed_change_request_uuid', $governedChangeUuid)
                ->orderByDesc('payload_object_event_id')
                ->value('from_status') ?? $status);
        }

        return [
            'source_id' => (int) $payload->source_id,
            'payload_object_id' => (int) $payload->payload_object_id,
            'payload_uuid' => (string) $payload->payload_uuid,
            'payload_kind' => (string) $payload->payload_kind,
            'data_classification' => (string) $payload->data_classification,
            'status' => $status,
            'legal_hold' => (bool) $payload->legal_hold,
            'retain_until' => (string) $payload->retain_until,
            'operation' => $operation,
            'reason_code' => $reasonCode,
            'deletion_blockers' => $operation === 'purge'
                ? $this->lifecycle->deletionBlockers((int) $payload->payload_object_id)
                : [],
            'authority_sha256' => hash('sha256', implode('|', [
                (string) $payload->ciphertext_sha256,
                (string) $payload->key_provider_scheme,
                (string) $payload->key_provider_version,
            ])),
        ];
    }

    /** @return array<string, bool|int|string|null|list<string>> */
    private function quarantineContract(
        object $quarantine,
        object $payload,
        string $operation,
        ?string $governedChangeUuid = null,
    ): array {
        return [
            'source_id' => (int) $quarantine->source_id,
            'payload_quarantine_id' => (int) $quarantine->payload_quarantine_id,
            'quarantine_uuid' => (string) $quarantine->quarantine_uuid,
            'quarantine_status' => (string) $quarantine->status,
            'operation' => $operation,
            'object' => $this->objectContract($payload, $operation, $governedChangeUuid),
        ];
    }

    /** @param array<string, mixed> $extra */
    private function createdChange(GovernedChangeRequest $change, array $extra = []): JsonResponse
    {
        return response()->json(['data' => [
            'changeRequestUuid' => (string) $change->getKey(),
            'action' => $change->action_type->value,
            'status' => 'pending_approval',
            'expiresAt' => $change->expires_at?->toIso8601String(),
            ...$extra,
        ]], 201);
    }
}
