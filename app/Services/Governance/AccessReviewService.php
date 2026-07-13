<?php

namespace App\Services\Governance;

use App\Authorization\Capability;
use App\Models\Audit\UserEvent;
use App\Models\Auth\UserAccessScope;
use App\Models\Auth\UserExternalIdentity;
use App\Models\Governance\AccessReviewCampaign;
use App\Models\Governance\AccessReviewDecision;
use App\Models\Governance\AccessReviewExport;
use App\Models\Governance\AccessReviewItem;
use App\Models\Governance\AccessReviewRemediation;
use App\Models\Org\StaffAssignment;
use App\Models\User;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\AccountSessionService;
use App\Services\Auth\StepUpAuthenticationService;
use App\Services\Authorization\RoleCapabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

/**
 * Quarterly privileged-access certification. Campaign creation freezes the
 * authorization inputs and their provenance. Decisions and remediation are
 * append-only; completed evidence is deterministic and content-addressed.
 */
final class AccessReviewService
{
    /** @var list<Capability> */
    private const REVIEWED_CAPABILITIES = [
        Capability::ViewAdministration,
        Capability::ViewIdentity,
        Capability::ManageIdentity,
        Capability::ManagePrivileges,
        Capability::ViewAudit,
        Capability::ViewAccessReviews,
        Capability::ManageAccessReviews,
        Capability::ViewEnterpriseSetup,
        Capability::ManageEnterpriseSetup,
        Capability::ManageFacilityAdministration,
        Capability::ViewIntegrations,
        Capability::ManageIntegrationConfiguration,
        Capability::OperateIntegrations,
        Capability::ApproveIntegrationChanges,
        Capability::RotateIntegrationCredentials,
        Capability::ExecuteDestructiveReplay,
        Capability::ManageOutboundPolicy,
        Capability::ManageDataStewardship,
        Capability::AssumeAnyMobilePersona,
    ];

    public function __construct(
        private readonly RoleCapabilityService $authorization,
        private readonly StepUpAuthenticationService $stepUp,
        private readonly AccountSessionService $sessions,
        private readonly UserAuditRecorder $audit,
        private readonly ClinicalContentGuard $clinicalContent,
    ) {}

    /**
     * @param  array{title:string,review_period_start:string,review_period_end:string,due_at:string}  $attributes
     */
    public function createCampaign(
        User $actor,
        User $primaryReviewer,
        User $alternateReviewer,
        array $attributes,
        Request $request,
    ): AccessReviewCampaign {
        $this->clinicalContent->assertSafe($attributes['title'], 'clinical_content_evidence_rejected');
        $this->assertReviewer($primaryReviewer);
        $this->assertReviewer($alternateReviewer);
        if ($primaryReviewer->is($alternateReviewer)) {
            throw new GovernanceViolation('reviewers_not_independent', 'Primary and alternate reviewers must be different people.');
        }

        $candidates = User::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $this->hasReviewedAccess($user));

        if ($candidates->isEmpty()) {
            throw new GovernanceViolation('empty_review_population', 'No active privileged access exists to review.');
        }

        return DB::transaction(function () use (
            $actor,
            $primaryReviewer,
            $alternateReviewer,
            $attributes,
            $request,
            $candidates,
        ): AccessReviewCampaign {
            $periodLock = $attributes['review_period_start'].':'.$attributes['review_period_end'];
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$periodLock]);
            if (AccessReviewCampaign::query()
                ->where('review_period_start', $attributes['review_period_start'])
                ->where('review_period_end', $attributes['review_period_end'])
                ->where('status', '!=', 'cancelled')
                ->exists()) {
                throw new GovernanceViolation('campaign_period_exists', 'An access-review campaign already exists for this calendar quarter.');
            }

            $snapshotAt = now();
            $campaign = AccessReviewCampaign::query()->create([
                'campaign_uuid' => (string) Str::uuid7(),
                'title' => $attributes['title'],
                'review_period_start' => $attributes['review_period_start'],
                'review_period_end' => $attributes['review_period_end'],
                'due_at' => $attributes['due_at'],
                'status' => 'open',
                'primary_reviewer_user_id' => $primaryReviewer->getKey(),
                'alternate_reviewer_user_id' => $alternateReviewer->getKey(),
                'created_by_user_id' => $actor->getKey(),
                'snapshot_at' => $snapshotAt,
                'opened_at' => $snapshotAt,
            ]);

            $itemDigests = [];
            foreach ($candidates as $subject) {
                $reviewer = $primaryReviewer->is($subject) ? $alternateReviewer : $primaryReviewer;
                $snapshot = $this->entitlementSnapshot($subject);
                $snapshotJson = $this->canonicalJson($snapshot);
                $item = AccessReviewItem::query()->create([
                    'item_uuid' => (string) Str::uuid7(),
                    'campaign_id' => $campaign->getKey(),
                    'subject_user_id' => $subject->getKey(),
                    'reviewer_user_id' => $reviewer->getKey(),
                    'entitlement_snapshot' => $snapshot,
                    'snapshot_sha256' => hash('sha256', $snapshotJson),
                    'risk_flags' => $this->riskFlags($subject, $snapshot),
                    'created_at' => $snapshotAt,
                ]);
                $itemDigests[] = [
                    'item_uuid' => $item->item_uuid,
                    'snapshot_sha256' => $item->snapshot_sha256,
                ];
            }

            $campaign->forceFill([
                'snapshot_sha256' => hash('sha256', $this->canonicalJson($itemDigests)),
            ])->save();

            $this->audit->record('governance.access_review.opened', 'administration', 'success', [
                'request' => $request,
                'target_type' => 'access_review_campaign',
                'target_id' => $campaign->campaign_uuid,
                'reason' => 'quarterly_access_certification',
                'metadata' => ['campaign_uuid' => $campaign->campaign_uuid],
            ]);

            return $campaign->fresh(['items']);
        });
    }

    public function decide(
        AccessReviewCampaign $campaign,
        AccessReviewItem $item,
        User $actor,
        string $decision,
        string $reasonCode,
        string $rationale,
        Request $request,
    ): AccessReviewDecision {
        $this->clinicalContent->assertSafe($rationale, 'clinical_content_evidence_rejected');
        if ((int) $item->campaign_id !== (int) $campaign->getKey()) {
            throw new GovernanceViolation('review_item_mismatch', 'The review item does not belong to this campaign.');
        }
        if ($campaign->status !== 'open') {
            throw new GovernanceViolation('campaign_closed', 'Only an open access-review campaign can receive decisions.');
        }
        if ((int) $item->reviewer_user_id !== (int) $actor->getKey()) {
            throw new GovernanceViolation('reviewer_mismatch', 'Only the independently assigned reviewer may decide this item.');
        }
        if ((int) $item->subject_user_id === (int) $actor->getKey()) {
            throw new GovernanceViolation('self_certification_prohibited', 'Self-certification of access is prohibited.');
        }
        if ($item->decision()->exists()) {
            throw new GovernanceViolation('decision_already_recorded', 'This access-review item already has an immutable decision.');
        }

        $this->stepUp->assertSatisfied($request, 'access_review_decision');

        return DB::transaction(function () use ($campaign, $item, $actor, $decision, $reasonCode, $rationale, $request): AccessReviewDecision {
            $lockedItem = AccessReviewItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedItem->decision()->exists()) {
                throw new GovernanceViolation('decision_already_recorded', 'This access-review item already has an immutable decision.');
            }

            $subject = User::query()->whereKey($lockedItem->subject_user_id)->lockForUpdate()->firstOrFail();
            $record = AccessReviewDecision::query()->create([
                'decision_uuid' => (string) Str::uuid7(),
                'campaign_item_id' => $lockedItem->getKey(),
                'decision' => $decision,
                'reason_code' => $reasonCode,
                'rationale' => $rationale,
                'decided_by_user_id' => $actor->getKey(),
                'reviewed_snapshot_sha256' => $lockedItem->snapshot_sha256,
                'decided_at' => now(),
            ]);

            if ($decision === 'revoke') {
                $result = $this->revokeReviewedAccess($actor, $subject, $request);
                AccessReviewRemediation::query()->create([
                    'remediation_uuid' => (string) Str::uuid7(),
                    'decision_id' => $record->getKey(),
                    'executed_by_user_id' => $actor->getKey(),
                    'result' => $result,
                    'executed_at' => now(),
                ]);
            }

            $this->audit->record('governance.access_review.decided', 'administration', 'success', [
                'request' => $request,
                'target_type' => 'access_review_item',
                'target_id' => $lockedItem->item_uuid,
                'reason' => $reasonCode,
                'metadata' => [
                    'campaign_uuid' => $campaign->campaign_uuid,
                    'item_uuid' => $lockedItem->item_uuid,
                    'decision' => $decision,
                ],
            ]);

            return $record->load('remediation');
        });
    }

    public function complete(AccessReviewCampaign $campaign, User $actor, Request $request): AccessReviewCampaign
    {
        if ($campaign->status !== 'open') {
            throw new GovernanceViolation('campaign_closed', 'Only an open campaign can be completed.');
        }
        $this->stepUp->assertSatisfied($request, 'access_review_completion');

        return DB::transaction(function () use ($campaign, $request): AccessReviewCampaign {
            $locked = AccessReviewCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            $locked->load(['items.decision.remediation', 'items.subject', 'items.reviewer', 'primaryReviewer', 'alternateReviewer']);

            if ($locked->items->isEmpty() || $locked->items->contains(fn (AccessReviewItem $item): bool => $item->decision === null)) {
                throw new GovernanceViolation('review_incomplete', 'Every snapshotted access item requires an immutable decision.');
            }
            if ($locked->items->contains(fn (AccessReviewItem $item): bool => $item->decision?->decision === 'revoke'
                && $item->decision?->remediation === null)) {
                throw new GovernanceViolation('remediation_incomplete', 'Every revoke decision must have durable remediation evidence.');
            }

            $locked->forceFill(['status' => 'completed', 'completed_at' => now()]);
            $document = $this->evidenceDocument($locked);
            $digest = hash('sha256', $this->canonicalJson($document));
            $locked->forceFill(['evidence_sha256' => $digest])->save();

            $this->audit->record('governance.access_review.completed', 'administration', 'success', [
                'request' => $request,
                'target_type' => 'access_review_campaign',
                'target_id' => $locked->campaign_uuid,
                'reason' => 'all_access_decisions_complete',
                'metadata' => [
                    'campaign_uuid' => $locked->campaign_uuid,
                    'evidence_sha256' => $digest,
                ],
            ]);

            return $locked->fresh();
        });
    }

    public function cancel(AccessReviewCampaign $campaign, User $actor, string $reason, Request $request): AccessReviewCampaign
    {
        $this->clinicalContent->assertSafe($reason, 'clinical_content_evidence_rejected');
        if ($campaign->status !== 'open') {
            throw new GovernanceViolation('campaign_closed', 'Only an open campaign can be cancelled.');
        }
        $this->stepUp->assertSatisfied($request, 'access_review_cancellation');

        return DB::transaction(function () use ($campaign, $actor, $reason, $request): AccessReviewCampaign {
            $locked = AccessReviewCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'open') {
                throw new GovernanceViolation('campaign_closed', 'Only an open campaign can be cancelled.');
            }
            $locked->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->getKey(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->audit->record('governance.access_review.cancelled', 'administration', 'success', [
                'request' => $request,
                'target_type' => 'access_review_campaign',
                'target_id' => $locked->campaign_uuid,
                'reason' => 'access_review_campaign_cancelled',
                'metadata' => ['campaign_uuid' => $locked->campaign_uuid],
            ]);

            return $locked;
        });
    }

    /** @return array<string, mixed> */
    public function evidenceDocument(AccessReviewCampaign $campaign): array
    {
        if ($campaign->status !== 'completed') {
            throw new GovernanceViolation('campaign_not_completed', 'Evidence is available only after campaign completion.');
        }

        $campaign->loadMissing([
            'items.subject:id,username',
            'items.reviewer:id,username',
            'items.decision.decidedBy:id,username',
            'items.decision.remediation.executedBy:id,username',
            'primaryReviewer:id,username',
            'alternateReviewer:id,username',
        ]);

        $items = $campaign->items->sortBy('item_uuid')->map(function (AccessReviewItem $item): array {
            $decision = $item->decision;

            return [
                'item_uuid' => $item->item_uuid,
                'subject' => ['user_id' => $item->subject_user_id, 'username' => $item->subject?->username],
                'reviewer' => ['user_id' => $item->reviewer_user_id, 'username' => $item->reviewer?->username],
                'entitlement_snapshot' => $item->entitlement_snapshot,
                'risk_flags' => $item->risk_flags,
                'snapshot_sha256' => $item->snapshot_sha256,
                'decision' => [
                    'decision_uuid' => $decision?->decision_uuid,
                    'value' => $decision?->decision,
                    'reason_code' => $decision?->reason_code,
                    'rationale' => $decision?->rationale,
                    'decided_by' => $decision ? [
                        'user_id' => $decision->decided_by_user_id,
                        'username' => $decision->decidedBy?->username,
                    ] : null,
                    'decided_at' => $decision?->decided_at?->toIso8601String(),
                    'reviewed_snapshot_sha256' => $decision?->reviewed_snapshot_sha256,
                ],
                'remediation' => $decision?->remediation ? [
                    'remediation_uuid' => $decision->remediation->remediation_uuid,
                    'executed_by' => [
                        'user_id' => $decision->remediation->executed_by_user_id,
                        'username' => $decision->remediation->executedBy?->username,
                    ],
                    'executed_at' => $decision->remediation->executed_at?->toIso8601String(),
                    'result' => $decision->remediation->result,
                ] : null,
            ];
        })->values()->all();

        $document = [
            'schema' => 'zephyrus.access-review-evidence.v1',
            'campaign' => [
                'campaign_uuid' => $campaign->campaign_uuid,
                'title' => $campaign->title,
                'review_period_start' => $campaign->review_period_start?->toDateString(),
                'review_period_end' => $campaign->review_period_end?->toDateString(),
                'due_at' => $campaign->due_at?->toIso8601String(),
                'snapshot_at' => $campaign->snapshot_at?->toIso8601String(),
                'opened_at' => $campaign->opened_at?->toIso8601String(),
                'completed_at' => $campaign->completed_at?->toIso8601String(),
                'primary_reviewer' => [
                    'user_id' => $campaign->primary_reviewer_user_id,
                    'username' => $campaign->primaryReviewer?->username,
                ],
                'alternate_reviewer' => [
                    'user_id' => $campaign->alternate_reviewer_user_id,
                    'username' => $campaign->alternateReviewer?->username,
                ],
                'snapshot_sha256' => $campaign->snapshot_sha256,
            ],
            'items' => $items,
        ];
        $this->clinicalContent->assertSafe($document, 'clinical_content_evidence_rejected');

        return $document;
    }

    /** @return array{content:string,sha256:string} */
    public function jsonEvidence(AccessReviewCampaign $campaign): array
    {
        $content = $this->canonicalJson($this->evidenceDocument($campaign));
        $content .= "\n";

        return ['content' => $content, 'sha256' => hash('sha256', $content)];
    }

    /** @return array{content:string,sha256:string} */
    public function csvEvidence(AccessReviewCampaign $campaign): array
    {
        $document = $this->evidenceDocument($campaign);
        $rows = [[
            'campaign_uuid', 'item_uuid', 'subject_user_id', 'subject_username',
            'reviewer_user_id', 'reviewer_username', 'effective_roles', 'effective_capabilities',
            'scope_count', 'workforce_assignment_count', 'risk_flags', 'decision', 'reason_code', 'rationale',
            'decided_by', 'decided_at', 'remediated', 'snapshot_sha256',
        ]];
        foreach ($document['items'] as $item) {
            $snapshot = $item['entitlement_snapshot'];
            $rows[] = [
                $document['campaign']['campaign_uuid'],
                $item['item_uuid'],
                (string) $item['subject']['user_id'],
                (string) $item['subject']['username'],
                (string) $item['reviewer']['user_id'],
                (string) $item['reviewer']['username'],
                implode('|', $snapshot['effective_roles']),
                implode('|', $snapshot['effective_capabilities']),
                (string) count($snapshot['explicit_scopes']),
                (string) count($snapshot['workforce_assignments']),
                implode('|', $item['risk_flags']),
                (string) $item['decision']['value'],
                (string) $item['decision']['reason_code'],
                (string) $item['decision']['rationale'],
                (string) ($item['decision']['decided_by']['username'] ?? ''),
                (string) $item['decision']['decided_at'],
                $item['remediation'] === null ? 'no' : 'yes',
                $item['snapshot_sha256'],
            ];
        }

        $stream = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($stream, array_map($this->safeCsvCell(...), $row), ',', '"', '');
        }
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);
        $content = str_replace("\n", "\r\n", $content);

        return ['content' => $content, 'sha256' => hash('sha256', $content)];
    }

    public function recordExport(AccessReviewCampaign $campaign, User $actor, string $format, string $sha256, Request $request): void
    {
        DB::transaction(function () use ($campaign, $actor, $format, $sha256, $request): void {
            AccessReviewExport::query()->create([
                'export_uuid' => (string) Str::uuid7(),
                'campaign_id' => $campaign->getKey(),
                'format' => $format,
                'content_sha256' => $sha256,
                'exported_by_user_id' => $actor->getKey(),
                'exported_at' => now(),
            ]);
            $this->audit->record('governance.access_review.evidence_exported', 'access', 'success', [
                'request' => $request,
                'target_type' => 'evidence_export',
                'target_id' => $campaign->campaign_uuid.'.'.$format,
                'reason' => 'access_review_evidence_export',
                'metadata' => [
                    'campaign_uuid' => $campaign->campaign_uuid,
                    'evidence_sha256' => $sha256,
                ],
            ]);
        });
    }

    private function assertReviewer(User $reviewer): void
    {
        if (! $reviewer->is_active || ! $this->authorization->allows($reviewer, Capability::ManageAccessReviews)) {
            throw new GovernanceViolation('reviewer_not_authorized', 'Every assigned reviewer must be active and authorized to manage access reviews.');
        }
    }

    private function hasReviewedAccess(User $user): bool
    {
        $reviewed = collect(self::REVIEWED_CAPABILITIES)->map->value;

        return collect($this->authorization->effectiveCapabilities($user))->map->value->intersect($reviewed)->isNotEmpty();
    }

    /** @return array<string, mixed> */
    private function entitlementSnapshot(User $user): array
    {
        $spatieRoles = $user->roles()->orderBy('name')->pluck('name')->all();
        $directPermissions = $user->permissions()->orderBy('name')->pluck('name')->all();
        $scopes = UserAccessScope::query()
            ->with([
                'organization:organization_id,organization_key,name',
                'facility:facility_id,facility_key,facility_name',
                'grantedBy:id,username',
            ])
            ->effective()
            ->where('user_id', $user->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn (UserAccessScope $scope): array => [
                'scope_id' => $scope->getKey(),
                'organization_id' => $scope->organization_id,
                'organization_key' => $scope->organization?->organization_key,
                'facility_id' => $scope->facility_id,
                'facility_key' => $scope->facility?->facility_key,
                'valid_from' => $scope->valid_from?->toIso8601String(),
                'valid_until' => $scope->valid_until?->toIso8601String(),
                'grant_reason' => $scope->grant_reason,
                'granted_by' => $scope->grantedBy ? [
                    'user_id' => $scope->granted_by_user_id,
                    'username' => $scope->grantedBy->username,
                ] : null,
            ])->all();
        $workforceAssignments = StaffAssignment::query()
            ->where('is_active', true)
            ->whereHas('staffMember', fn ($query) => $query
                ->where('user_id', $user->getKey())
                ->where('is_active', true))
            ->where(fn ($query) => $query->whereNull('effective_start')->orWhere('effective_start', '<=', today()))
            ->where(fn ($query) => $query->whereNull('effective_end')->orWhere('effective_end', '>=', today()))
            ->orderBy('staff_assignment_id')
            ->get()
            ->map(fn (StaffAssignment $assignment): array => [
                'staff_assignment_id' => $assignment->getKey(),
                'facility_key' => $assignment->facility_key,
                'service_line_code' => $assignment->service_line_code,
                'role_code' => $assignment->role_code,
                'unit_id' => $assignment->unit_id,
                'resolution_source' => $assignment->resolution_source,
                'review_status' => $assignment->review_status,
                'effective_start' => $assignment->effective_start?->toDateString(),
                'effective_end' => $assignment->effective_end?->toDateString(),
            ])->all();
        $lastAuthentication = UserEvent::query()
            ->where('actor_user_id', $user->getKey())
            ->whereIn('action', ['auth.login', 'mobile.auth.token_exchange'])
            ->where('outcome', 'success')
            ->max('occurred_at');

        return [
            'subject' => [
                'user_id' => $user->getKey(),
                'username' => $user->username,
                'display_name' => $user->name,
                'is_active' => (bool) $user->is_active,
                'is_protected' => (bool) $user->is_protected,
                'must_change_password' => (bool) $user->must_change_password,
            ],
            'scalar_role' => $this->authorization->canonicalRole($user->role),
            'spatie_roles' => $spatieRoles,
            'direct_permissions' => $directPermissions,
            'effective_roles' => $this->authorization->effectiveRoleIds($user),
            'effective_capabilities' => collect($this->authorization->effectiveCapabilities($user))->map->value->sort()->values()->all(),
            'explicit_scopes' => $scopes,
            'workforce_assignments' => $workforceAssignments,
            'external_identity_providers' => UserExternalIdentity::query()
                ->where('user_id', $user->getKey())->distinct()->orderBy('provider')->pluck('provider')->all(),
            'active_api_token_count' => $user->tokens()->count(),
            'last_successful_authentication_at' => $lastAuthentication,
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return list<string>
     */
    private function riskFlags(User $user, array $snapshot): array
    {
        $flags = collect();
        if ($user->is_protected) {
            $flags->push('protected_account');
        }
        if ($user->must_change_password) {
            $flags->push('password_change_due');
        }
        if ($snapshot['external_identity_providers'] === []) {
            $flags->push('local_identity_only');
        }
        if ($snapshot['active_api_token_count'] > 0) {
            $flags->push('active_api_tokens');
        }
        if (collect($snapshot['effective_roles'])->intersect(config('authorization.global_scope_roles', []))->isNotEmpty()) {
            $flags->push('global_scope');
        }
        if ($snapshot['last_successful_authentication_at'] === null
            || now()->subDays(90)->isAfter($snapshot['last_successful_authentication_at'])) {
            $flags->push('no_recent_authentication_90d');
        }

        return $flags->sort()->values()->all();
    }

    /** @return array<string, mixed> */
    private function revokeReviewedAccess(User $actor, User $subject, Request $request): array
    {
        if ($subject->is_protected) {
            throw new GovernanceViolation('protected_account', 'Protected access requires the separate break-glass lifecycle; it cannot be revoked by a routine review.');
        }
        if ($actor->is($subject)) {
            throw new GovernanceViolation('self_revocation_prohibited', 'A reviewer cannot remediate their own access.');
        }

        $hadAdministration = $this->authorization->allows($subject, Capability::ViewAdministration);
        if ($hadAdministration) {
            $otherAdministratorExists = User::query()
                ->whereKeyNot($subject->getKey())
                ->where('is_active', true)
                ->lockForUpdate()
                ->get()
                ->contains(fn (User $user): bool => $this->authorization->allows($user, Capability::ViewAdministration));
            if (! $otherAdministratorExists) {
                throw new GovernanceViolation('last_administrator', 'The final active administrator cannot be revoked.');
            }
        }

        $reviewedNames = collect(self::REVIEWED_CAPABILITIES)->map->value;
        $removedRoles = [];
        foreach ($subject->roles()->with('permissions')->get() as $role) {
            $canonical = $this->authorization->canonicalRole($role->name);
            $configured = collect(config('authorization.role_capabilities.'.$canonical, []));
            $permissionNames = $role->permissions->pluck('name')->map(
                fn (string $permission): string => str_starts_with($permission, 'capability:')
                    ? substr($permission, strlen('capability:'))
                    : $permission,
            );
            if ($configured->merge($permissionNames)->intersect($reviewedNames)->isNotEmpty()) {
                $subject->removeRole($role);
                $removedRoles[] = $role->name;
            }
        }

        $removedPermissions = [];
        foreach ($subject->permissions()->get() as $permission) {
            $capability = str_starts_with($permission->name, 'capability:')
                ? substr($permission->name, strlen('capability:'))
                : $permission->name;
            if ($reviewedNames->contains($capability)) {
                $subject->revokePermissionTo($permission);
                $removedPermissions[] = $permission->name;
            }
        }

        $oldScalarRole = $this->authorization->canonicalRole($subject->role);
        $scalarCapabilities = collect(config('authorization.role_capabilities.'.$oldScalarRole, []));
        $newScalarRole = $oldScalarRole;
        if ($scalarCapabilities->intersect($reviewedNames)->isNotEmpty()) {
            $newScalarRole = 'user';
            $subject->forceFill(['role' => 'user'])->save();
        }

        $scopeCount = UserAccessScope::query()
            ->effective()
            ->where('user_id', $subject->getKey())
            ->update([
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->getKey(),
                'revocation_reason' => 'quarterly_access_review',
                'updated_at' => now(),
            ]);

        $subject->unsetRelation('roles')->unsetRelation('permissions');
        if ($this->hasReviewedAccess($subject->fresh())) {
            throw new GovernanceViolation('revocation_incomplete', 'Reviewed access remains after remediation; the decision was rolled back.');
        }

        $sessionResult = $this->sessions->revoke($subject, $request, 'quarterly_access_review');

        return [
            'scalar_role' => ['from' => $oldScalarRole, 'to' => $newScalarRole],
            'spatie_roles_removed' => collect($removedRoles)->sort()->values()->all(),
            'direct_permissions_removed' => collect($removedPermissions)->sort()->values()->all(),
            'explicit_scopes_revoked' => $scopeCount,
            'api_tokens_revoked' => $sessionResult['api_tokens_revoked'],
            'database_sessions_revoked' => $sessionResult['database_sessions_revoked'],
            'auth_session_version' => $sessionResult['session_version'],
        ];
    }

    /** @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = array_is_list($item)
                    ? array_map(fn (mixed $child): mixed => is_array($child) ? $this->canonicalize($child) : $child, $item)
                    : $this->canonicalize($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /** @param array<string, mixed> $value
     * @throws JsonException
     */
    private function canonicalJson(array $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function safeCsvCell(mixed $value): string
    {
        $cell = str_replace(["\r\n", "\r", "\n"], ' ', (string) $value);

        return preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'".$cell : $cell;
    }
}
