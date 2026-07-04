<?php

namespace App\Services\Cockpit\Channels;

use App\Contracts\AlertChannel;
use App\Contracts\PushNotifier;
use App\Models\Cockpit\CockpitAlert;
use App\Models\User;

/**
 * Mobile paging for opened cockpit alerts through the existing Hummingbird
 * Push contract. Follows the doorbell-not-letter doctrine: generic copy +
 * identifiers only — the app fetches the live cockpit on open.
 *
 * Gated by EDDY_PUSH_ENABLED (eddy.push.enabled) exactly like the Eddy
 * approval doorbell — inert until mobile push is provisioned, and the plan
 * forbids presenting mobile paging as live before then. Recipient routing is
 * deliberately narrow until a subscription policy exists: active admins only.
 */
class PushAlertChannel implements AlertChannel
{
    public function __construct(private readonly PushNotifier $push) {}

    public function send(CockpitAlert $alert): int
    {
        if (! (bool) config('eddy.push.enabled')) {
            return 0;
        }

        $recipients = User::query()
            ->where('is_active', true)
            ->role(['super-admin', 'admin'])
            ->get();

        $dispatched = 0;

        foreach ($recipients as $recipient) {
            $dispatched += $this->push->sendToUser(
                $recipient,
                'Zephyrus capacity alert',
                $alert->status === 'crit'
                    ? 'A critical operational signal needs attention.'
                    : 'An operational signal crossed its warning band.',
                // PHI-FREE: key + tier + facility + deep link only.
                [
                    'kind' => 'cockpit_alert',
                    'key' => $alert->key,
                    'status' => $alert->status,
                    'facility_key' => $alert->facility_key,
                    'deep_link' => '/dashboard',
                ],
            );
        }

        return $dispatched;
    }
}
