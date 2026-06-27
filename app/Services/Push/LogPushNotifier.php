<?php

namespace App\Services\Push;

use App\Contracts\PushNotifier;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Phase 0 stub implementation of {@see PushNotifier}: resolves the user's active
 * devices and logs the dispatch instead of calling APNs/FCM. This proves the
 * seam end-to-end (device registry -> notifier) without external dependencies.
 * Phase 1 replaces the binding with real APNs (HTTP/2 .p8) + FCM v1 senders.
 */
class LogPushNotifier implements PushNotifier
{
    public function sendToUser(User $user, string $title, string $body, array $data = []): int
    {
        $devices = $user->mobileDevices()->whereNull('revoked_at')->get();

        foreach ($devices as $device) {
            // PHI guard: title/body are expected to be generic per the taxonomy.
            // We log only the title and the data *keys* (never values), never the body.
            Log::info('hummingbird.push.dispatch', [
                'user_id' => $user->getKey(),
                'device_id' => $device->mobile_device_id,
                'platform' => $device->platform,
                'title' => $title,
                'data_keys' => array_keys($data),
            ]);
        }

        return $devices->count();
    }
}
