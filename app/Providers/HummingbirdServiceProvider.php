<?php

namespace App\Providers;

use App\Contracts\PushNotifier;
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
        //
    }
}
