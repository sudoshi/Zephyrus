<?php

namespace App\Integrations\Healthcare\Services;

use App\Security\Network\IntegrationUrlPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntegrationConfigurationService
{
    public function __construct(
        private readonly IntegrationUrlPolicy $urlPolicy,
        private readonly IntegrationConfigurationAuditService $audit,
    ) {}

    /** @return list<array<string, mixed>> */
    public function sources(): array
    {
        return DB::table('integration.sources')->orderBy('source_name')->get()
            ->map(fn (object $source): array => $this->sourcePayload($source))
            ->all();
    }

    /** @return array<string, mixed> */
    public function source(int $sourceId): array
    {
        return $this->sourcePayload($this->sourceRow($sourceId));
    }

    /** @param array<string, mixed> $payload */
    public function createSource(array $payload, ?int $actorUserId, string $correlationId): array
    {
        if (DB::table('integration.sources')->where('source_key', $payload['source_key'])->exists()) {
            throw ValidationException::withMessages(['source_key' => 'This source key is already configured.']);
        }
        if (filled($payload['base_url'] ?? null)) {
            $this->urlPolicy->assertSafe((string) $payload['base_url']);
        }

        return DB::transaction(function () use ($payload, $actorUserId, $correlationId): array {
            $metadata = $this->metadata($payload);
            $sourceId = DB::table('integration.sources')->insertGetId([
                'source_uuid' => (string) Str::uuid(),
                'source_key' => $payload['source_key'],
                'tenant_key' => $payload['tenant_key'],
                'facility_key' => $payload['facility_key'] ?? null,
                'source_name' => $payload['source_name'],
                'vendor' => $payload['vendor'] ?? null,
                'system_class' => $payload['system_class'],
                'environment' => $payload['environment'],
                'base_url' => $payload['base_url'] ?? null,
                'interface_type' => $payload['interface_type'],
                'active_status' => $payload['active_status'],
                'fhir_version' => $payload['fhir_version'] ?? null,
                'us_core_version' => $payload['us_core_version'] ?? null,
                'smart_supported' => (bool) ($payload['smart_supported'] ?? false),
                'bulk_supported' => (bool) ($payload['bulk_supported'] ?? false),
                'subscriptions_supported' => (bool) ($payload['subscriptions_supported'] ?? false),
                'contract_status' => $payload['contract_status'],
                'baa_status' => $payload['baa_status'],
                'phi_allowed' => (bool) ($payload['phi_allowed'] ?? false),
                'go_live_status' => $payload['go_live_status'],
                'metadata' => json_encode((object) $metadata, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'source_id');

            $source = $this->sourceRow((int) $sourceId);
            $after = $this->sourcePayload($source);
            $this->audit->record($actorUserId, 'created', 'source', (int) $sourceId, $source->source_key, [], $after, $correlationId);

            return $after;
        });
    }

    /** @param array<string, mixed> $payload */
    public function updateSource(int $sourceId, array $payload, ?int $actorUserId, string $correlationId): array
    {
        if (filled($payload['base_url'] ?? null)) {
            $this->urlPolicy->assertSafe((string) $payload['base_url']);
        }

        return DB::transaction(function () use ($sourceId, $payload, $actorUserId, $correlationId): array {
            $source = $this->sourceRow($sourceId);
            $before = $this->sourcePayload($source);
            $columnMap = [
                'source_name', 'tenant_key', 'facility_key', 'vendor', 'system_class',
                'environment', 'base_url', 'interface_type', 'active_status', 'fhir_version',
                'us_core_version', 'smart_supported', 'bulk_supported', 'subscriptions_supported',
                'contract_status', 'baa_status', 'phi_allowed', 'go_live_status',
            ];
            $updates = collect($payload)->only($columnMap)->all();
            if (array_key_exists('owner', $payload) || array_key_exists('expected_cadence_minutes', $payload)) {
                $updates['metadata'] = json_encode(
                    (object) $this->mergeMetadata($this->decodeMap($source->metadata), $payload),
                    JSON_THROW_ON_ERROR,
                );
            }
            $updates['updated_at'] = now();
            DB::table('integration.sources')->where('source_id', $sourceId)->update($updates);

            $updated = $this->sourceRow($sourceId);
            $after = $this->sourcePayload($updated);
            $this->audit->record($actorUserId, 'updated', 'source', $sourceId, $source->source_key, $before, $after, $correlationId);

            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function retireSource(int $sourceId, ?int $actorUserId, string $correlationId): array
    {
        return DB::transaction(function () use ($sourceId, $actorUserId, $correlationId): array {
            $source = $this->sourceRow($sourceId);
            $before = $this->sourcePayload($source);
            DB::table('integration.sources')->where('source_id', $sourceId)->update([
                'active_status' => 'disabled',
                'go_live_status' => 'retired',
                'updated_at' => now(),
            ]);
            DB::table('integration.source_endpoints')->where('source_id', $sourceId)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
            DB::table('integration.source_credentials')->where('source_id', $sourceId)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

            $updated = $this->sourceRow($sourceId);
            $after = $this->sourcePayload($updated);
            $this->audit->record($actorUserId, 'retired', 'source', $sourceId, $source->source_key, $before, $after, $correlationId);

            return $after;
        });
    }

    /** @return list<array<string, mixed>> */
    public function endpoints(int $sourceId): array
    {
        $this->sourceRow($sourceId);

        return DB::table('integration.source_endpoints')->where('source_id', $sourceId)
            ->orderBy('endpoint_type')->get()
            ->map(fn (object $endpoint): array => $this->endpointPayload($endpoint))
            ->all();
    }

    /** @param array<string, mixed> $payload */
    public function createEndpoint(int $sourceId, array $payload, ?int $actorUserId, string $correlationId): array
    {
        $this->sourceRow($sourceId);
        $this->urlPolicy->assertSafe((string) $payload['url']);
        $duplicate = DB::table('integration.source_endpoints')
            ->where('source_id', $sourceId)
            ->where('endpoint_type', $payload['endpoint_type'])
            ->where('url', $payload['url'])
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['url' => 'This endpoint is already configured for the source.']);
        }

        return DB::transaction(function () use ($sourceId, $payload, $actorUserId, $correlationId): array {
            $endpointId = DB::table('integration.source_endpoints')->insertGetId([
                'source_id' => $sourceId,
                'endpoint_type' => $payload['endpoint_type'],
                'url' => $payload['url'],
                'auth_type' => $payload['auth_type'] ?? null,
                'tls_mode' => $payload['tls_mode'] ?? null,
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'metadata' => json_encode((object) $this->metadata($payload), JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'source_endpoint_id');

            $endpoint = $this->endpointRow($sourceId, (int) $endpointId);
            $after = $this->endpointPayload($endpoint);
            $this->audit->record($actorUserId, 'created', 'endpoint', (int) $endpointId, $this->endpointKey($endpoint), [], $after, $correlationId);

            return $after;
        });
    }

    /** @param array<string, mixed> $payload */
    public function updateEndpoint(int $sourceId, int $endpointId, array $payload, ?int $actorUserId, string $correlationId): array
    {
        if (isset($payload['url'])) {
            $this->urlPolicy->assertSafe((string) $payload['url']);
        }

        return DB::transaction(function () use ($sourceId, $endpointId, $payload, $actorUserId, $correlationId): array {
            $endpoint = $this->endpointRow($sourceId, $endpointId);
            $before = $this->endpointPayload($endpoint);
            $updates = collect($payload)->only(['endpoint_type', 'url', 'auth_type', 'tls_mode', 'is_active'])->all();
            if (array_key_exists('owner', $payload) || array_key_exists('expected_cadence_minutes', $payload)) {
                $updates['metadata'] = json_encode(
                    (object) $this->mergeMetadata($this->decodeMap($endpoint->metadata), $payload),
                    JSON_THROW_ON_ERROR,
                );
            }
            $updates['updated_at'] = now();
            DB::table('integration.source_endpoints')->where('source_endpoint_id', $endpointId)->update($updates);

            $updated = $this->endpointRow($sourceId, $endpointId);
            $after = $this->endpointPayload($updated);
            $this->audit->record($actorUserId, 'updated', 'endpoint', $endpointId, $this->endpointKey($updated), $before, $after, $correlationId);

            return $after;
        });
    }

    public function deleteEndpoint(int $sourceId, int $endpointId, ?int $actorUserId, string $correlationId): void
    {
        DB::transaction(function () use ($sourceId, $endpointId, $actorUserId, $correlationId): void {
            $endpoint = $this->endpointRow($sourceId, $endpointId);
            $before = $this->endpointPayload($endpoint);
            DB::table('integration.source_endpoints')->where('source_endpoint_id', $endpointId)->delete();
            $this->audit->record($actorUserId, 'deleted', 'endpoint', $endpointId, $this->endpointKey($endpoint), $before, [], $correlationId);
        });
    }

    /** @return list<array<string, mixed>> */
    public function credentials(int $sourceId): array
    {
        $this->sourceRow($sourceId);

        return DB::table('integration.source_credentials')->where('source_id', $sourceId)
            ->orderBy('credential_key')->get()
            ->map(fn (object $credential): array => $this->credentialPayload($credential))
            ->all();
    }

    /** @param array<string, mixed> $payload */
    public function createCredential(int $sourceId, array $payload, ?int $actorUserId, string $correlationId): array
    {
        $this->sourceRow($sourceId);
        if (filled($payload['jwks_uri'] ?? null)) {
            $this->urlPolicy->assertSafe((string) $payload['jwks_uri']);
        }
        if (DB::table('integration.source_credentials')->where('source_id', $sourceId)->where('credential_key', $payload['credential_key'])->exists()) {
            throw ValidationException::withMessages(['credential_key' => 'This credential key already exists for the source.']);
        }

        return DB::transaction(function () use ($sourceId, $payload, $actorUserId, $correlationId): array {
            $credentialId = DB::table('integration.source_credentials')->insertGetId([
                'source_id' => $sourceId,
                'credential_key' => $payload['credential_key'],
                'credential_type' => $payload['credential_type'],
                'secret_ref' => $payload['secret_ref'] ?? null,
                'certificate_ref' => $payload['certificate_ref'] ?? null,
                'jwks_uri' => $payload['jwks_uri'] ?? null,
                'rotates_at' => $payload['rotates_at'] ?? null,
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'metadata' => json_encode((object) $this->metadata($payload), JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'source_credential_id');

            $credential = $this->credentialRow($sourceId, (int) $credentialId);
            $after = $this->credentialPayload($credential);
            $this->audit->record($actorUserId, 'created', 'credential_reference', (int) $credentialId, $this->credentialKey($credential), [], $after, $correlationId);

            return $after;
        });
    }

    /** @param array<string, mixed> $payload */
    public function updateCredential(int $sourceId, int $credentialId, array $payload, ?int $actorUserId, string $correlationId): array
    {
        if (filled($payload['jwks_uri'] ?? null)) {
            $this->urlPolicy->assertSafe((string) $payload['jwks_uri']);
        }

        return DB::transaction(function () use ($sourceId, $credentialId, $payload, $actorUserId, $correlationId): array {
            $credential = $this->credentialRow($sourceId, $credentialId);
            $before = $this->credentialPayload($credential);
            $updates = collect($payload)->only([
                'credential_key', 'credential_type', 'secret_ref', 'certificate_ref',
                'jwks_uri', 'rotates_at', 'is_active',
            ])->all();
            if (array_key_exists('owner', $payload)) {
                $updates['metadata'] = json_encode(
                    (object) $this->mergeMetadata($this->decodeMap($credential->metadata), $payload),
                    JSON_THROW_ON_ERROR,
                );
            }
            $updates['updated_at'] = now();
            DB::table('integration.source_credentials')->where('source_credential_id', $credentialId)->update($updates);

            $updated = $this->credentialRow($sourceId, $credentialId);
            $after = $this->credentialPayload($updated);
            $this->audit->record($actorUserId, 'updated', 'credential_reference', $credentialId, $this->credentialKey($updated), $before, $after, $correlationId);

            return $after;
        });
    }

    public function deleteCredential(int $sourceId, int $credentialId, ?int $actorUserId, string $correlationId): void
    {
        DB::transaction(function () use ($sourceId, $credentialId, $actorUserId, $correlationId): void {
            $credential = $this->credentialRow($sourceId, $credentialId);
            $before = $this->credentialPayload($credential);
            DB::table('integration.source_credentials')->where('source_credential_id', $credentialId)->delete();
            $this->audit->record($actorUserId, 'deleted', 'credential_reference', $credentialId, $this->credentialKey($credential), $before, [], $correlationId);
        });
    }

    private function sourceRow(int $sourceId): object
    {
        return DB::table('integration.sources')->where('source_id', $sourceId)->firstOrFail();
    }

    private function endpointRow(int $sourceId, int $endpointId): object
    {
        return DB::table('integration.source_endpoints')
            ->where('source_id', $sourceId)
            ->where('source_endpoint_id', $endpointId)
            ->firstOrFail();
    }

    private function credentialRow(int $sourceId, int $credentialId): object
    {
        return DB::table('integration.source_credentials')
            ->where('source_id', $sourceId)
            ->where('source_credential_id', $credentialId)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function sourcePayload(object $source): array
    {
        $metadata = $this->decodeMap($source->metadata);

        return [
            'sourceId' => (int) $source->source_id,
            'sourceKey' => $source->source_key,
            'sourceName' => $source->source_name,
            'tenantKey' => $source->tenant_key,
            'facilityKey' => $source->facility_key,
            'vendor' => $source->vendor,
            'systemClass' => $source->system_class,
            'environment' => $source->environment,
            'interfaceType' => $source->interface_type,
            'activeStatus' => $source->active_status,
            'fhirVersion' => $source->fhir_version,
            'usCoreVersion' => $source->us_core_version,
            'smartSupported' => (bool) $source->smart_supported,
            'bulkSupported' => (bool) $source->bulk_supported,
            'subscriptionsSupported' => (bool) $source->subscriptions_supported,
            'contractStatus' => $source->contract_status,
            'baaStatus' => $source->baa_status,
            'phiAllowed' => (bool) $source->phi_allowed,
            'goLiveStatus' => $source->go_live_status,
            'baseUrlConfigured' => filled($source->base_url),
            'baseUrlOrigin' => $this->urlOrigin($source->base_url),
            'owner' => $metadata['owner'] ?? null,
            'expectedCadenceMinutes' => isset($metadata['expected_cadence_minutes']) ? (int) $metadata['expected_cadence_minutes'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function endpointPayload(object $endpoint): array
    {
        $metadata = $this->decodeMap($endpoint->metadata);

        return [
            'endpointId' => (int) $endpoint->source_endpoint_id,
            'sourceId' => (int) $endpoint->source_id,
            'endpointType' => $endpoint->endpoint_type,
            'urlConfigured' => filled($endpoint->url),
            'urlOrigin' => $this->urlOrigin($endpoint->url),
            'authType' => $endpoint->auth_type,
            'tlsMode' => $endpoint->tls_mode,
            'isActive' => (bool) $endpoint->is_active,
            'owner' => $metadata['owner'] ?? null,
            'expectedCadenceMinutes' => isset($metadata['expected_cadence_minutes']) ? (int) $metadata['expected_cadence_minutes'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function credentialPayload(object $credential): array
    {
        $metadata = $this->decodeMap($credential->metadata);

        return [
            'credentialId' => (int) $credential->source_credential_id,
            'sourceId' => (int) $credential->source_id,
            'credentialKey' => $credential->credential_key,
            'credentialType' => $credential->credential_type,
            'status' => $credential->is_active ? 'configured' : 'disabled',
            'secretReferenceConfigured' => filled($credential->secret_ref),
            'certificateReferenceConfigured' => filled($credential->certificate_ref),
            'jwksConfigured' => filled($credential->jwks_uri),
            'rotatesAtIso' => filled($credential->rotates_at) ? CarbonImmutable::parse($credential->rotates_at)->toIso8601String() : null,
            'owner' => $metadata['owner'] ?? null,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function metadata(array $payload): array
    {
        return array_filter([
            'owner' => $payload['owner'] ?? null,
            'expected_cadence_minutes' => $payload['expected_cadence_minutes'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeMetadata(array $existing, array $payload): array
    {
        foreach (['owner', 'expected_cadence_minutes'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            if ($payload[$key] === null || $payload[$key] === '') {
                unset($existing[$key]);
            } else {
                $existing[$key] = $payload[$key];
            }
        }

        return $existing;
    }

    /** @return array<string, mixed> */
    private function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : [];

        return is_array($decoded) ? $decoded : [];
    }

    private function urlOrigin(?string $url): ?string
    {
        if (! filled($url)) {
            return null;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        return is_string($scheme) && is_string($host)
            ? strtolower($scheme).'://'.strtolower($host).($port ? ':'.$port : '')
            : null;
    }

    private function endpointKey(object $endpoint): string
    {
        return $endpoint->source_id.':'.$endpoint->endpoint_type.':'.$endpoint->source_endpoint_id;
    }

    private function credentialKey(object $credential): string
    {
        return $credential->source_id.':'.$credential->credential_key;
    }
}
