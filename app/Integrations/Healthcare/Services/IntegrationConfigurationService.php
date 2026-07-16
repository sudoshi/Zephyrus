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
        private readonly SourceConfigurationVersionService $versions,
        private readonly SourceLifecycleService $lifecycle,
        private readonly SourceOnboardingService $onboarding,
        private readonly CredentialAuthorityService $credentialAuthority,
        private readonly NetworkRouteService $networkRoutes,
    ) {}

    /** @return list<array<string, mixed>> */
    public function sources(): array
    {
        return $this->sourceQuery()->orderBy('source.source_name')->get()
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
            $activeStatus = (string) ($payload['active_status'] ?? 'inactive');
            $goLiveStatus = (string) ($payload['go_live_status'] ?? 'not_started');
            $lifecycleState = $this->lifecycle->stateForLegacyStatuses($activeStatus, $goLiveStatus);
            $sourceId = DB::table('integration.sources')->insertGetId([
                'source_uuid' => (string) Str::uuid(),
                'source_key' => $payload['source_key'],
                'organization_id' => $payload['organization_id'],
                'facility_id' => $payload['facility_id'],
                'tenant_key' => $payload['tenant_key'],
                'facility_key' => $payload['facility_key'] ?? null,
                'source_name' => $payload['source_name'],
                'vendor' => $payload['vendor'] ?? null,
                'system_class' => $payload['system_class'],
                'environment' => $payload['environment'],
                'base_url' => $payload['base_url'] ?? null,
                'interface_type' => $payload['interface_type'],
                'active_status' => $activeStatus,
                'fhir_version' => $payload['fhir_version'] ?? null,
                'us_core_version' => $payload['us_core_version'] ?? null,
                'smart_supported' => (bool) ($payload['smart_supported'] ?? false),
                'bulk_supported' => (bool) ($payload['bulk_supported'] ?? false),
                'subscriptions_supported' => (bool) ($payload['subscriptions_supported'] ?? false),
                'contract_status' => $payload['contract_status'],
                'baa_status' => $payload['baa_status'],
                'phi_allowed' => (bool) ($payload['phi_allowed'] ?? false),
                'go_live_status' => $goLiveStatus,
                'lifecycle_state' => $lifecycleState,
                'metadata' => json_encode((object) $metadata, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'source_id');

            $this->versions->initialize(
                (int) $sourceId,
                $actorUserId,
                'Initial integration source configuration.',
                $correlationId,
            );
            $this->lifecycle->initialize(
                (int) $sourceId,
                $actorUserId,
                'Initial integration source lifecycle state.',
            );
            $this->onboarding->initialize(
                (int) $sourceId,
                [
                    'owner_name' => $metadata['owner'] ?? null,
                    'data_classification' => (bool) ($payload['phi_allowed'] ?? false)
                        ? 'restricted_phi'
                        : 'confidential',
                ],
                $actorUserId,
            );

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
            if (in_array((string) $source->lifecycle_state, ['approved', 'scheduled', 'live', 'degraded', 'suspended', 'retired'], true)) {
                throw ValidationException::withMessages([
                    'configuration' => 'Approved, live, suspended, and retired sources require an immutable proposal and governed application workflow.',
                ]);
            }
            $before = $this->sourcePayload($source);
            $reason = (string) ($payload['change_reason'] ?? '');
            $this->versions->reviseAndApply(
                $sourceId,
                $payload,
                (int) ($payload['expected_configuration_version_id'] ?? 0),
                $actorUserId,
                $reason,
                $correlationId,
            );
            $this->lifecycle->resetAfterConfigurationChange($sourceId, $reason, $actorUserId);

            $updated = $this->sourceRow($sourceId);
            $after = $this->sourcePayload($updated);
            $this->audit->record($actorUserId, 'updated', 'source', $sourceId, $source->source_key, $before, $after, $correlationId);

            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function retireSource(
        int $sourceId,
        ?int $actorUserId,
        string $correlationId,
        string $reason,
    ): array {
        return DB::transaction(function () use ($sourceId, $actorUserId, $correlationId, $reason): array {
            $source = $this->sourceRow($sourceId);
            $before = $this->sourcePayload($source);
            if ((string) $source->lifecycle_state !== 'retired') {
                $this->lifecycle->transition(
                    $sourceId,
                    'retired',
                    $reason,
                    $actorUserId,
                );
            }
            DB::table('integration.source_endpoints')->where('source_id', $sourceId)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
            DB::table('integration.source_credentials')
                ->where('source_id', $sourceId)
                ->pluck('source_credential_id')
                ->each(fn (int $credentialId) => $this->credentialAuthority->revoke(
                    $credentialId,
                    $actorUserId,
                    $reason,
                ));

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
        $this->assertChildConfigurationMutable($sourceId, 'endpoint');
        $this->networkRoutes->assertUrlAllowed($sourceId, (string) $payload['url']);
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
        $this->assertChildConfigurationMutable($sourceId, 'endpoint');
        if (isset($payload['url'])) {
            $this->networkRoutes->assertUrlAllowed($sourceId, (string) $payload['url']);
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
        $this->assertChildConfigurationMutable($sourceId, 'endpoint');
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
        $this->assertChildConfigurationMutable($sourceId, 'credential');
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
                'valid_from' => $payload['valid_from'] ?? now(),
                'expires_at' => $payload['expires_at'] ?? null,
                'rotation_overlap_ends_at' => $payload['rotation_overlap_ends_at'] ?? null,
                'credential_state' => (bool) ($payload['is_active'] ?? true) ? 'active' : 'disabled',
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'metadata' => json_encode((object) $this->metadata($payload), JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'source_credential_id');

            $this->credentialAuthority->initialize(
                (int) $credentialId,
                $actorUserId,
                (string) ($payload['change_reason'] ?? 'Initial governed credential reference authority.'),
            );

            $credential = $this->credentialRow($sourceId, (int) $credentialId);
            $after = $this->credentialPayload($credential);
            $this->audit->record($actorUserId, 'created', 'credential_reference', (int) $credentialId, $this->credentialKey($credential), [], $after, $correlationId);

            return $after;
        });
    }

    /** @param array<string, mixed> $payload */
    public function updateCredential(int $sourceId, int $credentialId, array $payload, ?int $actorUserId, string $correlationId): array
    {
        $this->assertChildConfigurationMutable($sourceId, 'credential');
        if (array_key_exists('credential_key', $payload)) {
            $current = $this->credentialRow($sourceId, $credentialId);
            if (! hash_equals((string) $current->credential_key, (string) $payload['credential_key'])) {
                throw ValidationException::withMessages(['credential_key' => 'Credential identity keys are immutable.']);
            }
        }

        return DB::transaction(function () use ($sourceId, $credentialId, $payload, $actorUserId, $correlationId): array {
            $credential = $this->credentialRow($sourceId, $credentialId);
            $before = $this->credentialPayload($credential);
            if (array_key_exists('owner', $payload)) {
                DB::table('integration.source_credentials')->where('source_credential_id', $credentialId)->update([
                    'metadata' => json_encode(
                        (object) $this->mergeMetadata($this->decodeMap($credential->metadata), $payload),
                        JSON_THROW_ON_ERROR,
                    ),
                    'updated_at' => now(),
                ]);
            }
            $updated = $this->credentialRow($sourceId, $credentialId);
            $after = $this->credentialPayload($updated);
            $this->audit->record($actorUserId, 'updated', 'credential_reference', $credentialId, $this->credentialKey($updated), $before, $after, $correlationId);

            return $after;
        });
    }

    /**
     * Apply an independently approved credential rotation. Callers must bind
     * the exact payload to a governed change before invoking this method.
     *
     * @param  array<string, mixed>  $payload
     */
    public function rotateCredential(
        int $sourceId,
        int $credentialId,
        array $payload,
        ?int $actorUserId,
        string $correlationId,
        string $reason,
        string $governedChangeUuid,
    ): array {
        return DB::transaction(function () use (
            $sourceId,
            $credentialId,
            $payload,
            $actorUserId,
            $correlationId,
            $reason,
            $governedChangeUuid,
        ): array {
            $credential = $this->credentialRow($sourceId, $credentialId);
            $before = $this->credentialPayload($credential);
            $this->credentialAuthority->rotate(
                $credentialId,
                $payload,
                $actorUserId,
                $reason,
                $governedChangeUuid,
            );

            $updated = $this->credentialRow($sourceId, $credentialId);
            $after = $this->credentialPayload($updated);
            $this->audit->record($actorUserId, 'updated', 'credential_reference', $credentialId, $this->credentialKey($updated), $before, $after, $correlationId);

            return $after;
        });
    }

    public function deleteCredential(
        int $sourceId,
        int $credentialId,
        ?int $actorUserId,
        string $correlationId,
        string $reason,
    ): void {
        $this->assertChildConfigurationMutable($sourceId, 'credential');
        DB::transaction(function () use ($sourceId, $credentialId, $actorUserId, $correlationId, $reason): void {
            $credential = $this->credentialRow($sourceId, $credentialId);
            $before = $this->credentialPayload($credential);
            $this->credentialAuthority->revoke($credentialId, $actorUserId, $reason);
            $updated = $this->credentialRow($sourceId, $credentialId);
            $after = $this->credentialPayload($updated);
            $this->audit->record($actorUserId, 'revoked', 'credential_reference', $credentialId, $this->credentialKey($credential), $before, $after, $correlationId);
        });
    }

    private function sourceRow(int $sourceId): object
    {
        return $this->sourceQuery()->where('source.source_id', $sourceId)->firstOrFail();
    }

    public function assertChildConfigurationMutable(int $sourceId, string $entity): void
    {
        $source = $this->sourceRow($sourceId);
        if (in_array((string) $source->lifecycle_state, [
            'approved', 'scheduled', 'live', 'degraded', 'suspended', 'retired',
        ], true)) {
            throw ValidationException::withMessages([
                'configuration' => "The {$entity} authority cannot change while the source is protected. Move the source through a reasoned reconfiguration and validation cycle first.",
            ]);
        }
    }

    private function sourceQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('integration.sources as source')
            ->leftJoin('hosp_org.organizations as organization', 'organization.organization_id', '=', 'source.organization_id')
            ->leftJoin('hosp_org.facilities as facility', 'facility.facility_id', '=', 'source.facility_id')
            ->leftJoin(
                'integration.source_configuration_versions as configuration_version',
                'configuration_version.source_configuration_version_id',
                '=',
                'source.current_configuration_version_id',
            )
            ->select([
                'source.*',
                'organization.name as organization_name',
                'facility.facility_name',
                'facility.is_active as facility_is_active',
                'configuration_version.version_number as configuration_version_number',
                'configuration_version.configuration_sha256',
                'configuration_version.created_at as configuration_version_created_at',
            ]);
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
            'organizationId' => isset($source->organization_id) ? (int) $source->organization_id : null,
            'organizationName' => $source->organization_name ?? null,
            'facilityId' => isset($source->facility_id) ? (int) $source->facility_id : null,
            'facilityName' => $source->facility_name ?? null,
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
            'lifecycleState' => $source->lifecycle_state,
            'lifecycleChangedAtIso' => $source->lifecycle_changed_at !== null ? (string) $source->lifecycle_changed_at : null,
            'currentConfigurationVersionId' => $source->current_configuration_version_id !== null
                ? (int) $source->current_configuration_version_id
                : null,
            'currentConfigurationVersionNumber' => $source->configuration_version_number !== null
                ? (int) $source->configuration_version_number
                : null,
            'currentConfigurationSha256' => $source->configuration_sha256,
            'currentConfigurationCreatedAtIso' => $source->configuration_version_created_at !== null
                ? (string) $source->configuration_version_created_at
                : null,
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
            'status' => $credential->credential_state ?? ($credential->is_active ? 'configured' : 'disabled'),
            'credentialState' => $credential->credential_state ?? ($credential->is_active ? 'active' : 'disabled'),
            'currentCredentialVersionId' => $credential->current_credential_version_id !== null
                ? (int) $credential->current_credential_version_id
                : null,
            'secretReferenceConfigured' => filled($credential->secret_ref),
            'certificateReferenceConfigured' => filled($credential->certificate_ref),
            'jwksConfigured' => filled($credential->jwks_uri),
            'rotatesAtIso' => filled($credential->rotates_at) ? CarbonImmutable::parse($credential->rotates_at)->toIso8601String() : null,
            'validFromIso' => filled($credential->valid_from) ? CarbonImmutable::parse($credential->valid_from)->toIso8601String() : null,
            'expiresAtIso' => filled($credential->expires_at) ? CarbonImmutable::parse($credential->expires_at)->toIso8601String() : null,
            'rotationOverlapEndsAtIso' => filled($credential->rotation_overlap_ends_at)
                ? CarbonImmutable::parse($credential->rotation_overlap_ends_at)->toIso8601String()
                : null,
            'revokedAtIso' => filled($credential->revoked_at) ? CarbonImmutable::parse($credential->revoked_at)->toIso8601String() : null,
            'lastUsedAtIso' => filled($credential->last_used_at) ? CarbonImmutable::parse($credential->last_used_at)->toIso8601String() : null,
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
