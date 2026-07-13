<?php

namespace App\Providers;

use App\Auth\AuthDriverRegistry;
use App\Auth\Drivers\AuthentikOidcAuthDriver;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use App\Security\ClinicalPayloads\ClinicalPayloadException;
use App\Security\ClinicalPayloads\ClinicalPayloadSafeQueueJob;
use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use App\Security\ClinicalPayloads\ClinicalSafeLogManager;
use App\Security\ClinicalPayloads\EncryptedClinicalPayloadStore;
use App\Security\Secrets\Providers\AwsSecretsManagerProvider;
use App\Security\Secrets\Providers\AzureKeyVaultProvider;
use App\Security\Secrets\Providers\FileSecretProvider;
use App\Security\Secrets\Providers\GcpSecretManagerProvider;
use App\Security\Secrets\Providers\VaultSecretProvider;
use App\Security\Secrets\SecretProviderRegistry;
use App\Services\Auth\Oidc\ExternalIdentityEventRecorder;
use App\Services\Auth\Oidc\OidcDiscoveryService;
use App\Services\Auth\Oidc\OidcHandshakeStore;
use App\Services\Auth\Oidc\OidcHttpClient;
use App\Services\Auth\Oidc\OidcProviderConfig;
use App\Services\Auth\Oidc\OidcReconciliationService;
use App\Services\Auth\Oidc\OidcTokenValidator;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('log', fn ($app) => new ClinicalSafeLogManager($app));

        $this->app->singleton(\App\Services\Authorization\RoleCapabilityService::class);

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
            $app->make(OidcProviderConfig::class)->discoveryUrl(),
            $app->make(OidcHttpClient::class),
            $app->make(\App\Security\Network\OidcUrlPolicy::class),
        ));

        $this->app->bind(OidcTokenValidator::class, fn ($app) => new OidcTokenValidator(
            $app->make(OidcDiscoveryService::class),
            $app->make(OidcProviderConfig::class)->clientId()
        ));

        $this->app->bind(OidcReconciliationService::class, fn ($app) => new OidcReconciliationService(
            $app->make(OidcProviderConfig::class)->allowedGroups(),
            $app->make(OidcProviderConfig::class)->adminGroups(),
            $app->make(ExternalIdentityEventRecorder::class),
        ));

        $this->app->singleton(OidcHandshakeStore::class);

        $this->app->singleton(SecretProviderRegistry::class, fn ($app) => new SecretProviderRegistry([
            $app->make(FileSecretProvider::class),
            $app->make(VaultSecretProvider::class),
            $app->make(AwsSecretsManagerProvider::class),
            $app->make(GcpSecretManagerProvider::class),
            $app->make(AzureKeyVaultProvider::class),
        ]));

        $this->app->singleton(ClinicalPayloadStore::class, EncryptedClinicalPayloadStore::class);

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
        if ($this->app->environment('production')) {
            $this->app->make(\App\Services\Auth\ProductionSessionConfiguration::class)->assertSecure();
        }

        Queue::createPayloadUsing(function (string $connection, ?string $queue, array $payload): array {
            $guard = $this->app->make(ClinicalContentGuard::class);
            $guard->assertSafe($payload, 'clinical_payload_queue_payload_rejected');

            if (($queue ?? 'default') !== 'integrations') {
                return [];
            }

            $job = data_get($payload, 'data.commandName');
            if (! $job instanceof ClinicalPayloadSafeQueueJob) {
                throw new ClinicalPayloadException('clinical_payload_queue_contract_invalid');
            }
            if (! $job instanceof ShouldBeEncrypted) {
                throw new ClinicalPayloadException('clinical_payload_queue_encryption_required');
            }

            $guard->assertQueueJob($job);

            return [
                'zephyrus:clinical-content-safety' => [
                    'schema' => 1,
                    'encryptedCommand' => true,
                ],
            ];
        });

        Vite::prefetch(concurrency: 3);
    }
}
