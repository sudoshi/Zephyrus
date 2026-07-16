<?php

namespace App\Services\Admin;

use App\Authorization\AdminScope;
use App\Authorization\Capability;
use App\Authorization\GovernedAction;
use App\Models\Auth\UserAccessScope;
use App\Models\User;
use App\Services\Authorization\RoleCapabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Small, read-only roll-up for the Admin landing page. Domain consoles remain
 * authoritative; this service returns counts and deep links, never duplicated
 * rows, secret material, patient data, or mutation controls.
 */
final class AdminReadinessService
{
    public function __construct(
        private readonly SystemHealthService $health,
        private readonly RoleCapabilityService $authorization,
    ) {}

    /** @param array<string, int|null> $identityMetrics @return array<string, mixed> */
    public function for(User $user, array $identityMetrics, ?AdminScope $activeScope = null): array
    {
        $canViewIdentity = $this->authorization->allows($user, Capability::ViewIdentity);
        $canViewAudit = $this->authorization->allows($user, Capability::ViewAudit);
        $canViewHealth = $this->authorization->allows($user, Capability::ViewSystemHealth);
        $canViewReviews = $this->authorization->allows($user, Capability::ViewAccessReviews);
        $canViewIntegrations = $this->authorization->allows($user, Capability::ViewIntegrations);
        $canApproveIntegrations = $this->authorization->allows($user, Capability::ApproveIntegrationChanges);
        $canManagePrivileges = $this->authorization->allows($user, Capability::ManagePrivileges);
        $health = $canViewHealth ? $this->health->snapshot() : null;

        $openReviewItems = $canViewReviews ? $this->openAccessReviewItems() : null;
        $overdueCampaigns = $canViewReviews ? $this->overdueAccessReviewCampaigns() : null;
        $integration = $canViewIntegrations ? $this->integrationCounts() : $this->restrictedIntegrationCounts();
        $pendingIntegrationApprovals = $canApproveIntegrations
            ? $this->pendingGovernedChanges(array_values(array_filter(
                array_map(
                    fn (GovernedAction $action): ?string => $action === GovernedAction::PurgeUserIdentity ? null : $action->value,
                    GovernedAction::cases(),
                )
            )))
            : null;
        $pendingIdentityApprovals = $canManagePrivileges
            ? $this->pendingGovernedChanges([GovernedAction::PurgeUserIdentity->value])
            : null;

        $healthAttention = $health !== null ? (int) $health['counts']['requiredAttention'] : null;
        $integrationExceptions = $integration['openDeadLetters'] !== null
            ? (int) $integration['openDeadLetters'] + (int) $integration['openProjectionErrors']
            : null;

        $readinessMetrics = [
            [
                'label' => 'Identity',
                'value' => $canViewIdentity
                    ? sprintf('%d / %d', $identityMetrics['activeUsers'], $identityMetrics['totalUsers'])
                    : 'Restricted',
                'detail' => $canViewIdentity ? 'active / total users' : 'viewIdentity capability required',
                'tone' => 'default',
            ],
            [
                'label' => 'Credential action',
                'value' => $canViewIdentity ? $identityMetrics['mustChangePassword'] : 'Restricted',
                'detail' => $canViewIdentity ? 'password changes due' : 'viewIdentity capability required',
                'tone' => $canViewIdentity && $identityMetrics['mustChangePassword'] > 0 ? 'warning' : 'default',
            ],
            [
                'label' => 'System health',
                'value' => $canViewHealth ? ucfirst((string) $health['overallStatus']) : 'Restricted',
                'detail' => $canViewHealth
                    ? $healthAttention.' required item'.($healthAttention === 1 ? '' : 's').' need attention'
                    : 'viewSystemHealth capability required',
                'tone' => $canViewHealth && $health['overallStatus'] === 'critical'
                    ? 'critical'
                    : ($canViewHealth && $healthAttention > 0 ? 'warning' : 'default'),
            ],
            [
                'label' => 'Access certification',
                'value' => $openReviewItems ?? 'Restricted',
                'detail' => $openReviewItems === null ? 'capability required' : 'open review items',
                'tone' => ($openReviewItems ?? 0) > 0 ? 'warning' : 'default',
            ],
            [
                'label' => 'Integration exceptions',
                'value' => $integrationExceptions ?? 'Restricted',
                'detail' => $integrationExceptions === null ? 'separately governed' : 'dead letters + projection errors',
                'tone' => ($integrationExceptions ?? 0) > 0 ? 'critical' : 'default',
            ],
            [
                'label' => 'Mapping review',
                'value' => $integration['pendingMappings'] ?? 'Restricted',
                'detail' => $integration['pendingMappings'] === null ? 'separately governed' : 'terminology maps pending',
                'tone' => ($integration['pendingMappings'] ?? 0) > 0 ? 'warning' : 'default',
            ],
            [
                'label' => 'Governed approvals',
                'value' => ($pendingIntegrationApprovals ?? 0) + ($pendingIdentityApprovals ?? 0),
                'detail' => ($pendingIntegrationApprovals === null && $pendingIdentityApprovals === null) ? 'no approval capability' : 'awaiting authorized decision',
                'tone' => (($pendingIntegrationApprovals ?? 0) + ($pendingIdentityApprovals ?? 0)) > 0 ? 'warning' : 'default',
            ],
        ];

        $actions = collect();
        if ($canViewIdentity && $identityMetrics['mustChangePassword'] > 0) {
            $actions->push($this->action('credential_rotation', 'warning', 'Credential changes are due', $identityMetrics['mustChangePassword'], 'User accounts require a password change before normal access.', '/users', 'Identity Administration'));
        }
        if ($canViewAudit && $identityMetrics['failedLoginsToday'] > 0) {
            $actions->push($this->action('failed_authentication', 'warning', 'Review failed authentication', $identityMetrics['failedLoginsToday'], 'Failed or denied authentication events were recorded today.', '/admin/user-audit?category=authentication', 'Security Operations'));
        }
        if (($overdueCampaigns ?? 0) > 0) {
            $actions->push($this->action('overdue_access_reviews', 'critical', 'Complete overdue access reviews', $overdueCampaigns, 'One or more open access-review campaigns are past due.', '/admin/access-reviews?status=open', 'Access Governance'));
        } elseif (($openReviewItems ?? 0) > 0) {
            $actions->push($this->action('open_access_review_items', 'warning', 'Certify privileged access', $openReviewItems, 'Frozen access-review items are awaiting independent decisions.', '/admin/access-reviews', 'Access Governance'));
        }
        if (($integration['openDeadLetters'] ?? 0) > 0) {
            $actions->push($this->action('integration_dead_letters', 'critical', 'Resolve integration dead letters', $integration['openDeadLetters'], 'Inbound healthcare transactions are quarantined for operator review.', '/integrations?tab=dead-letters', 'Integration Operations'));
        }
        if (($integration['openProjectionErrors'] ?? 0) > 0) {
            $actions->push($this->action('integration_projection_errors', 'critical', 'Resolve projection errors', $integration['openProjectionErrors'], 'Canonical events have open projection failures.', '/integrations?tab=dead-letters', 'Integration Operations'));
        }
        if (($integration['pendingMappings'] ?? 0) > 0) {
            $actions->push($this->action('terminology_mapping_review', 'warning', 'Review terminology mappings', $integration['pendingMappings'], 'Source-to-canonical mappings are awaiting stewardship.', '/integrations?tab=mappings', 'Data Stewardship'));
        }
        if (($integration['expiringCredentials'] ?? 0) > 0) {
            $actions->push($this->action('integration_credential_expiry', 'warning', 'Rotate integration credentials', $integration['expiringCredentials'], 'Active credential references enter their renewal window within 30 days.', '/integrations?tab=credentials', 'Integration Security'));
        }
        if (($pendingIntegrationApprovals ?? 0) > 0) {
            $actions->push($this->action('integration_governed_approvals', 'warning', 'Decide governed integration changes', $pendingIntegrationApprovals, 'High-risk integration changes await an independent approver.', '/integrations?tab=audit&governance=pending', 'Integration Governance'));
        }
        if (($pendingIdentityApprovals ?? 0) > 0) {
            $actions->push($this->action('identity_governed_approvals', 'warning', 'Decide identity purge requests', $pendingIdentityApprovals, 'Retention-aware identity purge requests await an independent approver.', '/users', 'Identity Governance'));
        }
        if ($canViewHealth && $healthAttention > 0) {
            $actions->push($this->action('system_health_attention', $health['overallStatus'] === 'critical' ? 'critical' : 'warning', 'Resolve platform readiness evidence', $healthAttention, 'Required health components are critical, degraded, disabled, stale, or unobserved.', '/admin/system-health?status=attention', 'Platform Operations'));
        }

        $actions = $actions
            ->sortBy(fn (array $item): string => sprintf('%d-%s', match ($item['severity']) {
                'critical' => 0,
                'warning' => 1,
                default => 2,
            }, $item['title']))
            ->values()
            ->all();

        return [
            'health' => [
                'visible' => $canViewHealth,
                'overallStatus' => $canViewHealth ? $health['overallStatus'] : 'restricted',
                'counts' => $canViewHealth ? $health['counts'] : [],
                'lastScheduledAt' => $canViewHealth ? $health['lastScheduledAt'] : null,
            ],
            'integration' => $integration,
            'governance' => [
                'openAccessReviewItems' => $openReviewItems,
                'overdueAccessReviewCampaigns' => $overdueCampaigns,
                'pendingIntegrationApprovals' => $pendingIntegrationApprovals,
                'pendingIdentityApprovals' => $pendingIdentityApprovals,
            ],
            'readinessMetrics' => $readinessMetrics,
            'scopeIndicators' => $this->scopeIndicators($user, $canViewIntegrations, $activeScope),
            'actionQueue' => $actions,
        ];
    }

    private function openAccessReviewItems(): int
    {
        return $this->safeCount(fn (): int => DB::table('governance.access_review_items as item')
            ->join('governance.access_review_campaigns as campaign', 'campaign.id', '=', 'item.campaign_id')
            ->leftJoin('governance.access_review_decisions as decision', 'decision.campaign_item_id', '=', 'item.id')
            ->where('campaign.status', 'open')
            ->whereNull('decision.id')
            ->count()) ?? 0;
    }

    private function overdueAccessReviewCampaigns(): int
    {
        return $this->safeCount(fn (): int => DB::table('governance.access_review_campaigns')
            ->where('status', 'open')
            ->where('due_at', '<', now())
            ->count()) ?? 0;
    }

    /** @param list<string> $actions */
    private function pendingGovernedChanges(array $actions): int
    {
        if ($actions === []) {
            return 0;
        }

        return $this->safeCount(fn (): int => DB::table('governance.change_requests as request')
            ->leftJoin('governance.change_decisions as decision', 'decision.change_request_uuid', '=', 'request.change_request_uuid')
            ->whereIn('request.action_type', $actions)
            ->where('request.expires_at', '>', now())
            ->whereNull('decision.change_decision_id')
            ->count()) ?? 0;
    }

    /** @return array<string, int|null> */
    private function integrationCounts(): array
    {
        return [
            'sources' => $this->tableCount('integration.sources'),
            'activeSources' => $this->tableCount('integration.sources', fn ($query) => $query->where('active_status', 'active')),
            'tenantScopes' => $this->distinctCount('integration.sources', 'tenant_key'),
            'facilityScopes' => $this->distinctCount('integration.sources', 'facility_key'),
            'openDeadLetters' => $this->tableCount('raw.dead_letters', fn ($query) => $query->where('status', 'open')),
            'openProjectionErrors' => $this->tableCount('integration.event_projection_errors', fn ($query) => $query->where('status', 'open')),
            'pendingMappings' => $this->tableCount('integration.terminology_maps', fn ($query) => $query->where('review_status', '!=', 'approved')),
            'expiringCredentials' => $this->tableCount('integration.source_credentials', fn ($query) => $query
                ->where('is_active', true)
                ->whereNotNull('rotates_at')
                ->where('rotates_at', '<=', now()->addDays(30))),
        ];
    }

    /** @return array<string, null> */
    private function restrictedIntegrationCounts(): array
    {
        return array_fill_keys([
            'sources', 'activeSources', 'tenantScopes', 'facilityScopes', 'openDeadLetters',
            'openProjectionErrors', 'pendingMappings', 'expiringCredentials',
        ], null);
    }

    /** @return list<array<string, mixed>> */
    private function scopeIndicators(User $user, bool $canViewIntegrations, ?AdminScope $activeScope): array
    {
        $roles = $this->authorization->effectiveRoleIds($user);
        $global = collect($roles)->intersect(config('authorization.global_scope_roles', []))->isNotEmpty();
        $organizationScopes = 0;
        $facilityScopes = 0;
        if (! $global) {
            try {
                $scopes = UserAccessScope::query()->effective()->where('user_id', $user->getKey());
                $organizationScopes = (clone $scopes)->distinct('organization_id')->count('organization_id');
                $facilityScopes = (clone $scopes)->whereNotNull('facility_id')->distinct('facility_id')->count('facility_id');
            } catch (Throwable) {
                // Scope errors remain zero/unknown and never imply wider access.
            }
        }

        return [
            [
                'key' => 'principal',
                'label' => 'Principal boundary',
                'value' => $global ? 'Global' : 'Scoped',
                'detail' => $global ? 'Granted only by a configured global-scope role.' : 'Requires effective organization or facility grants.',
                'status' => $global ? 'warning' : 'ready',
            ],
            [
                'key' => 'organizations',
                'label' => 'Active organization',
                'value' => $activeScope?->organizationName ?? 'Not selected',
                'detail' => $activeScope?->organizationKey
                    ?? ($global ? 'Explicit selection is still required for scoped mutations.' : $organizationScopes.' organization grant(s) available.'),
                'status' => $activeScope ? 'ready' : 'warning',
            ],
            [
                'key' => 'facilities',
                'label' => 'Active facility',
                'value' => $activeScope?->facilityName ?? 'Not selected',
                'detail' => $activeScope?->facilityKey
                    ?? ($global ? 'Required for facility and source mutations.' : $facilityScopes.' facility grant(s) available.'),
                'status' => $activeScope?->facilityId ? 'ready' : 'warning',
            ],
            [
                'key' => 'sources',
                'label' => 'Active source',
                'value' => $canViewIntegrations ? ($activeScope?->sourceName ?? 'Not selected') : 'Restricted',
                'detail' => $canViewIntegrations
                    ? ($activeScope?->sourceKey ?? 'Required for integration-source mutations; read summaries remain capability-wide.')
                    : 'viewIntegrations capability required',
                'status' => ! $canViewIntegrations ? 'restricted' : ($activeScope?->sourceId ? 'ready' : 'warning'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function action(string $key, string $severity, string $title, int $count, string $detail, string $href, string $owner): array
    {
        return compact('key', 'severity', 'title', 'count', 'detail', 'href', 'owner');
    }

    private function safeCount(callable $callback): ?int
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }

    private function tableCount(string $table, ?callable $scope = null): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        return $this->safeCount(function () use ($table, $scope): int {
            $query = DB::table($table);

            return ($scope ? $scope($query) : $query)->count();
        });
    }

    private function distinctCount(string $table, string $column): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        return $this->safeCount(fn (): int => DB::table($table)->whereNotNull($column)->distinct()->count($column));
    }
}
