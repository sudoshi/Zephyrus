<?php

namespace App\Providers;

use App\Auth\AuthDriverRegistry;
use App\Auth\Drivers\AuthentikOidcAuthDriver;
use App\Database\ProductionDatabaseReadOnlyGuard;
use App\Domain\Arena\Copilot\CopilotLlm;
use App\Domain\Arena\Copilot\EddyProxyCopilotLlm;
use App\Integrations\Healthcare\Ancillary\AncillaryHl7V2MessageNormalizer;
use App\Integrations\Healthcare\Ancillary\AncillaryStructuredMessageNormalizer;
use App\Integrations\Healthcare\Ancillary\LabOrderFhirNormalizer;
use App\Integrations\Healthcare\Ancillary\LabOrderHl7V2Normalizer;
use App\Integrations\Healthcare\Ancillary\LabResultFhirNormalizer;
use App\Integrations\Healthcare\Ancillary\LabResultHl7V2Normalizer;
use App\Integrations\Healthcare\Ancillary\PharmacyAdcTransactionNormalizer;
use App\Integrations\Healthcare\Ancillary\PharmacyAdministrationImportNormalizer;
use App\Integrations\Healthcare\Ancillary\PharmacyOrderFhirNormalizer;
use App\Integrations\Healthcare\Ancillary\PharmacyOrderHl7V2Normalizer;
use App\Integrations\Healthcare\Ancillary\PharmacyVerificationQueueNormalizer;
use App\Integrations\Healthcare\Ancillary\RadiologyOperationalEventNormalizer;
use App\Integrations\Healthcare\Ancillary\RadiologyOrderFhirNormalizer;
use App\Integrations\Healthcare\Ancillary\RadiologyOrderHl7V2Normalizer;
use App\Integrations\Healthcare\Ancillary\RadiologyResultFhirNormalizer;
use App\Integrations\Healthcare\Ancillary\RadiologyResultHl7V2Normalizer;
use App\Integrations\Healthcare\Ancillary\UnsupportedAncillaryMessageNormalizer;
use App\Integrations\Healthcare\Contracts\BulkBackfillAdapter;
use App\Integrations\Healthcare\Contracts\ProjectionHandler;
use App\Integrations\Healthcare\Services\AdcStationEventProjectionHandler;
use App\Integrations\Healthcare\Services\AncillaryBulkBackfillAdapter;
use App\Integrations\Healthcare\Services\AncillaryNormalizerRegistry;
use App\Integrations\Healthcare\Services\AncillaryProjectionHandler;
use App\Integrations\Healthcare\Services\ProjectionDispatcher;
use App\Integrations\Healthcare\Services\RpmProjectionHandler;
use App\Integrations\Healthcare\Services\RtdcProjectionHandler;
use App\Integrations\Healthcare\Services\RxAdministrationRecordProjectionHandler;
use App\Observability\Contracts\MetricExporter;
use App\Observability\Contracts\TraceExporter;
use App\Observability\Exporters\InMemoryMetricExporter;
use App\Observability\Exporters\NullMetricExporter;
use App\Observability\Exporters\OtlpExporter;
use App\Observability\Exporters\OtlpExporterFactory;
use App\Observability\MetricRecorder;
use App\Rtdc\Optimizer\Contracts\BedAssignmentOptimizer;
use App\Rtdc\Optimizer\HeuristicBedAssignmentOptimizer;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use App\Security\ClinicalPayloads\ClinicalPayloadException;
use App\Security\ClinicalPayloads\ClinicalPayloadSafeQueueJob;
use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use App\Security\ClinicalPayloads\ClinicalSafeLogManager;
use App\Security\ClinicalPayloads\EncryptedClinicalPayloadStore;
use App\Security\Network\OidcUrlPolicy;
use App\Security\Secrets\Providers\AwsSecretsManagerProvider;
use App\Security\Secrets\Providers\AzureKeyVaultProvider;
use App\Security\Secrets\Providers\FileSecretProvider;
use App\Security\Secrets\Providers\GcpSecretManagerProvider;
use App\Security\Secrets\Providers\VaultSecretProvider;
use App\Security\Secrets\SecretProviderRegistry;
use App\Services\Alerting\OperationalAlertDispatcher;
use App\Services\Auth\Oidc\ExternalIdentityEventRecorder;
use App\Services\Auth\Oidc\OidcDiscoveryService;
use App\Services\Auth\Oidc\OidcHandshakeStore;
use App\Services\Auth\Oidc\OidcHttpClient;
use App\Services\Auth\Oidc\OidcProviderConfig;
use App\Services\Auth\Oidc\OidcReconciliationService;
use App\Services\Auth\Oidc\OidcTokenValidator;
use App\Services\Auth\ProductionSessionConfiguration;
use App\Services\Authorization\RoleCapabilityService;
use App\Services\Cockpit\AlertFanout;
use App\Services\Cockpit\Channels\PushAlertChannel;
use App\Services\Cockpit\Channels\TeamsAlertChannel;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\Ancillary\BloodBankDemoGenerator;
use App\Services\Demo\Ancillary\LabDemoGenerator;
use App\Services\Demo\Ancillary\PathologyDemoGenerator;
use App\Services\Demo\Ancillary\PharmacyDemoGenerator;
use App\Services\Demo\Ancillary\RadiologyDemoGenerator;
use App\Services\Lab\LabAggregateSnapshotFactory;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\Events\ConnectionEstablished;
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
        $this->app->singleton(ProductionDatabaseReadOnlyGuard::class);
        $this->app->make('events')->listen(
            ConnectionEstablished::class,
            function (ConnectionEstablished $event): void {
                $this->app->make(ProductionDatabaseReadOnlyGuard::class)
                    ->protect($event->connection, $this->app->environment());
            },
        );

        $this->app->singleton('log', fn ($app) => new ClinicalSafeLogManager($app));

        $this->app->singleton(RoleCapabilityService::class);

        $this->app->bind(
            BedAssignmentOptimizer::class,
            HeuristicBedAssignmentOptimizer::class,
        );

        // Part X (X4) — the Arena copilot's LLM seam. Bound to the Eddy-proxy driver,
        // which is inert (isLive()=false, generate()=null) unless BOTH EDDY_ENABLED
        // and ARENA_AI_ENABLED are on — so the copilot runs fully deterministic by
        // default and in tests, with the LLM as a pure enhancement when switched on.
        $this->app->bind(
            CopilotLlm::class,
            EddyProxyCopilotLlm::class,
        );

        $this->app->singleton(OidcProviderConfig::class);

        $this->app->bind(OidcDiscoveryService::class, fn ($app) => new OidcDiscoveryService(
            $app->make(OidcProviderConfig::class)->discoveryUrl(),
            $app->make(OidcHttpClient::class),
            $app->make(OidcUrlPolicy::class),
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

        $this->app->singleton(
            ProjectionDispatcher::class,
            fn ($app) => new ProjectionDispatcher([
                $app->make(RtdcProjectionHandler::class),
                $app->make(AncillaryProjectionHandler::class),
                $app->make(AdcStationEventProjectionHandler::class),
                $app->make(RxAdministrationRecordProjectionHandler::class),
                // Home Hospital RPM feed (ObservationRecorded / DeviceStatusChanged).
                $app->make(RpmProjectionHandler::class),
            ]),
        );
        $this->app->alias(
            ProjectionDispatcher::class,
            ProjectionHandler::class,
        );
        $this->app->singleton(
            AncillaryNormalizerRegistry::class,
            fn ($app) => new AncillaryNormalizerRegistry([
                $app->make(RadiologyOrderHl7V2Normalizer::class),
                $app->make(RadiologyResultHl7V2Normalizer::class),
                $app->make(RadiologyOrderFhirNormalizer::class),
                $app->make(RadiologyResultFhirNormalizer::class),
                $app->make(RadiologyOperationalEventNormalizer::class),
                $app->make(LabResultHl7V2Normalizer::class),
                $app->make(LabResultFhirNormalizer::class),
                $app->make(LabOrderHl7V2Normalizer::class),
                $app->make(LabOrderFhirNormalizer::class),
                $app->make(PharmacyOrderHl7V2Normalizer::class),
                $app->make(PharmacyOrderFhirNormalizer::class),
                $app->make(PharmacyVerificationQueueNormalizer::class),
                $app->make(PharmacyAdcTransactionNormalizer::class),
                $app->make(PharmacyAdministrationImportNormalizer::class),
                $app->make(AncillaryHl7V2MessageNormalizer::class),
                $app->make(AncillaryStructuredMessageNormalizer::class),
                $app->make(UnsupportedAncillaryMessageNormalizer::class),
            ]),
        );
        $this->app->bind(
            BulkBackfillAdapter::class,
            AncillaryBulkBackfillAdapter::class,
        );
        $this->app->singleton(
            AncillaryDemoScenarioService::class,
            fn ($app) => new AncillaryDemoScenarioService([
                $app->make(RadiologyDemoGenerator::class),
                $app->make(LabDemoGenerator::class),
                $app->make(PathologyDemoGenerator::class),
                $app->make(BloodBankDemoGenerator::class),
                $app->make(PharmacyDemoGenerator::class),
            ]),
        );

        // §3.2.9: one laboratory aggregate computation per request/queued job.
        // scoped() (not singleton) — the container flushes it per FPM request
        // and per queue job, so it can never become a second cross-request
        // snapshot authority beside SnapshotBuilder's cache + persisted row.
        $this->app->scoped(LabAggregateSnapshotFactory::class);

        // P6: the alert fan-out lanes. Both are inert by default (push gated
        // by EDDY_PUSH_ENABLED, Teams by TEAMS_ALERT_WEBHOOK_URL) — adding a
        // lane means adding an AlertChannel here, not touching the engine.
        $this->app->singleton(AlertFanout::class, fn ($app) => new AlertFanout([
            $app->make(PushAlertChannel::class),
            $app->make(TeamsAlertChannel::class),
        ]));

        // INT-OBS 5 + ADM-HEALTH 6: the shared on-call delivery abstraction for
        // integration SLO breaches and critical system-health observations.
        // Reuses the SAME inert-by-default channels — a new lane is a new
        // OperationalAlertChannel binding here, not a new delivery path.
        $this->app->singleton(OperationalAlertDispatcher::class, fn ($app) => new OperationalAlertDispatcher([
            $app->make(PushAlertChannel::class),
            $app->make(TeamsAlertChannel::class),
        ], $app->make(ClinicalContentGuard::class)));

        // INT-OBS 4: the guarded application recorder can stay in-memory, discard,
        // or send OTLP/HTTP protobuf through the official OpenTelemetry SDK. Both
        // contracts resolve to one singleton so metrics and spans share config.
        $this->app->singleton(InMemoryMetricExporter::class, fn ($app) => new InMemoryMetricExporter(
            (int) config('observability.memory_buffer', 512),
        ));
        $this->app->singleton(OtlpExporter::class, fn ($app) => $app
            ->make(OtlpExporterFactory::class)
            ->make());
        $this->app->singleton(MetricExporter::class, function ($app) {
            if (! (bool) config('observability.enabled', false)) {
                return $app->make(NullMetricExporter::class);
            }

            return match ((string) config('observability.exporter', 'memory')) {
                'null' => $app->make(NullMetricExporter::class),
                'memory' => $app->make(InMemoryMetricExporter::class),
                'otlp' => $app->make(OtlpExporter::class),
                default => throw new \InvalidArgumentException('observability_exporter_invalid'),
            };
        });
        $this->app->singleton(TraceExporter::class, function ($app) {
            if (! (bool) config('observability.enabled', false)) {
                return $app->make(NullMetricExporter::class);
            }

            return match ((string) config('observability.exporter', 'memory')) {
                'null' => $app->make(NullMetricExporter::class),
                'memory' => $app->make(InMemoryMetricExporter::class),
                'otlp' => $app->make(OtlpExporter::class),
                default => throw new \InvalidArgumentException('observability_exporter_invalid'),
            };
        });
        $this->app->singleton(MetricRecorder::class, fn ($app) => new MetricRecorder(
            $app->make(MetricExporter::class),
            $app->make(TraceExporter::class),
            $app->make(ClinicalContentGuard::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            $this->app->make(ProductionSessionConfiguration::class)->assertSecure();
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
