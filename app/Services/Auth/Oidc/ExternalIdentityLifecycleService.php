<?php

namespace App\Services\Auth\Oidc;

use App\Authorization\Capability;
use App\Models\Auth\UserExternalIdentity;
use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\AccountLifecyclePolicy;
use App\Services\Auth\AccountLifecycleViolation;
use App\Services\Auth\AccountSessionService;
use App\Services\Auth\StepUpAuthenticationService;
use App\Services\Authorization\RoleCapabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ExternalIdentityLifecycleService
{
    public function __construct(
        private readonly RoleCapabilityService $authorization,
        private readonly StepUpAuthenticationService $stepUp,
        private readonly AccountLifecyclePolicy $policy,
        private readonly AccountSessionService $sessions,
        private readonly ExternalIdentityEventRecorder $events,
        private readonly UserAuditRecorder $audit,
    ) {}

    public function unlink(Request $request, User $target, UserExternalIdentity $identity, string $reason): void
    {
        $actor = $this->actor($request);
        $this->authorize($actor);
        $this->stepUp->assertSatisfied($request, 'external_identity_unlinked');
        $reason = $this->reason($reason);

        DB::transaction(function () use ($request, $actor, $target, $identity, $reason): void {
            $lockedTarget = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            $lockedIdentity = UserExternalIdentity::query()
                ->whereKey($identity->getKey())
                ->where('user_id', $lockedTarget->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->policy->assertExternalIdentityMutationAllowed($actor, $lockedTarget);
            if (! $lockedIdentity->is_active) {
                throw new AccountLifecycleViolation(
                    'identity_already_unlinked',
                    'identity',
                    'This external identity is already unlinked.',
                );
            }

            $lockedIdentity->forceFill([
                'is_active' => false,
                'unlinked_at' => now(),
                'unlinked_by_user_id' => $actor->getKey(),
                'unlink_reason' => $reason,
            ])->save();

            $this->events->record($lockedIdentity, 'unlinked', $actor, $reason);
            $this->sessions->revoke($lockedTarget, $request, 'external_identity_unlinked');
            $this->audit->record('administration.external_identity.unlinked', 'administration', 'success', [
                'request' => $request,
                'reason' => 'external_identity_unlinked',
                'target_type' => 'user_external_identity',
                'target_id' => $lockedIdentity->getKey(),
                'metadata' => $this->safeMetadata($lockedIdentity),
            ]);
        });
    }

    public function relink(Request $request, User $target, UserExternalIdentity $identity, string $reason): void
    {
        $actor = $this->actor($request);
        $this->authorize($actor);
        $this->stepUp->assertSatisfied($request, 'external_identity_relinked');
        $reason = $this->reason($reason);

        DB::transaction(function () use ($request, $actor, $target, $identity, $reason): void {
            $lockedTarget = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            $lockedIdentity = UserExternalIdentity::query()
                ->whereKey($identity->getKey())
                ->where('user_id', $lockedTarget->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->policy->assertExternalIdentityMutationAllowed($actor, $lockedTarget);
            if (! (bool) $lockedTarget->is_active) {
                throw new AccountLifecycleViolation(
                    'account_inactive',
                    'identity',
                    'Reactivate the account before relinking an external identity.',
                );
            }
            if ($lockedIdentity->is_active) {
                throw new AccountLifecycleViolation(
                    'identity_already_linked',
                    'identity',
                    'This external identity is already linked.',
                );
            }

            $lockedIdentity->forceFill([
                'is_active' => true,
                'relinked_at' => now(),
                'relinked_by_user_id' => $actor->getKey(),
                'relink_reason' => $reason,
            ])->save();

            $this->events->record($lockedIdentity, 'relinked', $actor, $reason);
            $this->sessions->revoke($lockedTarget, $request, 'external_identity_relinked');
            $this->audit->record('administration.external_identity.relinked', 'administration', 'success', [
                'request' => $request,
                'reason' => 'external_identity_relinked',
                'target_type' => 'user_external_identity',
                'target_id' => $lockedIdentity->getKey(),
                'metadata' => $this->safeMetadata($lockedIdentity),
            ]);
        });
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AccountLifecycleViolation('actor_missing', 'identity', 'An authenticated identity administrator is required.');
        }

        return $actor;
    }

    private function authorize(User $actor): void
    {
        if (! $this->authorization->allows($actor, Capability::ManageIdentity)) {
            throw new AccountLifecycleViolation('authorization_denied', 'identity', 'The manageIdentity capability is required.');
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $reason) ?? '');
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new AccountLifecycleViolation('reason_invalid', 'reason', 'A 10-500 character identity-change reason is required.');
        }

        return $reason;
    }

    /** @return array<string, mixed> */
    private function safeMetadata(UserExternalIdentity $identity): array
    {
        return [
            'subject_user_id' => (int) $identity->user_id,
            'provider' => $identity->provider,
            'subject_fingerprint' => substr(hash('sha256', $identity->provider.':'.$identity->provider_subject), 0, 16),
            'link_active' => (bool) $identity->is_active,
        ];
    }
}
