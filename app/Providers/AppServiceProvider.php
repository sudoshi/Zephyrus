<?php

namespace App\Providers;

use App\Auth\AuthDriverRegistry;
use App\Auth\Drivers\AuthentikOidcAuthDriver;
use App\Services\Auth\Oidc\OidcDiscoveryService;
use App\Services\Auth\Oidc\OidcHandshakeStore;
use App\Services\Auth\Oidc\OidcProviderConfig;
use App\Services\Auth\Oidc\OidcReconciliationService;
use App\Services\Auth\Oidc\OidcTokenValidator;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Rtdc\Optimizer\Contracts\BedAssignmentOptimizer::class,
            \App\Rtdc\Optimizer\HeuristicBedAssignmentOptimizer::class,
        );

        $this->app->singleton(OidcProviderConfig::class);

        $this->app->bind(OidcDiscoveryService::class, fn ($app) => new OidcDiscoveryService(
            $app->make(OidcProviderConfig::class)->discoveryUrl()
        ));

        $this->app->bind(OidcTokenValidator::class, fn ($app) => new OidcTokenValidator(
            $app->make(OidcDiscoveryService::class),
            $app->make(OidcProviderConfig::class)->clientId()
        ));

        $this->app->bind(OidcReconciliationService::class, fn ($app) => new OidcReconciliationService(
            $app->make(OidcProviderConfig::class)->allowedGroups(),
            $app->make(OidcProviderConfig::class)->adminGroups()
        ));

        $this->app->singleton(OidcHandshakeStore::class);

        $this->app->singleton(AuthDriverRegistry::class, function ($app) {
            $registry = new AuthDriverRegistry;
            $registry->register($app->make(AuthentikOidcAuthDriver::class));

            return $registry;
        });

        // P6: the alert fan-out lanes. Both are inert by default (push gated
        // by EDDY_PUSH_ENABLED, Teams by TEAMS_ALERT_WEBHOOK_URL) — adding a
        // lane means adding an AlertChannel here, not touching the engine.
        $this->app->singleton(\App\Services\Cockpit\AlertFanout::class, fn ($app) => new \App\Services\Cockpit\AlertFanout([
            $app->make(\App\Services\Cockpit\Channels\PushAlertChannel::class),
            $app->make(\App\Services\Cockpit\Channels\TeamsAlertChannel::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
