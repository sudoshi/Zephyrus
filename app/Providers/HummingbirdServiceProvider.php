<?php

namespace App\Providers;

use App\Contracts\PushNotifier;
use App\Events\Rtdc\BedsChanged;
use App\Models\Bed;
use App\Services\Push\LogPushNotifier;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Hummingbird mobile-companion backend (Phase 0 foundation).
 *
 * Everything here is additive to the existing application. The Phase 0 binding
 * for {@see PushNotifier} is the log-only stub; swap the concrete in Phase 1 for
 * the real APNs/FCM sender without touching call sites.
 */
class HummingbirdServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PushNotifier::class, LogPushNotifier::class);
    }

    public function boot(): void
    {
        // Push a PHI-free "beds changed" signal to mobile clients whenever a bed's status
        // changes, so the Hummingbird apps re-snapshot the census in real time over Reverb.
        // (In the test env BROADCAST_CONNECTION=null makes this a no-op.)
        Bed::updated(function (Bed $bed): void {
            if ($bed->wasChanged('status')) {
                broadcast(new BedsChanged($bed->unit_id));
            }
        });
    }
}
