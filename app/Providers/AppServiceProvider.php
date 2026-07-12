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

        // Part X (X4) — the Arena copilot's LLM seam. Bound to the Eddy-proxy driver,
        // which is inert (isLive()=false, generate()=null) unless BOTH EDDY_ENABLED
        // and ARENA_AI_ENABLED are on — so the copilot runs fully deterministic by
        // default and in tests, with the LLM as a pure enhancement when switched on.
        $this->app->bind(
            \App\Domain\Arena\Copilot\CopilotLlm::class,
            \App\Domain\Arena\Copilot\EddyProxyCopilotLlm::class,
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

        $this->app->singleton(
            \App\Integrations\Healthcare\Services\ProjectionDispatcher::class,
            fn ($app) => new \App\Integrations\Healthcare\Services\ProjectionDispatcher([
                $app->make(\App\Integrations\Healthcare\Services\RtdcProjectionHandler::class),
                $app->make(\App\Integrations\Healthcare\Services\AncillaryProjectionHandler::class),
            ]),
        );
        $this->app->alias(
            \App\Integrations\Healthcare\Services\ProjectionDispatcher::class,
            \App\Integrations\Healthcare\Contracts\ProjectionHandler::class,
        );
        $this->app->singleton(
            \App\Integrations\Healthcare\Services\AncillaryNormalizerRegistry::class,
            fn ($app) => new \App\Integrations\Healthcare\Services\AncillaryNormalizerRegistry([
                $app->make(\App\Integrations\Healthcare\Ancillary\RadiologyOrderHl7V2Normalizer::class),
                $app->make(\App\Integrations\Healthcare\Ancillary\RadiologyResultHl7V2Normalizer::class),
                $app->make(\App\Integrations\Healthcare\Ancillary\RadiologyOrderFhirNormalizer::class),
                $app->make(\App\Integrations\Healthcare\Ancillary\RadiologyResultFhirNormalizer::class),
                $app->make(\App\Integrations\Healthcare\Ancillary\RadiologyOperationalEventNormalizer::class),
                $app->make(\App\Integrations\Healthcare\Ancillary\LabResultHl7V2Normalizer::class),
                $app->make(\App\Integrations\Healthcare\Ancillary\LabResultFhirNormalizer::class),
                $app->make(\App\Integrations\Healthcare\Ancillary\LabOrderHl7V2Normalizer::class),
                $app->make(\App\Integrations\Healthcare\Ancillary\LabOrderFhirNormalizer::class),
                $app->make(\App\Integrations\Healthcare\Ancillary\AncillaryHl7V2MessageNormalizer::class),
                $app->make(\App\Integrations\Healthcare\Ancillary\AncillaryStructuredMessageNormalizer::class),
                $app->make(\App\Integrations\Healthcare\Ancillary\UnsupportedAncillaryMessageNormalizer::class),
            ]),
        );
        $this->app->bind(
            \App\Integrations\Healthcare\Contracts\BulkBackfillAdapter::class,
            \App\Integrations\Healthcare\Services\AncillaryBulkBackfillAdapter::class,
        );
        $this->app->singleton(
            \App\Services\Demo\Ancillary\AncillaryDemoScenarioService::class,
            fn ($app) => new \App\Services\Demo\Ancillary\AncillaryDemoScenarioService([
                $app->make(\App\Services\Demo\Ancillary\RadiologyDemoGenerator::class),
                $app->make(\App\Services\Demo\Ancillary\LabDemoGenerator::class),
                $app->make(\App\Services\Demo\Ancillary\PharmacyDemoGenerator::class),
            ]),
        );

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
