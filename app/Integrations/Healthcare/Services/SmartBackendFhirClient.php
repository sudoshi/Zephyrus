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
                    'fhir.resources.deleted' => (int) ($result['resourcesDeleted'] ?? 0),
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
        $pollingInteraction = (string) $profile->polling_interaction;
        $watermarkKind = $pollingInteraction === 'history' ? 'history_since' : 'last_updated';
        $since = DB::table('integration.connector_watermarks')
            ->where('source_id', $sourceId)
            ->where('connector_key', 'fhir.r4')
            ->where('scope_type', 'resource_type')
            ->where('scope_key', $resourceType)
            ->where('watermark_kind', $watermarkKind)
            ->value('watermark_value');
        $result = $pollingInteraction === 'history'
            ? $this->pollHistory($sourceId, $resourceType, $ingestRunId, $connectorKey, $profile, $token['accessToken'], $baseUrl, $since)
            : $this->pollSearch($sourceId, $resourceType, $ingestRunId, $connectorKey, $profile, $token['accessToken'], $baseUrl, $since);

        if ($result['latestUpdated'] instanceof CarbonImmutable) {
            $this->advanceWatermark(
                $sourceId,
                $resourceType,
                $watermarkKind,
                $result['latestUpdated'],
                (int) $result['pages'],
                $pollingInteraction,
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

        unset($result['latestUpdated']);

        return [
            'resourceType' => $resourceType,
            'pollingInteraction' => $pollingInteraction,
            ...$result,
        ];
    }

    /** @return array<string, mixed> */
    private function pollSearch(
        int $sourceId,
        string $resourceType,
        int $ingestRunId,
        string $connectorKey,
        object $profile,
        string $accessToken,
        string $baseUrl,
        mixed $since,
    ): array {
        $url = $baseUrl.'/'.$resourceType;
        $query = ['_count' => (int) $profile->page_size, '_sort' => '_lastUpdated'];
        if (is_string($since) && $since !== '') {
            $query['_lastUpdated'] = 'ge'.$since;
        }

        $pages = 0;
        $received = 0;
        $persisted = 0;
        $skipped = 0;
        $operationOutcomes = 0;
        $latestUpdated = null;
        $resourceLimitExceeded = false;
        $pageLimit = (int) $profile->page_limit;
        $resourceLimit = (int) $profile->resource_limit;

        while ($url !== null && $pages < $pageLimit && $received < $resourceLimit) {
            $bundle = $this->fhirGet(
                $sourceId,
                $connectorKey,
                $accessToken,
                $baseUrl,
                $url,
                $pages === 0 ? $query : [],
            );
            $this->assertBundleType($bundle, 'searchset', 'fhir_search_bundle_required');

            foreach ($this->bundleEntries($bundle, 'fhir_search_entry_invalid') as $entry) {
                $mode = data_get($entry, 'search.mode');
                if ($mode === 'include') {
                    $skipped++;

                    continue;
                }

                $resource = $entry['resource'] ?? null;
                if ($mode === 'outcome' || (is_array($resource) && ($resource['resourceType'] ?? null) === 'OperationOutcome')) {
                    if (! is_array($resource) || ($resource['resourceType'] ?? null) !== 'OperationOutcome') {
                        throw new IntegrationProtocolException('fhir_search_entry_invalid');
                    }
                    $this->assertNonFatalOperationOutcome($resource);
                    $operationOutcomes++;
                    $skipped++;

                    continue;
                }
                if ($mode !== null && $mode !== 'match') {
                    throw new IntegrationProtocolException('fhir_search_entry_invalid');
                }
                if ($received >= $resourceLimit) {
                    $resourceLimitExceeded = true;
                    break;
                }
                $this->assertExpectedResource($resource, $resourceType, 'fhir_search_entry_invalid');
                $updated = $this->instant(
                    data_get($resource, 'meta.lastUpdated'),
                    'fhir_search_last_updated_required',
                );
                $received++;
                $created = $this->persistResource($sourceId, $resourceType, $resource, $ingestRunId);
                $persisted += $created ? 1 : 0;
                $skipped += $created ? 0 : 1;
                $latestUpdated = $this->latest($latestUpdated, $updated);
            }

            $pages++;
            $url = $this->nextUrl($bundle);
        }

        if ($url !== null || $resourceLimitExceeded) {
            throw new IntegrationProtocolException('fhir_poll_limit_exceeded');
        }

        return [
            'pages' => $pages,
            'resourcesReceived' => $received,
            'resourcesPersisted' => $persisted,
            'resourcesSkipped' => $skipped,
            'resourcesDeleted' => 0,
            'operationOutcomes' => $operationOutcomes,
            'watermarkAdvanced' => $latestUpdated !== null,
            'latestUpdated' => $latestUpdated,
        ];
    }

    /** @return array<string, mixed> */
    private function pollHistory(
        int $sourceId,
        string $resourceType,
        int $ingestRunId,
        string $connectorKey,
        object $profile,
        string $accessToken,
        string $baseUrl,
        mixed $since,
    ): array {
        $url = $baseUrl.'/'.$resourceType.'/_history';
        $query = ['_count' => (int) $profile->page_size];
        if (is_string($since) && $since !== '') {
            $query['_since'] = $since;
        }

        $pages = 0;
        $received = 0;
        $persisted = 0;
        $skipped = 0;
        $deleted = 0;
        $latestUpdated = null;
        $resourceLimitExceeded = false;
        $pageLimit = (int) $profile->page_limit;
        $resourceLimit = (int) $profile->resource_limit;

        while ($url !== null && $pages < $pageLimit && $received < $resourceLimit) {
            $bundle = $this->fhirGet(
                $sourceId,
                $connectorKey,
                $accessToken,
                $baseUrl,
                $url,
                $pages === 0 ? $query : [],
            );
            $this->assertBundleType($bundle, 'history', 'fhir_history_bundle_required');

            foreach ($this->bundleEntries($bundle, 'fhir_history_entry_invalid') as $entry) {
                if ($received >= $resourceLimit) {
                    $resourceLimitExceeded = true;
                    break;
                }
                $method = strtoupper((string) data_get($entry, 'request.method'));
                $responseInstant = data_get($entry, 'response.lastModified');
                if ($method === 'DELETE') {
                    $updated = $this->instant($responseInstant, 'fhir_history_last_modified_required');
                    $fhirId = $this->historyDeletedId(
                        (string) data_get($entry, 'request.url'),
                        $resourceType,
                        $baseUrl,
                    );
                    $versionId = $this->historyVersionId(data_get($entry, 'response.etag'), $resourceType, $fhirId, $updated);
                    $created = $this->persistDeletion(
                        $sourceId,
                        $resourceType,
                        $fhirId,
                        $versionId,
                        $updated,
                        $ingestRunId,
                    );
                    $deleted++;
                } elseif (in_array($method, ['POST', 'PUT'], true)) {
                    $resource = $entry['resource'] ?? null;
                    $this->assertExpectedResource($resource, $resourceType, 'fhir_history_entry_invalid');
                    $updated = $this->instant(
                        data_get($resource, 'meta.lastUpdated') ?: $responseInstant,
                        'fhir_history_last_modified_required',
                    );
                    $created = $this->persistResource($sourceId, $resourceType, $resource, $ingestRunId);
                } else {
                    throw new IntegrationProtocolException('fhir_history_entry_invalid');
                }

                $received++;
                $persisted += $created ? 1 : 0;
                $skipped += $created ? 0 : 1;
                $latestUpdated = $this->latest($latestUpdated, $updated);
            }

            $pages++;
            $url = $this->nextUrl($bundle);
        }

        if ($url !== null || $resourceLimitExceeded) {
            throw new IntegrationProtocolException('fhir_poll_limit_exceeded');
        }

        return [
            'pages' => $pages,
            'resourcesReceived' => $received,
            'resourcesPersisted' => $persisted,
            'resourcesSkipped' => $skipped,
            'resourcesDeleted' => $deleted,
            'operationOutcomes' => 0,
            'watermarkAdvanced' => $latestUpdated !== null,
            'latestUpdated' => $latestUpdated,
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

    private function persistDeletion(
        int $sourceId,
        string $resourceType,
        string $fhirId,
        string $versionId,
        CarbonImmutable $deletedAt,
        int $ingestRunId,
    ): bool {
        $deletedAtIso = $deletedAt->toIso8601String();
        $hash = hash('sha256', "FHIR_R4_DELETE\0{$resourceType}\0{$fhirId}\0{$versionId}\0{$deletedAtIso}");
        $idempotencyKey = 'fhir:sha256:'.hash('sha256', "{$resourceType}\0{$fhirId}\0{$versionId}");

        return DB::transaction(function () use (
            $sourceId,
            $resourceType,
            $fhirId,
            $versionId,
            $deletedAt,
            $hash,
            $idempotencyKey,
            $ingestRunId,
        ): bool {
            $existing = DB::table('raw.inbound_messages')
                ->where('source_id', $sourceId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_hash, $hash)) {
                    throw new IntegrationProtocolException('fhir_version_hash_conflict');
                }

                return false;
            }

            $existingVersion = DB::table('fhir.resource_versions')
                ->where('source_id', $sourceId)
                ->where('resource_type', $resourceType)
                ->where('fhir_id', $fhirId)
                ->where('version_id', $versionId)
                ->lockForUpdate()
                ->first();
            if ($existingVersion !== null) {
                if ($existingVersion->deleted_at === null || ! hash_equals((string) $existingVersion->resource_hash, $hash)) {
                    throw new IntegrationProtocolException('fhir_version_state_conflict');
                }

                return false;
            }

            $messageId = DB::table('raw.inbound_messages')->insertGetId([
                'message_uuid' => (string) Str::uuid(),
                'source_id' => $sourceId,
                'ingest_run_id' => $ingestRunId,
                'message_type' => 'FHIR_R4_'.$resourceType.'_DELETE',
                'external_id' => Str::limit("{$resourceType}/{$fhirId}/_history/{$versionId}", 190, ''),
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $hash,
                'payload' => null,
                'payload_object_id' => null,
                'normalized_payload' => null,
                'received_at' => now(),
                'parse_status' => 'persisted',
                'metadata' => json_encode([
                    'protocol' => 'fhir_r4',
                    'interaction' => 'history',
                    'tombstone' => true,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'inbound_message_id');

            $resourceVersionId = DB::table('fhir.resource_versions')->insertGetId([
                'source_id' => $sourceId,
                'resource_type' => $resourceType,
                'fhir_id' => $fhirId,
                'version_id' => $versionId,
                'last_updated' => $deletedAt,
                'resource_hash' => $hash,
                'resource_data' => json_encode((object) [], JSON_THROW_ON_ERROR),
                'payload_object_id' => null,
                'ingest_run_id' => $ingestRunId,
                'deleted_at' => $deletedAt,
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
                    'interaction' => 'history_delete',
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        }, 3);
    }

    /** @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    private function fhirGet(
        int $sourceId,
        string $connectorKey,
        string $accessToken,
        string $baseUrl,
        string $url,
        array $query,
    ): array {
        if ($this->origin($url) !== $this->origin($baseUrl)) {
            throw new IntegrationProtocolException('fhir_pagination_origin_mismatch');
        }
        $connectionTarget = $this->connections->guard($sourceId, $url);
        $response = Http::withToken($accessToken)
            ->accept('application/fhir+json')
            ->withHeaders(['Prefer' => 'handling=strict'])
            ->connectTimeout(5)
            ->timeout((int) config('integrations.health_timeout_seconds', 10) * 3)
            ->withOptions($connectionTarget->httpOptions())
            ->get($url, $query);

        return $this->fhirJson($response, $sourceId, $connectorKey);
    }

    /** @param array<string, mixed> $bundle */
    private function assertBundleType(array $bundle, string $type, string $errorCode): void
    {
        if (($bundle['resourceType'] ?? null) !== 'Bundle' || ($bundle['type'] ?? null) !== $type) {
            throw new IntegrationProtocolException($errorCode);
        }
    }

    /** @param array<string, mixed> $bundle
     * @return list<array<string, mixed>>
     */
    private function bundleEntries(array $bundle, string $errorCode): array
    {
        $entries = $bundle['entry'] ?? [];
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new IntegrationProtocolException($errorCode);
        }
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new IntegrationProtocolException($errorCode);
            }
        }

        return $entries;
    }

    private function assertExpectedResource(mixed $resource, string $resourceType, string $errorCode): void
    {
        if (! is_array($resource)
            || ($resource['resourceType'] ?? null) !== $resourceType
            || ! is_string($resource['id'] ?? null)
            || preg_match('/^[A-Za-z0-9\-.]{1,64}$/', $resource['id']) !== 1) {
            throw new IntegrationProtocolException($errorCode);
        }
    }

    /** @param array<string, mixed> $outcome */
    private function assertNonFatalOperationOutcome(array $outcome): void
    {
        $issues = $outcome['issue'] ?? null;
        if (! is_array($issues) || ! array_is_list($issues) || $issues === []) {
            throw new IntegrationProtocolException('fhir_operation_outcome_invalid');
        }
        foreach ($issues as $issue) {
            $severity = is_array($issue) ? ($issue['severity'] ?? null) : null;
            $code = is_array($issue) ? ($issue['code'] ?? null) : null;
            if (! is_string($severity)
                || ! in_array($severity, ['fatal', 'error', 'warning', 'information'], true)
                || ! is_string($code)
                || $code === '') {
                throw new IntegrationProtocolException('fhir_operation_outcome_invalid');
            }
            if (in_array($severity, ['fatal', 'error'], true)) {
                throw new IntegrationProtocolException('fhir_operation_outcome_error');
            }
        }
    }

    private function instant(mixed $value, string $errorCode): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw new IntegrationProtocolException($errorCode);
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw new IntegrationProtocolException($errorCode);
        }
    }

    private function latest(?CarbonImmutable $latest, CarbonImmutable $candidate): CarbonImmutable
    {
        return $latest === null || $candidate->greaterThan($latest) ? $candidate : $latest;
    }

    private function historyDeletedId(string $requestUrl, string $resourceType, string $baseUrl): string
    {
        if (filter_var($requestUrl, FILTER_VALIDATE_URL)) {
            if ($this->origin($requestUrl) !== $this->origin($baseUrl)) {
                throw new IntegrationProtocolException('fhir_history_delete_origin_mismatch');
            }
            $requestUrl = (string) parse_url($requestUrl, PHP_URL_PATH);
        }
        $path = rawurldecode((string) parse_url($requestUrl, PHP_URL_PATH));
        if (preg_match('#(?:^|/)'.preg_quote($resourceType, '#').'/([A-Za-z0-9\-.]{1,64})$#', trim($path, '/'), $matches) !== 1) {
            throw new IntegrationProtocolException('fhir_history_delete_target_invalid');
        }

        return $matches[1];
    }

    private function historyVersionId(
        mixed $etag,
        string $resourceType,
        string $fhirId,
        CarbonImmutable $deletedAt,
    ): string {
        if ($etag !== null && (! is_string($etag) || preg_match('/^(?:W\/)?"([^"\r\n]{1,120})"$/', $etag, $matches) !== 1)) {
            throw new IntegrationProtocolException('fhir_history_etag_invalid');
        }
        if (is_string($etag)) {
            return $matches[1];
        }

        return 'delete-'.substr(hash('sha256', "{$resourceType}\0{$fhirId}\0{$deletedAt->toIso8601String()}"), 0, 32);
    }

    private function advanceWatermark(
        int $sourceId,
        string $resourceType,
        string $watermarkKind,
        CarbonImmutable $latestUpdated,
        int $pages,
        string $pollingInteraction,
    ): void {
        DB::table('integration.connector_watermarks')->updateOrInsert(
            [
                'source_id' => $sourceId,
                'connector_key' => 'fhir.r4',
                'scope_type' => 'resource_type',
                'scope_key' => $resourceType,
                'watermark_kind' => $watermarkKind,
            ],
            [
                'watermark_value' => $latestUpdated->toIso8601String(),
                'last_success_at' => now(),
                'metadata' => json_encode([
                    'pages' => $pages,
                    'polling_interaction' => $pollingInteraction,
                    'inclusive_boundary' => true,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
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
        if (strlen($response->body()) > 10_485_760) {
            throw new IntegrationProtocolException('fhir_response_too_large');
        }
        $payload = $response->json();
        if (! $response->successful()) {
            if (is_array($payload) && ($payload['resourceType'] ?? null) === 'OperationOutcome') {
                throw new IntegrationProtocolException('fhir_operation_outcome_error');
            }

            throw new IntegrationProtocolException('fhir_http_'.$response->status());
        }
        if (! is_array($payload)) {
            throw new IntegrationProtocolException('fhir_response_not_json');
        }
        if (($payload['resourceType'] ?? null) === 'OperationOutcome') {
            throw new IntegrationProtocolException('fhir_operation_outcome_error');
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
