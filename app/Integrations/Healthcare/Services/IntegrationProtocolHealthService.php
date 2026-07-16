<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Integrations\Healthcare\Exceptions\IntegrationThrottledException;
use App\Models\Integration\Source;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class IntegrationProtocolHealthService
{
    public function __construct(
        private readonly CredentialValidationService $credentials,
        private readonly IntegrationConnectionGuard $connections,
        private readonly SourceRuntimePressureService $runtimePressure,
        private readonly FhirResourceProfileService $fhirProfiles,
        private readonly FhirVendorConformanceService $vendorConformance,
        private readonly FhirConformanceObservationService $fhirConformance,
    ) {}

    /** @return array<string, mixed> */
    public function check(int $sourceId): array
    {
        $source = Source::query()->findOrFail($sourceId);
        $interface = strtolower((string) $source->interface_type);
        $connectorKey = 'integration.protocol-health';

        try {
            if (str_contains($interface, 'fhir')) {
                $this->runtimePressure->beforePartnerCall($sourceId, $connectorKey);
            }
            $result = match (true) {
                str_contains($interface, 'fhir') => $this->checkFhir($source),
                str_contains($interface, 'hl7') => $this->checkHl7($source),
                default => throw new IntegrationProtocolException('protocol_health_not_supported'),
            };

            $source->forceFill([
                'protocol_health_status' => $result['status'],
                'protocol_health_checked_at' => now(),
                'protocol_health_error' => null,
            ])->save();
            if (str_contains($interface, 'fhir')) {
                $this->runtimePressure->recordPartnerSuccess($sourceId, $connectorKey);
            }

            return $result;
        } catch (IntegrationProtocolException $exception) {
            $this->recordFailure($source, $exception->errorCode);
            if (str_contains($interface, 'fhir') && ! $exception instanceof IntegrationThrottledException) {
                $this->runtimePressure->recordPartnerFailure(
                    $sourceId,
                    $connectorKey,
                    $exception->errorCode,
                );
            }
            throw $exception;
        } catch (ConnectionException) {
            $this->recordFailure($source, 'protocol_transport_unavailable');
            if (str_contains($interface, 'fhir')) {
                $this->runtimePressure->recordPartnerFailure(
                    $sourceId,
                    $connectorKey,
                    'protocol_transport_unavailable',
                );
            }
            throw new IntegrationProtocolException('protocol_transport_unavailable');
        } catch (Throwable $exception) {
            $this->recordFailure($source, 'protocol_health_check_failed');
            throw new IntegrationProtocolException('protocol_health_check_failed');
        }
    }

    /** @return array<string, mixed> */
    private function checkFhir(Source $source): array
    {
        $connection = DB::table('integration.fhir_client_connections')
            ->where('source_id', $source->source_id)
            ->orderBy('fhir_client_connection_id')
            ->first();
        if (! $connection || ! filled($connection->base_url ?? $source->base_url)) {
            throw new IntegrationProtocolException('fhir_connection_not_configured');
        }

        $baseUrl = rtrim((string) ($connection->base_url ?: $source->base_url), '/');
        $smartUrl = $this->endpointUrl((int) $source->source_id, 'smart_configuration')
            ?? $baseUrl.'/.well-known/smart-configuration';

        $smart = $this->json((int) $source->source_id, $smartUrl, 'application/json');
        $tokenUrl = $smart['token_endpoint'] ?? null;
        $capabilities = is_array($smart['capabilities'] ?? null) ? $smart['capabilities'] : [];
        if (! is_string($tokenUrl) || $tokenUrl === '') {
            throw new IntegrationProtocolException('smart_token_endpoint_missing');
        }
        $this->connections->guard((int) $source->source_id, $tokenUrl);
        if (! in_array('client-confidential-asymmetric', $capabilities, true)) {
            throw new IntegrationProtocolException('smart_asymmetric_client_not_supported');
        }

        $statement = $this->json((int) $source->source_id, $baseUrl.'/metadata', 'application/fhir+json');
        if (($statement['resourceType'] ?? null) !== 'CapabilityStatement') {
            throw new IntegrationProtocolException('fhir_capability_statement_invalid');
        }
        $fhirVersion = (string) ($statement['fhirVersion'] ?? '');
        if ($fhirVersion !== '4.0.1') {
            throw new IntegrationProtocolException('fhir_r4_version_required');
        }

        $softwareName = trim((string) data_get($statement, 'software.name', 'Unknown'));
        $this->vendorConformance->assertCapabilityStatement($source, $statement);

        $credential = DB::table('integration.smart_backend_credentials')
            ->where('source_id', $source->source_id)
            ->orderBy('smart_backend_credential_id')
            ->first();
        $credentialValidation = $credential?->source_credential_id !== null
            ? $this->credentials->evaluate(
                (int) $credential->source_credential_id,
                CarbonImmutable::now(),
                persist: true,
            )
            : null;
        $configuredTokenUrl = $this->endpointUrl((int) $source->source_id, 'oauth_token');
        foreach ([$credential?->token_url, $configuredTokenUrl] as $expectedTokenUrl) {
            if (filled($expectedTokenUrl) && ! hash_equals((string) $expectedTokenUrl, $tokenUrl)) {
                throw new IntegrationProtocolException('smart_token_endpoint_drift');
            }
        }
        $credentialReady = filled($credential?->client_id)
            && $credentialValidation !== null
            && $credentialValidation['status'] === 'ready';
        $activationStatus = $credentialReady ? 'ready' : 'credential_required';

        $conformance = DB::transaction(function () use ($source, $connection, $statement, $smart, $tokenUrl, $fhirVersion, $softwareName, $credential, $credentialReady): array {
            $conformance = $this->fhirConformance->capture(
                (int) $source->source_id,
                (int) $connection->fhir_client_connection_id,
                $statement,
                $smart,
            );
            DB::table('integration.fhir_client_connections')
                ->where('fhir_client_connection_id', $connection->fhir_client_connection_id)
                ->update([
                    'status' => $credentialReady ? 'ready' : 'activation_required',
                    'health_status' => 'healthy',
                    'health_checked_at' => now(),
                    'last_health_error' => null,
                    'fhir_version' => $fhirVersion,
                    'capability_checked_at' => now(),
                    'capability_statement' => json_encode([
                        'resourceType' => 'CapabilityStatement',
                        'fhirVersion' => $fhirVersion,
                        'software' => [
                            'name' => $softwareName,
                            'version' => data_get($statement, 'software.version'),
                        ],
                        'formats' => $conformance['formats'],
                        'resourceTypes' => collect($conformance['resources'])->pluck('resourceType')->all(),
                        'searchableResourceTypes' => $conformance['searchableResourceTypes'],
                        'systemInteractions' => $conformance['systemInteractions'],
                        'observationId' => $conformance['observationId'],
                        'documentSha256' => $conformance['capabilityDocumentSha256'],
                    ], JSON_THROW_ON_ERROR),
                    'smart_configuration' => json_encode([
                        'tokenEndpoint' => $tokenUrl,
                        'grantTypes' => $conformance['smart']['grantTypes'],
                        'tokenAuthMethods' => $conformance['smart']['tokenAuthMethods'],
                        'capabilities' => $conformance['smart']['capabilities'],
                        'observationId' => $conformance['observationId'],
                        'documentSha256' => $conformance['smartDocumentSha256'],
                    ], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);

            if ($credential) {
                DB::table('integration.smart_backend_credentials')
                    ->where('smart_backend_credential_id', $credential->smart_backend_credential_id)
                    ->update([
                        'status' => $credentialReady ? 'ready' : 'activation_required',
                        'token_url' => filled($credential->token_url) ? $credential->token_url : $tokenUrl,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('integration.source_capabilities')->where('source_id', $source->source_id)->where('capability_type', 'fhir_resource')->delete();
            foreach ($conformance['resources'] as $resource) {
                $pollingInteractions = array_values(array_intersect(
                    ['search-type', 'history-type'],
                    $resource['interactions'],
                ));
                if ($pollingInteractions === []) {
                    continue;
                }
                DB::table('integration.source_capabilities')->insert([
                    'source_id' => $source->source_id,
                    'resource_type' => $resource['resourceType'],
                    'capability_type' => 'fhir_resource',
                    'operation' => implode('+', $pollingInteractions),
                    'supported' => true,
                    'metadata' => json_encode([
                        'conformanceObservationId' => $conformance['observationId'],
                        'interactions' => $resource['interactions'],
                        'baseProfileUrl' => $resource['baseProfileUrl'],
                        'supportedProfileCount' => count($resource['supportedProfiles']),
                        'searchParameterCount' => count($resource['searchParameters']),
                        'operationCount' => count($resource['operations']),
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->fhirProfiles->reconcileCapabilities(
                (int) $source->source_id,
                collect($conformance['resources'])
                    ->filter(fn (array $resource): bool => array_intersect(
                        ['search-type', 'history-type'],
                        $resource['interactions'],
                    ) !== [])
                    ->pluck('resourceType')
                    ->values()
                    ->all(),
            );

            return $conformance;
        });

        return [
            'status' => 'healthy',
            'protocol' => 'fhir_r4_smart',
            'vendor' => $softwareName,
            'fhirVersion' => $fhirVersion,
            'conformanceObservationId' => $conformance['observationId'],
            'declaredResourceCount' => count($conformance['resources']),
            'supportedResourceCount' => count($conformance['searchableResourceTypes']),
            'supportsBatch' => in_array('batch', $conformance['systemInteractions'], true),
            'supportsTransaction' => in_array('transaction', $conformance['systemInteractions'], true),
            'supportsBulkData' => $conformance['supportsBulkData'],
            'supportsSubscriptions' => $conformance['supportsSubscriptions'],
            'warnings' => $conformance['warnings'],
            'activationStatus' => $activationStatus,
        ];
    }

    /** @return array<string, mixed> */
    private function checkHl7(Source $source): array
    {
        $boundary = DB::table('integration.interface_engines')
            ->where('engine_key', 'patient-flow-hl7v2-ingress')
            ->where('status', 'ready')
            ->exists();
        if (! $boundary) {
            throw new IntegrationProtocolException('hl7_boundary_not_configured');
        }

        $machineIdentities = $this->activeMachineIdentityCount();
        $latestSuccess = DB::table('raw.ingest_runs')
            ->where('source_id', $source->source_id)
            ->where('connector_key', 'patient-flow.hl7v2.adt')
            ->where('status', 'completed')
            ->max('completed_at');
        $status = $machineIdentities > 0 ? 'healthy' : 'degraded';

        return [
            'status' => $status,
            'protocol' => 'hl7v2_adt_https',
            'boundaryStatus' => 'ready',
            'machineIdentityStatus' => $machineIdentities > 0 ? 'ready' : 'token_required',
            'activeMachineIdentityCount' => $machineIdentities,
            'lastIngressAtIso' => $latestSuccess ? CarbonImmutable::parse($latestSuccess)->toIso8601String() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function json(int $sourceId, string $url, string $accept): array
    {
        $connectionTarget = $this->connections->guard($sourceId, $url);
        $response = Http::accept($accept)
            ->connectTimeout(5)
            ->timeout((int) config('integrations.health_timeout_seconds', 10))
            ->withOptions($connectionTarget->httpOptions())
            ->get($url);

        $this->assertResponse($response, $sourceId);
        if (strlen($response->body()) > 5_242_880) {
            throw new IntegrationProtocolException('protocol_response_too_large');
        }
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new IntegrationProtocolException('protocol_response_not_json');
        }

        return $payload;
    }

    private function assertResponse(Response $response, int $sourceId): void
    {
        if ($response->redirect()) {
            throw new IntegrationProtocolException('protocol_redirect_rejected');
        }
        if ($response->status() === 429) {
            $retryAfter = $this->runtimePressure->retryAfterSeconds($response->header('Retry-After'));
            $this->runtimePressure->recordRateLimit(
                $sourceId,
                'throttled',
                429,
                $retryAfter,
                'integration.protocol-health',
                'protocol_health_rate_limited',
            );

            throw new IntegrationThrottledException($retryAfter, 'protocol_http_429');
        }
        if (! $response->successful()) {
            throw new IntegrationProtocolException('protocol_http_'.$response->status());
        }
    }

    private function endpointUrl(int $sourceId, string $type): ?string
    {
        $url = DB::table('integration.source_endpoints')
            ->where('source_id', $sourceId)
            ->where('endpoint_type', $type)
            ->where('is_active', true)
            ->value('url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function activeMachineIdentityCount(): int
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return 0;
        }

        $userIds = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->get(['tokenable_id', 'abilities'])
            ->filter(fn (PersonalAccessToken $token): bool => in_array('integration:patient-flow:ingest', $token->abilities ?? [], true))
            ->pluck('tokenable_id')->unique()->all();

        return User::query()->whereIn('id', $userIds)->where('is_active', true)->count();
    }

    private function recordFailure(Source $source, string $errorCode): void
    {
        $source->forceFill([
            'protocol_health_status' => 'failed',
            'protocol_health_checked_at' => now(),
            'protocol_health_error' => $errorCode,
        ])->save();
        DB::table('integration.fhir_client_connections')
            ->where('source_id', $source->source_id)
            ->update([
                'health_status' => 'failed',
                'health_checked_at' => now(),
                'last_health_error' => $errorCode,
                'updated_at' => now(),
            ]);
    }
}
