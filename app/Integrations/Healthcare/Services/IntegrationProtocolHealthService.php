<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Models\Integration\Source;
use App\Models\User;
use App\Security\Network\IntegrationUrlPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class IntegrationProtocolHealthService
{
    public function __construct(
        private readonly IntegrationUrlPolicy $urlPolicy,
        private readonly IntegrationSecretReferenceResolver $secrets,
    ) {}

    /** @return array<string, mixed> */
    public function check(int $sourceId): array
    {
        $source = Source::query()->findOrFail($sourceId);
        $interface = strtolower((string) $source->interface_type);

        try {
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

            return $result;
        } catch (IntegrationProtocolException $exception) {
            $this->recordFailure($source, $exception->errorCode);
            throw $exception;
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
        $this->urlPolicy->assertSafe($baseUrl);
        $this->urlPolicy->assertSafe($smartUrl);

        $smart = $this->json($smartUrl, 'application/json');
        $tokenUrl = $smart['token_endpoint'] ?? null;
        $capabilities = is_array($smart['capabilities'] ?? null) ? $smart['capabilities'] : [];
        if (! is_string($tokenUrl) || $tokenUrl === '') {
            throw new IntegrationProtocolException('smart_token_endpoint_missing');
        }
        $this->urlPolicy->assertSafe($tokenUrl);
        if (! in_array('client-confidential-asymmetric', $capabilities, true)) {
            throw new IntegrationProtocolException('smart_asymmetric_client_not_supported');
        }

        $statement = $this->json($baseUrl.'/metadata', 'application/fhir+json');
        if (($statement['resourceType'] ?? null) !== 'CapabilityStatement') {
            throw new IntegrationProtocolException('fhir_capability_statement_invalid');
        }
        $fhirVersion = (string) ($statement['fhirVersion'] ?? '');
        if ($fhirVersion !== '4.0.1') {
            throw new IntegrationProtocolException('fhir_r4_version_required');
        }

        $resources = collect(data_get($statement, 'rest.0.resource', []))
            ->pluck('type')
            ->filter(fn (mixed $type): bool => is_string($type) && preg_match('/^[A-Z][A-Za-z]{1,79}$/', $type) === 1)
            ->unique()->sort()->values()->all();
        $softwareName = trim((string) data_get($statement, 'software.name', 'Unknown'));
        if (strtolower((string) $source->vendor) === 'epic' && ! str_contains(strtolower($softwareName), 'epic')) {
            throw new IntegrationProtocolException('fhir_vendor_mismatch');
        }

        $credential = DB::table('integration.smart_backend_credentials')
            ->where('source_id', $source->source_id)
            ->orderBy('smart_backend_credential_id')
            ->first();
        $credentialReady = filled($credential?->client_id)
            && filled($credential?->jwks_secret_ref)
            && $this->secrets->resolvable($credential?->jwks_secret_ref);
        $activationStatus = $credentialReady ? 'ready' : 'credential_required';

        DB::transaction(function () use ($source, $connection, $statement, $smart, $tokenUrl, $resources, $fhirVersion, $softwareName, $credential, $credentialReady): void {
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
                        'formats' => array_values(array_filter((array) ($statement['format'] ?? []), 'is_string')),
                        'resourceTypes' => $resources,
                    ], JSON_THROW_ON_ERROR),
                    'smart_configuration' => json_encode([
                        'tokenEndpoint' => $tokenUrl,
                        'capabilities' => array_values(array_filter((array) ($smart['capabilities'] ?? []), 'is_string')),
                    ], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);

            if ($credential) {
                DB::table('integration.smart_backend_credentials')
                    ->where('smart_backend_credential_id', $credential->smart_backend_credential_id)
                    ->update([
                        'status' => $credentialReady ? 'ready' : 'activation_required',
                        'token_url' => $tokenUrl,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('integration.source_capabilities')->where('source_id', $source->source_id)->where('capability_type', 'fhir_resource')->delete();
            foreach ($resources as $resourceType) {
                DB::table('integration.source_capabilities')->insert([
                    'source_id' => $source->source_id,
                    'resource_type' => $resourceType,
                    'capability_type' => 'fhir_resource',
                    'operation' => 'read_search',
                    'supported' => true,
                    'metadata' => json_encode([], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return [
            'status' => 'healthy',
            'protocol' => 'fhir_r4_smart',
            'vendor' => $softwareName,
            'fhirVersion' => $fhirVersion,
            'supportedResourceCount' => count($resources),
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
    private function json(string $url, string $accept): array
    {
        $response = Http::accept($accept)
            ->connectTimeout(5)
            ->timeout((int) config('integrations.health_timeout_seconds', 10))
            ->withOptions(['allow_redirects' => false])
            ->get($url);

        $this->assertResponse($response);
        if (strlen($response->body()) > 5_242_880) {
            throw new IntegrationProtocolException('protocol_response_too_large');
        }
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new IntegrationProtocolException('protocol_response_not_json');
        }

        return $payload;
    }

    private function assertResponse(Response $response): void
    {
        if ($response->redirect()) {
            throw new IntegrationProtocolException('protocol_redirect_rejected');
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
