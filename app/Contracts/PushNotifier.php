<?php

namespace App\Contracts;

use App\Models\User;

/**
 * Delivers push notifications to a user's registered Hummingbird devices.
 *
 * Implementations MUST keep payloads PHI-free (generic copy + ids/deep-links
 * only) per the earned-urgency notification taxonomy. The Phase 0 binding is a
 * log-only stub; real APNs/FCM senders are introduced in Phase 1.
 */
interface PushNotifier
{
    /**
     * Deliver a PHI-free push to all of a user's active (non-revoked) devices.
     *
     * @param  array<string, mixed>  $data  PHI-free data payload — ids, deep-link,
     *                                      action category, tier. No patient/clinical detail.
     * @return int the number of devices the push was dispatched to
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): int;
}
