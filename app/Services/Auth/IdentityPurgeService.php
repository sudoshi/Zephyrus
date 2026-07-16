<?php

namespace App\Services\Auth;

use App\Authorization\GovernedAction;
use App\Models\Auth\UserExternalIdentity;
use App\Models\Governance\GovernedChangeRequest;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\Oidc\ExternalIdentityEventRecorder;
use App\Services\Governance\GovernanceViolation;
use App\Services\Governance\GovernedChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Exceptional, retention-aware identity purge. The user row and numeric key
 * remain so clinical, operational, governance, and audit FKs keep their
 * accountability chain; direct identifiers and every access path are removed.
 */
final class IdentityPurgeService
{
    public function __construct(
        private readonly GovernedChangeService $governance,
        private readonly AccountLifecyclePolicy $policy,
        private readonly AccountSessionService $sessions,
        private readonly ExternalIdentityEventRecorder $identityEvents,
        private readonly UserAuditRecorder $audit,
    ) {}

    public function requestPurge(Request $request, User $target, string $reason): GovernedChangeRequest
    {
        $actor = $this->actor($request);
        $this->policy->assertIdentityPurgeAllowed($actor, $target);
        $this->assertNoOpenPurge($target);
        $payloadHash = $this->governance->hashPayload($this->contract($target));

        return $this->governance->requestChange(
            $request,
            GovernedAction::PurgeUserIdentity,
            'user_identity',
            (string) $target->getKey(),
            $reason,
            $payloadHash,
        );
    }

    public function decide(Request $request, string $changeRequestUuid, bool $approve, string $reason): void
    {
        $change = GovernedChangeRequest::query()->whereKey($changeRequestUuid)->firstOrFail();
        if ($change->action_type !== GovernedAction::PurgeUserIdentity || $change->subject_type !== 'user_identity') {
            throw new GovernanceViolation('governed_action_mismatch', 'This decision endpoint accepts only user identity purges.');
        }

        $this->governance->decide($request, $changeRequestUuid, $approve, $reason);
    }

    /** @return array<string, mixed> */
    public function execute(Request $request, string $changeRequestUuid, User $target): array
    {
        $currentHash = $this->governance->hashPayload($this->contract($target));

        return $this->governance->executeApproved(
            $request,
            $changeRequestUuid,
            GovernedAction::PurgeUserIdentity,
            'user_identity',
            (string) $target->getKey(),
            $currentHash,
            function (GovernedChangeRequest $change) use ($request, $target, $currentHash): array {
                $lockedTarget = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
                $lockedHash = $this->governance->hashPayload($this->contract($lockedTarget));
                if (! hash_equals($currentHash, $lockedHash)) {
                    throw new GovernanceViolation(
                        'approved_payload_mismatch',
                        'The account changed while the approved purge was being executed.',
                    );
                }

                $actor = $this->actor($request);
                $this->policy->assertIdentityPurgeAllowed($actor, $lockedTarget);

                $revocation = $this->sessions->revoke($lockedTarget, $request, 'approved_identity_purge');
                $unlinked = $this->purgeExternalIdentities($lockedTarget, $actor, $change);
                $deviceCount = $lockedTarget->mobileDevices()->count();
                $scopeCount = $lockedTarget->accessScopes()->count();

                $lockedTarget->mobileDevices()->delete();
                $lockedTarget->accessScopes()->delete();
                $lockedTarget->units()->detach();
                $lockedTarget->syncPermissions([]);
                $lockedTarget->syncRoles([]);

                $tombstone = substr(hash('sha256', $change->getKey().':'.$lockedTarget->getKey()), 0, 20);
                $lockedTarget->forceFill([
                    'name' => 'Purged account '.$lockedTarget->getKey(),
                    'email' => 'purged+'.$lockedTarget->getKey().'.'.$tombstone.'@invalid.test',
                    'username' => 'purged_'.$lockedTarget->getKey().'_'.$tombstone,
                    'password' => Hash::make(Str::random(96)),
                    'phone' => null,
                    'workflow_preference' => 'superuser',
                    'must_change_password' => false,
                    'role' => 'user',
                    'is_active' => false,
                    'email_verified_at' => null,
                    'remember_token' => null,
                    'deactivated_at' => $lockedTarget->deactivated_at ?? now(),
                    'identity_purged_at' => now(),
                    'identity_purge_request_uuid' => $change->getKey(),
                ])->save();

                $result = [
                    'user_id' => (int) $lockedTarget->getKey(),
                    'external_identities_unlinked' => $unlinked,
                    'mobile_devices_revoked' => $deviceCount,
                    'access_scopes_revoked' => $scopeCount,
                    ...$revocation,
                ];

                $this->audit->record('administration.user.identity_purged', 'administration', 'success', [
                    'request' => $request,
                    'reason' => 'approved_identity_purge',
                    'target_type' => 'user',
                    'target_id' => $lockedTarget->getKey(),
                    'changes' => [
                        'is_active' => ['from' => false, 'to' => false],
                        'identity_purged' => ['from' => false, 'to' => true],
                    ],
                    'metadata' => [
                        'change_request_uuid' => $change->getKey(),
                        'external_identities_unlinked' => $unlinked,
                        'mobile_devices_revoked' => $deviceCount,
                        'access_scopes_revoked' => $scopeCount,
                    ],
                ]);

                return $result;
            },
        );
    }

    /** @return array<string, mixed> */
    public function contract(User $target): array
    {
        $target->refresh();

        return [
            'user_id' => (int) $target->getKey(),
            'updated_at' => $target->updated_at?->toIso8601String(),
            'is_active' => (bool) $target->is_active,
            'is_protected' => (bool) $target->is_protected,
            'identity_purged_at' => $target->identity_purged_at?->toIso8601String(),
            'auth_session_version' => (int) $target->auth_session_version,
            'active_token_count' => $target->tokens()->count(),
            'active_scope_count' => $target->accessScopes()->count(),
            'external_identities' => $target->externalIdentities()
                ->orderBy('id')
                ->get()
                ->map(fn (UserExternalIdentity $identity): array => [
                    'id' => (int) $identity->getKey(),
                    'provider' => $identity->provider,
                    'subject_sha256' => hash('sha256', $identity->provider.':'.$identity->provider_subject),
                    'is_active' => (bool) $identity->is_active,
                    'updated_at' => $identity->updated_at?->toIso8601String(),
                ])->all(),
        ];
    }

    private function purgeExternalIdentities(
        User $target,
        User $actor,
        GovernedChangeRequest $change,
    ): int {
        $identities = $target->externalIdentities()->lockForUpdate()->get();
        $unlinked = 0;
        foreach ($identities as $identity) {
            if ($identity->is_active) {
                $identity->forceFill([
                    'is_active' => false,
                    'unlinked_at' => now(),
                    'unlinked_by_user_id' => $actor->getKey(),
                    'unlink_reason' => 'Approved identity purge '.$change->getKey(),
                ])->save();
                $this->identityEvents->record(
                    $identity,
                    'unlinked',
                    $actor,
                    'Approved identity purge '.$change->getKey(),
                    ['change_request_uuid' => $change->getKey()],
                );
                $unlinked++;
            }

            $subjectHash = hash('sha256', $identity->provider.':'.$identity->provider_subject);
            $identity->forceFill([
                'provider_subject' => 'purged:'.$subjectHash,
                'provider_email_at_link' => null,
            ])->save();
        }

        return $unlinked;
    }

    private function assertNoOpenPurge(User $target): void
    {
        $open = GovernedChangeRequest::query()
            ->where('action_type', GovernedAction::PurgeUserIdentity->value)
            ->where('subject_type', 'user_identity')
            ->where('subject_id', (string) $target->getKey())
            ->where('expires_at', '>', now())
            ->whereDoesntHave('executions', fn ($query) => $query->where('outcome', 'success'))
            ->where(function ($query): void {
                $query->whereDoesntHave('decision')
                    ->orWhereHas('decision', fn ($decision) => $decision->where('decision', 'approved'));
            })
            ->exists();

        if ($open) {
            throw new GovernanceViolation('purge_request_open', 'An unexpired purge request already governs this account.');
        }
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new GovernanceViolation('actor_missing', 'An authenticated Zephyrus user is required.');
        }

        return $actor;
    }
}
