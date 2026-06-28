<?php

namespace App\Services\Eddy;

use App\Models\User;
use App\Services\Ops\Agents\AgentToolRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Assembles the PHI-free LIVE OPERATIONS snapshot Eddy is given on every turn —
 * the process-awareness muscle. Reuses the existing AgentToolRegistry read tools
 * (capacity.snapshot, executive_brief.compose) so there is ONE source of truth
 * for the operational picture; the dashboards and Eddy read the same numbers.
 *
 * Eddy is stateless and holds no DB creds — Laravel computes this and sends it in
 * the chat envelope. Fail-open: any error yields an empty context, never a broken
 * chat. Output is aggregate-only and run through redact() as defense in depth.
 */
class EddyContextService
{
    /** Surfaces that warrant the cross-domain executive situation block. */
    private const HOUSE_WIDE_SURFACES = ['chat', 'command_center', 'improvement'];

    public function __construct(private readonly AgentToolRegistry $registry) {}

    /**
     * @return array<string, mixed> empty when unavailable
     */
    public function forSurface(User $user, string $surface): array
    {
        try {
            $snapshot = $this->registry->call('capacity.snapshot', [], $user);
        } catch (\Throwable $e) {
            Log::info('eddy.context.capacity_unavailable', ['error' => $e->getMessage()]);

            return [];
        }

        $summary = $snapshot['summary'] ?? [];
        $context = [
            'generated_at' => $snapshot['generatedAtIso'] ?? null,
            'overall_status' => $snapshot['status'] ?? 'success',
            'capacity' => [
                'staffed_beds' => $summary['staffedBeds'] ?? null,
                'occupied_beds' => $summary['occupiedBeds'] ?? null,
                'available_beds' => $summary['availableBeds'] ?? null,
                'blocked_beds' => $summary['blockedBeds'] ?? null,
                'pending_admits' => $summary['pendingAdmits'] ?? null,
                'ed_boarders' => $summary['edBoarders'] ?? null,
                'transport_at_risk' => $summary['transportAtRisk'] ?? null,
                'net_beds' => $summary['netBeds'] ?? null,
                'risk_score' => $summary['riskScore'] ?? null,
            ],
            'source_freshness' => [
                'census_lag_minutes' => $summary['censusLagMinutes'] ?? null,
                'status' => $summary['sourceFreshnessStatus'] ?? 'warning',
            ],
            'findings' => array_map(
                static fn (array $f): array => [
                    'key' => $f['key'] ?? '',
                    'status' => $f['status'] ?? 'info',
                    'detail' => $f['detail'] ?? '',
                ],
                $snapshot['findings'] ?? [],
            ),
        ];

        if (in_array($surface, self::HOUSE_WIDE_SURFACES, true)) {
            $context = array_merge($context, $this->houseWide($user));
        }

        // Belt-and-suspenders: strip any PHI-shaped keys before egress.
        return (array) $this->registry->redact($context);
    }

    /**
     * @return array<string, mixed>
     */
    private function houseWide(User $user): array
    {
        try {
            $brief = $this->registry->call('executive_brief.compose', [], $user);
        } catch (\Throwable $e) {
            return [];
        }

        $plan = $brief['recommendedPlan'] ?? [];

        return [
            'headline' => $brief['headline'] ?? null,
            'situation' => array_map(
                static fn (array $s): array => [
                    'domain' => $s['domain'] ?? '',
                    'status' => $s['status'] ?? 'info',
                    'detail' => $s['detail'] ?? '',
                ],
                $brief['situation'] ?? [],
            ),
            'governance' => [
                'pending_approvals' => $plan['pendingApprovals'] ?? 0,
                'draft_actions' => $plan['draftActions'] ?? 0,
                'open_recommendations' => $plan['openRecommendations'] ?? 0,
            ],
        ];
    }
}
