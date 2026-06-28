<?php

namespace App\Services\Eddy;

use App\Contracts\PushNotifier;
use App\Models\Ops\Approval;
use App\Models\User;

/**
 * The Hummingbird "Eddy has a suggestion" doorbell.
 *
 * When an Eddy proposal lands pending (it never auto-executes), this rings a
 * PHI-FREE push to the approver's registered devices. The payload carries ONLY
 * identifiers + a server-derived tier + a deep link — never the action params,
 * rationale, runner-up, or any patient/clinical detail. The native app fetches
 * the real dry-run on open over the biometric-gated token (fetch-on-open; the
 * push is a doorbell, not a letter).
 *
 * The tier is derived here, server-side, from the action catalog's risk level so
 * the mobile client stays presentation-only and the "earned urgency" canon holds:
 * Tier-1 (iOS Critical Alert) is reserved for genuine capacity breaches.
 */
class EddyApprovalNotifier
{
    public function __construct(private readonly PushNotifier $push) {}

    /**
     * Ring the doorbell for a pending Eddy approval. No-op (returns 0) when push
     * is disabled, the approval is not pending, or it was not Eddy-sourced.
     *
     * @return int the number of devices the doorbell was dispatched to
     */
    public function notifyApprover(Approval $approval, User $approver): int
    {
        if (! (bool) config('eddy.push.enabled')) {
            return 0;
        }

        if (($approval->status ?? null) !== 'pending') {
            return 0;
        }

        $action = $approval->action;
        $recommendation = $action?->recommendation;

        // Only ring for Eddy-sourced proposals — never hijack another producer's approvals.
        if (($recommendation->created_by_source ?? null) !== 'eddy') {
            return 0;
        }

        $actionType = (string) ($action->action_type ?? '');
        $risk = (string) ($recommendation->risk_level ?? 'low');
        $surface = (string) ($recommendation->scope_type ?? 'house');

        return $this->push->sendToUser(
            $approver,
            'Eddy has a suggestion',
            $this->copyForTier($this->tierForRisk($risk)),
            // PHI-FREE: ids + tier + surface + deep link only. No params/rationale.
            [
                'kind' => 'eddy_approval',
                'approval_uuid' => (string) $approval->approval_uuid,
                'action_uuid' => (string) ($action->action_uuid ?? ''),
                'action_type' => $actionType,
                'surface' => $surface,
                'tier' => $this->tierForRisk($risk),
                'deep_link' => (string) config('eddy.push.deep_link'),
            ],
        );
    }

    /**
     * Map the catalog risk level to a notification tier. Unknown risk → tier_3
     * (never escalate on uncertainty — that would erode earned urgency).
     */
    public function tierForRisk(string $risk): string
    {
        $map = (array) config('eddy.push.tier_by_risk', []);

        return (string) ($map[$risk] ?? 'tier_3');
    }

    /** Generic, PHI-free body copy keyed to the tier. */
    private function copyForTier(string $tier): string
    {
        return match ($tier) {
            'tier_1' => 'A capacity action needs your review.',
            'tier_2' => 'A suggested action is waiting for approval.',
            default => 'Eddy proposed an action when you have a moment.',
        };
    }
}
