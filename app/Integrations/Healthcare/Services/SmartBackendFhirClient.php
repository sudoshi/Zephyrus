<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Integrations\Healthcare\Exceptions\IntegrationThrottledException;
use App\Models\Integration\Source;
use App\Observability\MetricRecorder;
use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SmartBackendFhirClient
{
    public function __construct(
        private readonly CredentialRuntimeResolver $credentials,
        private readonly IntegrationConnectionGuard $connections,
        private readonly ClinicalPayloadStore $payloads,
        private readonly SourceRuntimePressureService $runtimePressure,
        private readonly MetricRecorder $metrics,
        private readonly FhirResourceProfileService $profiles,
    ) {}

    /** @return array<string, mixed> */
    public function poll(int $sourceId, string $resourceType, int $ingestRunId): array
    {
        $profile = $this->profiles->requirePollable($sourceId, $resourceType);
        $connectorKey = 'fhir.r4.'.strtolower($resourceType);
        $runtimeCorrelation = $this->runCorrelation($ingestRunId);
        $startedAt = hrtime(true);
        $attributes = [
            'zephyrus.source.id' => $sourceId,
            'zephyrus.ingest_run.id' => $ingestRunId,
            'zephyrus.connector.key' => $connectorKey,
            'fhir.resource.type' => $resourceType,
        ];
        if ($runtimeCorrelation !== null) {
            $attributes['zephyrus.correlation.uuid'] = $runtimeCorrelation;
        }

        try {
            $this->runtimePressure->beforePartnerCall($sourceId, $connectorKey, $runtimeCorrelation);
            $result = $this->performPoll($sourceId, $resourceType, $ingestRunId, $connectorKey, $profile);
            $this->runtimePressure->recordPartnerSuccess($sourceId, $connectorKey, $runtimeCorrelation);
            $this->metrics->span(
                'zephyrus.integration.fhir.poll',
                'ok',
                $this->durationMs($startedAt),
                [
                    ...$attributes,
                    'zephyrus.outcome' => 'completed',
                    'fhir.resources.received' => (int) $result['resourcesReceived'],
                    'fhir.resources.persisted' => (int) $result['resourcesPersisted'],
                ],
            );

            return $result;
        } catch (IntegrationThrottledException $exception) {
            $this->recordFailureSpan($startedAt, $attributes, $exception->errorCode);

            throw $exception;
        } catch (ConnectionException) {
            $this->runtimePressure->recordPartnerFailure(
                $sourceId,
                $connectorKey,
                'fhir_transport_unavailable',
                $runtimeCorrelation,
            );
            $this->recordFailureSpan($startedAt, $attributes, 'fhir_transport_unavailable');

            throw new IntegrationProtocolException('fhir_transport_unavailable');
        } catch (IntegrationProtocolException $exception) {
            $this->runtimePressure->recordPartnerFailure(
                $sourceId,
                $connectorKey,
                $exception->errorCode,
                $runtimeCorrelation,
            );
            $this->recordFailureSpan($startedAt, $attributes, $exception->errorCode);

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function performPoll(
        int $sourceId,
        string $resourceType,
        int $ingestRunId,
        string $connectorKey,
        object $profile,
    ): array {

        $source = Source::query()->findOrFail($sourceId);
        if (! str_contains(strtolower((string) $source->interface_type), 'fhir')) {
            throw new IntegrationProtocolException('fhir_source_required');
        }
        if ($source->active_status !== 'active') {
            throw new IntegrationProtocolException('fhir_source_not_active');
        }
        if ($source->environment === 'production'
            && (! $source->phi_allowed || $source->go_live_status !== 'live')) {
            throw new IntegrationProtocolException('fhir_phi_governance_incomplete');
        }

        $connection = DB::table('integration.fhir_client_connections')
            ->where('source_id', $sourceId)->orderBy('fhir_client_connection_id')->first();
        if (! $connection || $connection->health_status !== 'healthy' || ! filled($connection->base_url)) {
            throw new IntegrationProtocolException('fhir_discovery_required');
        }
        $credential = DB::table('integration.smart_backend_credentials')
            ->where('source_id', $sourceId)->orderBy('smart_backend_credential_id')->first();
        if (! $credential
            || ! filled($credential->client_id)
            || $credential->source_credential_id === null
            || ! filled($credential->token_url)) {
            throw new IntegrationProtocolException('smart_credentials_required');
        }

        $token = $this->accessToken($credential, $sourceId, $connectorKey);
        $baseUrl = rtrim((string) $connection->base_url, '/');
        $since = DB::table('integration.connector_watermarks')
            ->where('source_id', $sourceId)
            ->where('connector_key', 'fhir.r4')
            ->where('scope_type', 'resource_type')
            ->where('scope_key', $resourceType)
            ->where('watermark_kind', 'last_updated')
            ->value('watermark_value');

        $url = $baseUrl.'/'.$resourceType;
        $query = ['_count' => (int) $profile->page_size, '_sort' => '_lastUpdated'];
        if (is_string($since) && $since !== '') {
            $query['_lastUpdated'] = 'gt'.$since;
        }

        $pages = 0;
        $received = 0;
        $persisted = 0;
        $skipped = 0;
        $resourceLimitExceeded = false;
        $latestUpdated = is_string($since) && $since !== '' ? CarbonImmutable::parse($since) : null;
        $pageLimit = (int) $profile->page_limit;
        $resourceLimit = (int) $profile->resource_limit;

        while ($url !== null && $pages < $pageLimit && $received < $resourceLimit) {
            $connectionTarget = $this->connections->guard($sourceId, $url);
            if ($this->origin($url) !== $this->origin($baseUrl)) {
                throw new IntegrationProtocolException('fhir_pagination_origin_mismatch');
            }
            $response = Http::withToken($token['accessToken'])
                ->accept('application/fhir+json')
                ->connectTimeout(5)
                ->timeout((int) config('integrations.health_timeout_seconds', 10) * 3)
                ->withOptions($connectionTarget->httpOptions())
                ->get($url, $pages === 0 ? $query : []);
            $bundle = $this->fhirJson($response, $sourceId, $connectorKey);
            if (($bundle['resourceType'] ?? null) !== 'Bundle') {
                throw new IntegrationProtocolException('fhir_search_bundle_required');
            }

            foreach ((array) ($bundle['entry'] ?? []) as $entry) {
                if ($received >= $resourceLimit) {
                    $resourceLimitExceeded = true;
                    break;
                }
                $resource = is_array($entry) ? ($entry['resource'] ?? null) : null;
                if (! is_array($resource) || ($resource['resourceType'] ?? null) !== $resourceType || ! filled($resource['id'] ?? null)) {
                    $skipped++;

                    continue;
                }
                $received++;
                $created = $this->persistResource($sourceId, $resourceType, $resource, $ingestRunId);
                $persisted += $created ? 1 : 0;
                $skipped += $created ? 0 : 1;
                $updated = data_get($resource, 'meta.lastUpdated');
                if (is_string($updated) && $updated !== '') {
                    $candidate = CarbonImmutable::parse($updated);
                    $latestUpdated = $latestUpdated === null || $candidate->greaterThan($latestUpdated) ? $candidate : $latestUpdated;
                }
            }

            $pages++;
            $url = $this->nextUrl($bundle);
        }

        if ($url !== null || $resourceLimitExceeded) {
            throw new IntegrationProtocolException('fhir_poll_limit_exceeded');
        }

        if ($latestUpdated !== null) {
            DB::table('integration.connector_watermarks')->updateOrInsert(
                [
                    'source_id' => $sourceId,
                    'connector_key' => 'fhir.r4',
                    'scope_type' => 'resource_type',
                    'scope_key' => $resourceType,
                    'watermark_kind' => 'last_updated',
                ],
                [
                    'watermark_value' => $latestUpdated->toIso8601String(),
                    'last_success_at' => now(),
                    'metadata' => json_encode(['pages' => $pages], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        DB::table('integration.smart_backend_credentials')
            ->where('smart_backend_credential_id', $credential->smart_backend_credential_id)
            ->update([
                'status' => 'active',
                'issued_at' => now(),
                'expires_at' => now()->addSeconds($token['expiresIn']),
                'updated_at' => now(),
            ]);
        DB::table('integration.fhir_client_connections')
            ->where('fhir_client_connection_id', $connection->fhir_client_connection_id)
            ->update(['status' => 'active', 'updated_at' => now()]);

        return [
            'resourceType' => $resourceType,
            'pages' => $pages,
            'resourcesReceived' => $received,
            'resourcesPersisted' => $persisted,
            'resourcesSkipped' => $skipped,
            'watermarkAdvanced' => $latestUpdated !== null,
        ];
    }

    /** @return array{accessToken: string, expiresIn: int} */
    private function accessToken(object $credential, int $sourceId, string $connectorKey): array
    {
        $tokenUrl = (string) $credential->token_url;
        $connectionTarget = $this->connections->guard($sourceId, $tokenUrl);
        try {
            $privateKey = $this->credentials->resolveSecret(
                $sourceId,
                (int) $credential->source_credential_id,
            )->value();
        } catch (IntegrationCredentialException $exception) {
            throw new IntegrationProtocolException($exception->errorCode);
        }

        $metadata = $this->decodeMap($credential->metadata);
        [$jwtAlgorithm, $opensslAlgorithm] = $this->signingAlgorithm($metadata['algorithm'] ?? null);
        $header = array_filter([
            'alg' => $jwtAlgorithm,
            'typ' => 'JWT',
            'kid' => $metadata['key_id'] ?? null,
        ], fn (mixed $value): bool => filled($value));
        $now = time();
        $claims = [
            'iss' => (string) $credential->client_id,
            'sub' => (string) $credential->client_id,
            'aud' => $tokenUrl,
            'jti' => (string) Str::uuid(),
            'iat' => $now,
            'exp' => $now + 300,
        ];
        $signingInput = $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR)).'.'.$this->base64Url(json_encode($claims, JSON_THROW_ON_ERROR));
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new IntegrationProtocolException('credential_private_key_invalid');
        }
        $signature = '';
        if (! openssl_sign($signingInput, $signature, $key, $opensslAlgorithm)) {
            throw new IntegrationProtocolException('client_assertion_signing_failed');
        }
        $assertion = $signingInput.'.'.$this->base64Url($signature);
        $scopes = array_values(array_filter($this->decodeList($credential->scope_payload), 'is_string'));

        $response = Http::asForm()
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout((int) config('integrations.health_timeout_seconds', 10) * 2)
            ->withOptions($connectionTarget->httpOptions())
            ->post($tokenUrl, [
                'grant_type' => 'client_credentials',
                'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                'client_assertion' => $assertion,
                'scope' => implode(' ', $scopes),
            ]);
        if ($response->redirect()) {
            throw new IntegrationProtocolException('smart_token_redirect_rejected');
        }
        if ($response->status() === 429) {
            $retryAfter = $this->runtimePressure->retryAfterSeconds($response->header('Retry-After'));
            $this->runtimePressure->recordRateLimit(
                $sourceId,
                'throttled',
                429,
                $retryAfter,
                $connectorKey,
                'smart_token_rate_limited',
            );

            throw new IntegrationThrottledException($retryAfter, 'smart_token_http_429');
        }
        if (! $response->successful()) {
            throw new IntegrationProtocolException('smart_token_http_'.$response->status());
        }
        $payload = $response->json();
        $accessToken = is_array($payload) ? ($payload['access_token'] ?? null) : null;
        if (! is_string($accessToken) || $accessToken === '') {
            throw new IntegrationProtocolException('smart_access_token_missing');
        }
        $expiresIn = min(300, max(1, (int) ($payload['expires_in'] ?? 300)));

        return ['accessToken' => $accessToken, 'expiresIn' => $expiresIn];
    }

    /** @param array<string, mixed> $resource */
    private function persistResource(int $sourceId, string $resourceType, array $resource, int $ingestRunId): bool
    {
        $resourceJson = json_encode($resource, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $resourceJson);
        $fhirId = (string) $resource['id'];
        $versionId = (string) (data_get($resource, 'meta.versionId') ?: 'hash-'.substr($hash, 0, 32));
        $idempotencyKey = 'fhir:sha256:'.hash('sha256', "{$resourceType}\0{$fhirId}\0{$versionId}");

        $existing = DB::table('raw.inbound_messages')
            ->where('source_id', $sourceId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            if (! hash_equals((string) $existing->payload_hash, $hash)) {
                throw new IntegrationProtocolException('fhir_version_hash_conflict');
            }

            return false;
        }

        $stored = $this->payloads->storeJson(
            $sourceId,
            'fhir_resource',
            $resource,
            contentType: 'application/fhir+json',
        );

        try {
            $created = DB::transaction(function () use ($sourceId, $resourceType, $resource, $hash, $fhirId, $versionId, $idempotencyKey, $ingestRunId, $stored): bool {
                $existing = DB::table('raw.inbound_messages')
                    ->where('source_id', $sourceId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()->first();
                if ($existing) {
                    if (! hash_equals((string) $existing->payload_hash, $hash)) {
                        throw new IntegrationProtocolException('fhir_version_hash_conflict');
                    }

                    return false;
                }

                $messageId = DB::table('raw.inbound_messages')->insertGetId([
                    'message_uuid' => (string) Str::uuid(),
                    'source_id' => $sourceId,
                    'ingest_run_id' => $ingestRunId,
                    'message_type' => 'FHIR_R4_'.$resourceType,
                    'external_id' => Str::limit("{$resourceType}/{$fhirId}/_history/{$versionId}", 190, ''),
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $hash,
                    'payload' => null,
                    'payload_object_id' => $stored->payloadObjectId,
                    'normalized_payload' => null,
                    'received_at' => now(),
                    'parse_status' => 'persisted',
                    'metadata' => json_encode(['protocol' => 'fhir_r4'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'inbound_message_id');

                $resourceVersionId = DB::table('fhir.resource_versions')->insertGetId([
                    'source_id' => $sourceId,
                    'resource_type' => $resourceType,
                    'fhir_id' => $fhirId,
                    'version_id' => $versionId,
                    'last_updated' => data_get($resource, 'meta.lastUpdated'),
                    'resource_hash' => $hash,
                    'resource_data' => json_encode((object) [], JSON_THROW_ON_ERROR),
                    'payload_object_id' => $stored->payloadObjectId,
                    'ingest_run_id' => $ingestRunId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'resource_version_id');

                DB::table('integration.provenance_records')->insert([
                    'source_id' => $sourceId,
                    'inbound_message_id' => $messageId,
                    'canonical_event_id' => null,
                    'target_schema' => 'fhir',
                    'target_table' => 'resource_versions',
                    'target_pk' => (string) $resourceVersionId,
                    'lineage' => json_encode([
                        'raw_message' => "raw.inbound_messages:{$messageId}",
                        'projection' => "fhir.resource_versions:{$resourceVersionId}",
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return true;
            });
        } catch (Throwable $exception) {
            $this->discardPayload($stored->payloadObjectId, $sourceId, 'fhir_resource_persistence_failed');

            throw $exception;
        }

        if (! $created) {
            $this->discardPayload($stored->payloadObjectId, $sourceId, 'fhir_resource_insert_race');
        }

        return $created;
    }

    private function discardPayload(int $payloadObjectId, int $sourceId, string $reasonCode): void
    {
        $this->payloads->discard(
            $payloadObjectId,
            $sourceId,
            $reasonCode,
            'Encrypted FHIR payload was discarded because the intended database link did not become authoritative.',
        );
    }

    /** @return array<string, mixed> */
    private function fhirJson(Response $response, int $sourceId, string $connectorKey): array
    {
        if ($response->redirect()) {
            throw new IntegrationProtocolException('fhir_redirect_rejected');
        }
        if ($response->status() === 429) {
            $retryAfter = $this->runtimePressure->retryAfterSeconds($response->header('Retry-After'));
            $this->runtimePressure->recordRateLimit(
                $sourceId,
                'throttled',
                429,
                $retryAfter,
                $connectorKey,
                'fhir_search_rate_limited',
            );

            throw new IntegrationThrottledException($retryAfter, 'fhir_http_429');
        }
        if (! $response->successful()) {
            throw new IntegrationProtocolException('fhir_http_'.$response->status());
        }
        if (strlen($response->body()) > 10_485_760) {
            throw new IntegrationProtocolException('fhir_response_too_large');
        }
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new IntegrationProtocolException('fhir_response_not_json');
        }

        return $payload;
    }

    /** @param array<string, int|string> $attributes */
    private function recordFailureSpan(int $startedAt, array $attributes, string $errorCode): void
    {
        $this->metrics->span(
            'zephyrus.integration.fhir.poll',
            'error',
            $this->durationMs($startedAt),
            [...$attributes, 'error.type' => $errorCode],
        );
    }

    private function durationMs(int $startedAt): int
    {
        return max(0, min(86_400_000, (int) ((hrtime(true) - $startedAt) / 1_000_000)));
    }

    /** @param array<string, mixed> $bundle */
    private function nextUrl(array $bundle): ?string
    {
        foreach ((array) ($bundle['link'] ?? []) as $link) {
            if (is_array($link) && ($link['relation'] ?? null) === 'next' && is_string($link['url'] ?? null)) {
                return $link['url'];
            }
        }

        return null;
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return "{$scheme}://{$host}:{$port}";
    }

    private function runCorrelation(int $ingestRunId): ?string
    {
        $metadata = $this->decodeMap(
            DB::table('raw.ingest_runs')->where('ingest_run_id', $ingestRunId)->value('metadata'),
        );
        $correlation = $metadata['correlation_id'] ?? null;

        return is_string($correlation) && Str::isUuid($correlation) ? $correlation : null;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @return array{string, int} */
    private function signingAlgorithm(mixed $value): array
    {
        return match (strtoupper(trim((string) $value))) {
            'RS256' => ['RS256', OPENSSL_ALGO_SHA256],
            'RS512' => ['RS512', OPENSSL_ALGO_SHA512],
            default => ['RS384', OPENSSL_ALGO_SHA384],
        };
    }

    /** @return array<string, mixed> */
    private function decodeMap(mixed $value): array
    {
        return is_string($value) ? (json_decode($value, true) ?: []) : (is_array($value) ? $value : []);
    }

    /** @return list<mixed> */
    private function decodeList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }
}
