<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Models\Integration\Source;
use App\Security\Network\IntegrationUrlPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class EpicSmartFhirClient
{
    private const RESOURCE_TYPES = ['Encounter', 'Location'];

    public function __construct(
        private readonly IntegrationUrlPolicy $urlPolicy,
        private readonly IntegrationSecretReferenceResolver $secrets,
    ) {}

    /** @return array<string, mixed> */
    public function poll(int $sourceId, string $resourceType, int $ingestRunId): array
    {
        if (! in_array($resourceType, self::RESOURCE_TYPES, true)) {
            throw new IntegrationProtocolException('fhir_resource_not_allowed');
        }

        $source = Source::query()->findOrFail($sourceId);
        if (strtolower((string) $source->vendor) !== 'epic' || ! str_contains(strtolower((string) $source->interface_type), 'fhir')) {
            throw new IntegrationProtocolException('epic_fhir_source_required');
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
        $supported = DB::table('integration.source_capabilities')
            ->where('source_id', $sourceId)
            ->where('capability_type', 'fhir_resource')
            ->where('resource_type', $resourceType)
            ->where('supported', true)
            ->exists();
        if (! $supported) {
            throw new IntegrationProtocolException('fhir_resource_not_supported');
        }

        $credential = DB::table('integration.smart_backend_credentials')
            ->where('source_id', $sourceId)->orderBy('smart_backend_credential_id')->first();
        if (! $credential || ! filled($credential->client_id) || ! filled($credential->jwks_secret_ref) || ! filled($credential->token_url)) {
            throw new IntegrationProtocolException('smart_credentials_required');
        }

        $token = $this->accessToken($credential);
        $baseUrl = rtrim((string) $connection->base_url, '/');
        $this->urlPolicy->assertSafe($baseUrl);
        $since = DB::table('integration.connector_watermarks')
            ->where('source_id', $sourceId)
            ->where('connector_key', 'epic.fhir-r4')
            ->where('scope_type', 'resource_type')
            ->where('scope_key', $resourceType)
            ->where('watermark_kind', 'last_updated')
            ->value('watermark_value');

        $url = $baseUrl.'/'.$resourceType;
        $query = ['_count' => 100, '_sort' => '_lastUpdated'];
        if (is_string($since) && $since !== '') {
            $query['_lastUpdated'] = 'gt'.$since;
        }

        $pages = 0;
        $received = 0;
        $persisted = 0;
        $skipped = 0;
        $resourceLimitExceeded = false;
        $latestUpdated = is_string($since) && $since !== '' ? CarbonImmutable::parse($since) : null;
        $pageLimit = (int) config('integrations.fhir_page_limit', 10);
        $resourceLimit = (int) config('integrations.fhir_resource_limit', 1000);

        while ($url !== null && $pages < $pageLimit && $received < $resourceLimit) {
            $this->urlPolicy->assertSafe($url);
            if ($this->origin($url) !== $this->origin($baseUrl)) {
                throw new IntegrationProtocolException('fhir_pagination_origin_mismatch');
            }
            $response = Http::withToken($token['accessToken'])
                ->accept('application/fhir+json')
                ->connectTimeout(5)
                ->timeout((int) config('integrations.health_timeout_seconds', 10) * 3)
                ->withOptions(['allow_redirects' => false])
                ->get($url, $pages === 0 ? $query : []);
            $bundle = $this->fhirJson($response);
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
                    'connector_key' => 'epic.fhir-r4',
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
    private function accessToken(object $credential): array
    {
        $tokenUrl = (string) $credential->token_url;
        $this->urlPolicy->assertSafe($tokenUrl);
        try {
            $privateKey = $this->secrets->resolve((string) $credential->jwks_secret_ref);
        } catch (IntegrationCredentialException $exception) {
            throw new IntegrationProtocolException($exception->errorCode);
        }

        $metadata = $this->decodeMap($credential->metadata);
        $header = array_filter([
            'alg' => 'RS384',
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
        if (! openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA384)) {
            throw new IntegrationProtocolException('client_assertion_signing_failed');
        }
        $assertion = $signingInput.'.'.$this->base64Url($signature);
        $scopes = array_values(array_filter($this->decodeList($credential->scope_payload), 'is_string'));

        $response = Http::asForm()
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout((int) config('integrations.health_timeout_seconds', 10) * 2)
            ->withOptions(['allow_redirects' => false])
            ->post($tokenUrl, [
                'grant_type' => 'client_credentials',
                'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                'client_assertion' => $assertion,
                'scope' => implode(' ', $scopes),
            ]);
        if ($response->redirect()) {
            throw new IntegrationProtocolException('smart_token_redirect_rejected');
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

        return DB::transaction(function () use ($sourceId, $resourceType, $resource, $resourceJson, $hash, $fhirId, $versionId, $idempotencyKey, $ingestRunId): bool {
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
                'payload' => $resourceJson,
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
                'resource_data' => $resourceJson,
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
    }

    /** @return array<string, mixed> */
    private function fhirJson(Response $response): array
    {
        if ($response->redirect()) {
            throw new IntegrationProtocolException('fhir_redirect_rejected');
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

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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
