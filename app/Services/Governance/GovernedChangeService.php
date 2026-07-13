<?php

namespace App\Services\Governance;

use App\Authorization\AuthorizationScope;
use App\Authorization\Capability;
use App\Authorization\GovernedAction;
use App\Models\Governance\GovernedChangeDecision;
use App\Models\Governance\GovernedChangeExecution;
use App\Models\Governance\GovernedChangeRequest;
use App\Models\User;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use App\Security\ClinicalPayloads\ClinicalPayloadException;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\StepUpAuthenticationService;
use App\Services\Authorization\RoleCapabilityService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dual-control workflow for production source activation, credential rotation,
 * destructive replay, and outbound dispatch policy. Requests, decisions, and
 * execution attempts are separate append-only records.
 */
final class GovernedChangeService
{
    public function __construct(
        private readonly RoleCapabilityService $authorization,
        private readonly StepUpAuthenticationService $stepUp,
        private readonly UserAuditRecorder $audit,
        private readonly ClinicalContentGuard $clinicalContent,
    ) {}

    public function requestChange(
        Request $request,
        GovernedAction $action,
        string $subjectType,
        string $subjectId,
        string $reason,
        string $payloadSha256,
        ?AuthorizationScope $scope = null,
        array $metadata = [],
    ): GovernedChangeRequest {
        $actor = $this->actor($request);
        $this->assertAuthorized($actor, $action->authorCapability(), $scope);
        $this->stepUp->assertSatisfied($request, $action->value.'_requested');
        $this->assertIdentifiers($subjectType, $subjectId);
        $reason = $this->validatedReason($reason);
        $payloadSha256 = $this->validatedHash($payloadSha256);
        $metadata = $this->validatedMetadata($metadata);

        $change = GovernedChangeRequest::query()->create([
            'change_request_uuid' => (string) Str::uuid7(),
            'action_type' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'organization_id' => $scope?->organizationId,
            'facility_id' => $scope?->facilityId,
            'author_user_id' => $actor->getKey(),
            'reason' => $reason,
            'payload_sha256' => $payloadSha256,
            'requested_at' => now(),
            'expires_at' => now()->addSeconds((int) config('security.governed_changes.ttl_seconds', 604800)),
            'metadata' => $metadata,
        ]);

        $this->audit->record('governance.change.requested', 'administration', 'success', [
            'request' => $request,
            'reason' => $action->value,
            'target_type' => 'governed_change',
            'target_id' => $change->getKey(),
            'metadata' => [
                'governed_action' => $action->value,
                'change_request_uuid' => $change->getKey(),
            ],
        ]);

        return $change;
    }

    public function decide(
        Request $request,
        string $changeRequestUuid,
        bool $approve,
        string $reason,
    ): GovernedChangeDecision {
        $actor = $this->actor($request);
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($request, $actor, $changeRequestUuid, $approve, $reason): GovernedChangeDecision {
            $change = GovernedChangeRequest::query()->whereKey($changeRequestUuid)->lockForUpdate()->firstOrFail();
            $scope = $this->scope($change);
            $this->assertAuthorized($actor, $change->action_type->approvalCapability(), $scope);
            $this->stepUp->assertSatisfied($request, $change->action_type->value.'_decision');

            if ((int) $change->author_user_id === (int) $actor->getKey()) {
                throw new GovernanceViolation('author_approver_conflict', 'The author cannot decide their own governed change.');
            }
            if ($change->expires_at->isPast()) {
                throw new GovernanceViolation('change_request_expired', 'The governed change request has expired.');
            }
            if (GovernedChangeDecision::query()->where('change_request_uuid', $change->getKey())->exists()) {
                throw new GovernanceViolation('change_already_decided', 'The governed change already has a decision.');
            }

            $decision = GovernedChangeDecision::query()->create([
                'change_request_uuid' => $change->getKey(),
                'decision' => $approve ? 'approved' : 'rejected',
                'decided_by_user_id' => $actor->getKey(),
                'reason' => $reason,
                'decided_at' => now(),
            ]);

            $this->audit->record('governance.change.decided', 'administration', 'success', [
                'request' => $request,
                'reason' => $change->action_type->value,
                'target_type' => 'governed_change',
                'target_id' => $change->getKey(),
                'metadata' => [
                    'governed_action' => $change->action_type->value,
                    'decision' => $decision->decision,
                    'change_request_uuid' => $change->getKey(),
                ],
            ]);

            return $decision;
        });
    }

    public function executeApproved(
        Request $request,
        string $changeRequestUuid,
        GovernedAction $expectedAction,
        string $expectedSubjectType,
        string $expectedSubjectId,
        string $expectedPayloadSha256,
        Closure $operation,
    ): mixed {
        $actor = $this->actor($request);
        $expectedPayloadSha256 = $this->validatedHash($expectedPayloadSha256);

        return DB::transaction(function () use (
            $request,
            $actor,
            $changeRequestUuid,
            $expectedAction,
            $expectedSubjectType,
            $expectedSubjectId,
            $expectedPayloadSha256,
            $operation,
        ): mixed {
            $change = GovernedChangeRequest::query()->whereKey($changeRequestUuid)->lockForUpdate()->firstOrFail();
            $this->assertExecutableRequest(
                $change,
                $expectedAction,
                $expectedSubjectType,
                $expectedSubjectId,
                $expectedPayloadSha256,
            );
            $this->assertAuthorized($actor, $expectedAction->authorCapability(), $this->scope($change));
            $this->stepUp->assertSatisfied($request, $expectedAction->value.'_executed');

            $result = $operation($change);

            GovernedChangeExecution::query()->create([
                'change_request_uuid' => $change->getKey(),
                'executed_by_user_id' => $actor->getKey(),
                'outcome' => 'success',
                'executed_at' => now(),
                'metadata' => [],
            ]);
            $this->audit->record('governance.change.executed', 'administration', 'success', [
                'request' => $request,
                'reason' => $expectedAction->value,
                'target_type' => 'governed_change',
                'target_id' => $change->getKey(),
                'metadata' => [
                    'governed_action' => $expectedAction->value,
                    'change_request_uuid' => $change->getKey(),
                ],
            ]);

            return $result;
        });
    }

    /**
     * Execute an approved operation whose external side effect cannot be rolled back.
     * Known fail-closed payload failures are committed with a failure attempt so the
     * same approval can be retried without losing deletion-pending evidence.
     */
    public function executeApprovedFailClosed(
        Request $request,
        string $changeRequestUuid,
        GovernedAction $expectedAction,
        string $expectedSubjectType,
        string $expectedSubjectId,
        string $expectedPayloadSha256,
        Closure $operation,
    ): mixed {
        $actor = $this->actor($request);
        $expectedPayloadSha256 = $this->validatedHash($expectedPayloadSha256);

        $result = DB::transaction(function () use (
            $request,
            $actor,
            $changeRequestUuid,
            $expectedAction,
            $expectedSubjectType,
            $expectedSubjectId,
            $expectedPayloadSha256,
            $operation,
        ): mixed {
            $change = GovernedChangeRequest::query()->whereKey($changeRequestUuid)->lockForUpdate()->firstOrFail();
            $this->assertExecutableRequest(
                $change,
                $expectedAction,
                $expectedSubjectType,
                $expectedSubjectId,
                $expectedPayloadSha256,
            );
            $this->assertAuthorized($actor, $expectedAction->authorCapability(), $this->scope($change));
            $this->stepUp->assertSatisfied($request, $expectedAction->value.'_executed');

            try {
                $operationResult = $operation($change);
            } catch (ClinicalPayloadException $exception) {
                GovernedChangeExecution::query()->create([
                    'change_request_uuid' => $change->getKey(),
                    'executed_by_user_id' => $actor->getKey(),
                    'outcome' => 'failure',
                    'executed_at' => now(),
                    'metadata' => ['error_code' => explode(':', $exception->errorCode, 2)[0]],
                ]);
                $this->audit->record('governance.change.executed', 'administration', 'failure', [
                    'request' => $request,
                    'reason' => $expectedAction->value,
                    'target_type' => 'governed_change',
                    'target_id' => $change->getKey(),
                    'metadata' => [
                        'governed_action' => $expectedAction->value,
                        'change_request_uuid' => $change->getKey(),
                        'error_code' => explode(':', $exception->errorCode, 2)[0],
                    ],
                ]);

                return new GovernedExecutionFailure($exception);
            }

            GovernedChangeExecution::query()->create([
                'change_request_uuid' => $change->getKey(),
                'executed_by_user_id' => $actor->getKey(),
                'outcome' => 'success',
                'executed_at' => now(),
                'metadata' => [],
            ]);
            $this->audit->record('governance.change.executed', 'administration', 'success', [
                'request' => $request,
                'reason' => $expectedAction->value,
                'target_type' => 'governed_change',
                'target_id' => $change->getKey(),
                'metadata' => [
                    'governed_action' => $expectedAction->value,
                    'change_request_uuid' => $change->getKey(),
                ],
            ]);

            return $operationResult;
        });

        if ($result instanceof GovernedExecutionFailure) {
            throw $result->exception;
        }

        return $result;
    }

    /** @param array<string, mixed> $payload */
    public function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new GovernanceViolation('actor_missing', 'An authenticated Zephyrus user is required.');
        }

        return $actor;
    }

    private function assertAuthorized(User $actor, Capability $capability, ?AuthorizationScope $scope): void
    {
        $decision = $this->authorization->decide($actor, $capability, $scope);
        if (! $decision->allowed) {
            throw new GovernanceViolation('authorization_denied', 'The actor is not authorized for this governed change and scope.');
        }
    }

    private function assertExecutableRequest(
        GovernedChangeRequest $change,
        GovernedAction $action,
        string $subjectType,
        string $subjectId,
        string $payloadSha256,
    ): void {
        if ($change->action_type !== $action
            || $change->subject_type !== $subjectType
            || $change->subject_id !== $subjectId
            || ! hash_equals((string) $change->payload_sha256, $payloadSha256)) {
            throw new GovernanceViolation('approved_payload_mismatch', 'The approved request does not match the execution payload.');
        }
        if ($change->expires_at->isPast()) {
            throw new GovernanceViolation('change_request_expired', 'The governed change request has expired.');
        }

        $decision = GovernedChangeDecision::query()->where('change_request_uuid', $change->getKey())->first();
        if ($decision === null || $decision->decision !== 'approved') {
            throw new GovernanceViolation('approval_missing', 'The governed change has not been approved.');
        }
        if (GovernedChangeExecution::query()
            ->where('change_request_uuid', $change->getKey())
            ->where('outcome', 'success')
            ->exists()) {
            throw new GovernanceViolation('change_already_executed', 'The governed change was already executed successfully.');
        }
    }

    private function scope(GovernedChangeRequest $change): ?AuthorizationScope
    {
        if ($change->facility_id !== null) {
            return AuthorizationScope::facility((int) $change->facility_id);
        }
        if ($change->organization_id !== null) {
            return AuthorizationScope::organization((int) $change->organization_id);
        }

        return null;
    }

    private function assertIdentifiers(string $subjectType, string $subjectId): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,79}$/', $subjectType) !== 1
            || preg_match('/^[A-Za-z0-9_.:-]{1,190}$/', $subjectId) !== 1) {
            throw new GovernanceViolation('subject_invalid', 'The governed subject identifier is invalid.');
        }
    }

    private function validatedReason(string $reason): string
    {
        $reason = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $reason) ?? '');
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new GovernanceViolation('reason_invalid', 'A 10-500 character governed reason is required.');
        }
        $this->clinicalContent->assertSafe($reason, 'clinical_content_governance_rejected');

        return $reason;
    }

    private function validatedHash(string $payloadSha256): string
    {
        $payloadSha256 = strtolower(trim($payloadSha256));
        if (preg_match('/^[0-9a-f]{64}$/', $payloadSha256) !== 1) {
            throw new GovernanceViolation('payload_hash_invalid', 'A SHA-256 payload hash is required.');
        }

        return $payloadSha256;
    }

    /** @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function validatedMetadata(array $metadata): array
    {
        $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 4096) {
            throw new GovernanceViolation('metadata_invalid', 'Governed change metadata exceeds its safe bound.');
        }
        $walk = function (mixed $value, ?string $key = null) use (&$walk): void {
            if ($key !== null && preg_match('/secret|password|token|credential|private.?key|patient|payload|body/i', $key)) {
                throw new GovernanceViolation('metadata_invalid', 'Governed change metadata contains a prohibited field.');
            }
            if (is_array($value)) {
                foreach ($value as $nestedKey => $nested) {
                    $walk($nested, is_string($nestedKey) ? $nestedKey : null);
                }

                return;
            }
            if (! is_null($value) && ! is_scalar($value)) {
                throw new GovernanceViolation('metadata_invalid', 'Governed change metadata must contain only bounded scalar values.');
            }
        };
        $walk($metadata);
        $this->clinicalContent->assertSafe($metadata, 'clinical_content_governance_rejected');

        return $metadata;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $nested): mixed => $this->canonicalize($nested), $value);
    }
}
