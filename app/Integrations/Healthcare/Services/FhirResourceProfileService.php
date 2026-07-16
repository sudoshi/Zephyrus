<?php

namespace App\Integrations\Healthcare\Services;

use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Governs the difference between "the server advertises this resource" and
 * "Zephyrus is approved and configured to poll this resource".
 */
final class FhirResourceProfileService
{
    public function __construct(private readonly IntegrationConfigurationService $configuration) {}

    public function ensureConfigured(
        int $sourceId,
        string $resourceType,
        ?int $actorUserId = null,
        string $reasonCode = 'fhir_resource_profile_configured',
        ?string $correlationUuid = null,
        string $changeReason = 'Configure the governed FHIR resource profile.',
    ): object {
        $resourceType = $this->resourceType($resourceType);

        return DB::transaction(function () use ($sourceId, $resourceType, $actorUserId, $reasonCode, $correlationUuid, $changeReason): object {
            $source = DB::table('integration.sources')->where('source_id', $sourceId)->lockForUpdate()->first();
            if ($source === null) {
                throw new InvalidArgumentException('fhir_resource_profile_source_not_found');
            }

            $existing = DB::table('integration.fhir_resource_profiles')
                ->where('source_id', $sourceId)
                ->where('resource_type', $resourceType)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
            $this->assertProfileMutable($sourceId);

            $profileId = DB::table('integration.fhir_resource_profiles')->insertGetId([
                'profile_uuid' => (string) Str::uuid7(),
                'source_id' => $sourceId,
                'configuration_version_id' => $source->current_configuration_version_id,
                'resource_type' => $resourceType,
                'canonical_profile_url' => null,
                'canonical_profile_version' => null,
                'profile_status' => 'configured',
                'poll_enabled' => true,
                'polling_interaction' => 'search',
                'cadence_minutes' => max(1, min(10080, (int) config('integrations.fhir_poll_cadence_minutes', 15))),
                'page_size' => max(1, min(1000, (int) config('integrations.fhir_page_size', 100))),
                'page_limit' => max(1, min(100, (int) config('integrations.fhir_page_limit', 10))),
                'resource_limit' => max(1, min(100000, (int) config('integrations.fhir_resource_limit', 1000))),
                'version_number' => 1,
                'reason_code' => $this->reasonCode($reasonCode),
                'change_reason' => $this->changeReason($changeReason),
                'configured_by_user_id' => $actorUserId,
                'correlation_uuid' => $this->correlation($correlationUuid),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'fhir_resource_profile_id');

            return DB::table('integration.fhir_resource_profiles')
                ->where('fhir_resource_profile_id', $profileId)
                ->firstOrFail();
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function configure(
        int $sourceId,
        string $resourceType,
        array $input,
        ?int $actorUserId,
        string $changeReason,
        ?string $correlationUuid = null,
    ): object {
        $resourceType = $this->resourceType($resourceType);
        $changeReason = $this->changeReason($changeReason);
        $this->assertProfileMutable($sourceId);

        DB::transaction(function () use ($sourceId, $resourceType, $input, $actorUserId, $changeReason, $correlationUuid): void {
            $source = DB::table('integration.sources')->where('source_id', $sourceId)->lockForUpdate()->first();
            if ($source === null || ! str_contains(strtolower((string) $source->interface_type), 'fhir')) {
                throw new InvalidArgumentException('fhir_resource_profile_source_required');
            }

            $existing = DB::table('integration.fhir_resource_profiles')
                ->where('source_id', $sourceId)
                ->where('resource_type', $resourceType)
                ->lockForUpdate()
                ->first();
            if ((string) ($existing?->profile_status ?? '') === 'retired') {
                throw new InvalidArgumentException('fhir_resource_profile_retired');
            }

            $pollEnabled = (bool) ($input['poll_enabled'] ?? $existing?->poll_enabled ?? true);
            $values = [
                'configuration_version_id' => $source->current_configuration_version_id,
                'canonical_profile_url' => $this->optionalString(
                    array_key_exists('canonical_profile_url', $input)
                        ? $input['canonical_profile_url']
                        : $existing?->canonical_profile_url,
                    500,
                ),
                'canonical_profile_version' => $this->optionalString(
                    array_key_exists('canonical_profile_version', $input)
                        ? $input['canonical_profile_version']
                        : $existing?->canonical_profile_version,
                    80,
                ),
                'profile_status' => $pollEnabled
                    ? ((string) ($existing?->profile_status ?? '') === 'enabled' ? 'enabled' : 'configured')
                    : 'suspended',
                'poll_enabled' => $pollEnabled,
                'polling_interaction' => 'search',
                'cadence_minutes' => $this->boundedInteger($input['cadence_minutes'] ?? $existing?->cadence_minutes ?? 15, 1, 10080),
                'page_size' => $this->boundedInteger($input['page_size'] ?? $existing?->page_size ?? 100, 1, 1000),
                'page_limit' => $this->boundedInteger($input['page_limit'] ?? $existing?->page_limit ?? 10, 1, 100),
                'resource_limit' => $this->boundedInteger($input['resource_limit'] ?? $existing?->resource_limit ?? 1000, 1, 100000),
                'reason_code' => $existing === null ? 'fhir_resource_profile_configured' : 'fhir_resource_profile_updated',
                'change_reason' => $changeReason,
                'configured_by_user_id' => $actorUserId,
                'correlation_uuid' => $this->correlation($correlationUuid),
                'updated_at' => now(),
            ];

            if ($existing === null) {
                DB::table('integration.fhir_resource_profiles')->insert([
                    'profile_uuid' => (string) Str::uuid7(),
                    'source_id' => $sourceId,
                    'resource_type' => $resourceType,
                    'version_number' => 1,
                    'created_at' => now(),
                    ...$values,
                ]);

                return;
            }

            $changed = collect($values)->except([
                'reason_code',
                'change_reason',
                'configured_by_user_id',
                'correlation_uuid',
                'updated_at',
            ])->contains(fn (mixed $value, string $key): bool => $this->different($existing->{$key}, $value));
            if (! $changed) {
                return;
            }

            DB::table('integration.fhir_resource_profiles')
                ->where('fhir_resource_profile_id', $existing->fhir_resource_profile_id)
                ->update([
                    ...$values,
                    'version_number' => (int) $existing->version_number + 1,
                ]);
        }, 3);

        $advertised = DB::table('integration.source_capabilities')
            ->where('source_id', $sourceId)
            ->where('capability_type', 'fhir_resource')
            ->where('supported', true)
            ->pluck('resource_type')
            ->map(fn (mixed $value): string => (string) $value)
            ->all();
        $this->reconcileCapabilities($sourceId, $advertised);

        return DB::table('integration.fhir_resource_profiles')
            ->where('source_id', $sourceId)
            ->where('resource_type', $resourceType)
            ->firstOrFail();
    }

    public function retire(
        int $sourceId,
        int $profileId,
        ?int $actorUserId,
        string $changeReason,
        ?string $correlationUuid = null,
    ): object {
        $changeReason = $this->changeReason($changeReason);
        $this->assertProfileMutable($sourceId);

        return DB::transaction(function () use ($sourceId, $profileId, $actorUserId, $changeReason, $correlationUuid): object {
            DB::table('integration.sources')->where('source_id', $sourceId)->lockForUpdate()->firstOrFail();
            $profile = DB::table('integration.fhir_resource_profiles')
                ->where('source_id', $sourceId)
                ->where('fhir_resource_profile_id', $profileId)
                ->lockForUpdate()
                ->firstOrFail();
            if ((string) $profile->profile_status === 'retired') {
                return $profile;
            }

            DB::table('integration.fhir_resource_profiles')
                ->where('fhir_resource_profile_id', $profileId)
                ->update([
                    'profile_status' => 'retired',
                    'poll_enabled' => false,
                    'version_number' => (int) $profile->version_number + 1,
                    'reason_code' => 'fhir_resource_profile_retired',
                    'change_reason' => $changeReason,
                    'configured_by_user_id' => $actorUserId,
                    'correlation_uuid' => $this->correlation($correlationUuid),
                    'updated_at' => now(),
                ]);

            return DB::table('integration.fhir_resource_profiles')
                ->where('fhir_resource_profile_id', $profileId)
                ->firstOrFail();
        }, 3);
    }

    /** @param list<string> $advertisedResourceTypes */
    public function reconcileCapabilities(int $sourceId, array $advertisedResourceTypes): void
    {
        $advertised = [];
        foreach ($advertisedResourceTypes as $resourceType) {
            $advertised[$this->resourceType($resourceType)] = true;
        }

        DB::transaction(function () use ($sourceId, $advertised): void {
            DB::table('integration.sources')->where('source_id', $sourceId)->lockForUpdate()->firstOrFail();
            $profiles = DB::table('integration.fhir_resource_profiles')
                ->where('source_id', $sourceId)
                ->orderBy('fhir_resource_profile_id')
                ->lockForUpdate()
                ->get();

            foreach ($profiles as $profile) {
                if ((string) $profile->profile_status === 'retired') {
                    continue;
                }

                $advertisedNow = isset($advertised[(string) $profile->resource_type]);
                $scopeAuthorized = $this->scopeAllows($sourceId, (string) $profile->resource_type);
                $nextStatus = match (true) {
                    $advertisedNow && $scopeAuthorized && (bool) $profile->poll_enabled => 'enabled',
                    (! $advertisedNow || ! $scopeAuthorized) && (string) $profile->profile_status === 'enabled' => 'suspended',
                    default => (string) $profile->profile_status,
                };
                if ($nextStatus === (string) $profile->profile_status) {
                    continue;
                }

                DB::table('integration.fhir_resource_profiles')
                    ->where('fhir_resource_profile_id', $profile->fhir_resource_profile_id)
                    ->update([
                        'profile_status' => $nextStatus,
                        'version_number' => (int) $profile->version_number + 1,
                        'reason_code' => match (true) {
                            $nextStatus === 'enabled' => 'capability_and_scope_confirmed',
                            ! $advertisedNow => 'capability_missing_profile_suspended',
                            default => 'smart_scope_missing_profile_suspended',
                        },
                        'change_reason' => match (true) {
                            $nextStatus === 'enabled' => 'Enable polling after capability and SMART scope confirmation.',
                            ! $advertisedNow => 'Suspend polling because the server capability is no longer advertised.',
                            default => 'Suspend polling because the required SMART system read scope is unavailable.',
                        },
                        'configured_by_user_id' => null,
                        'correlation_uuid' => null,
                        'updated_at' => now(),
                    ]);
            }
        }, 3);
    }

    public function requirePollable(int $sourceId, string $resourceType): object
    {
        $resourceType = $this->resourceType($resourceType);
        $profile = DB::table('integration.fhir_resource_profiles')
            ->where('source_id', $sourceId)
            ->where('resource_type', $resourceType)
            ->first();
        if ($profile === null || ! $profile->poll_enabled) {
            throw new IntegrationProtocolException('fhir_resource_profile_not_enabled');
        }

        $advertised = DB::table('integration.source_capabilities')
            ->where('source_id', $sourceId)
            ->where('capability_type', 'fhir_resource')
            ->where('resource_type', $resourceType)
            ->where('supported', true)
            ->exists();
        if (! $advertised) {
            throw new IntegrationProtocolException('fhir_resource_not_supported');
        }
        if (! $this->scopeAllows($sourceId, $resourceType)) {
            throw new IntegrationProtocolException('smart_scope_not_authorized_for_resource');
        }
        if ((string) $profile->profile_status !== 'enabled') {
            throw new IntegrationProtocolException('fhir_resource_profile_not_enabled');
        }

        return $profile;
    }

    /** @return list<string> */
    public function pollableResourceTypes(int $sourceId): array
    {
        return DB::table('integration.fhir_resource_profiles as profile')
            ->join('integration.source_capabilities as capability', function ($join): void {
                $join->on('capability.source_id', '=', 'profile.source_id')
                    ->on('capability.resource_type', '=', 'profile.resource_type')
                    ->where('capability.capability_type', 'fhir_resource')
                    ->where('capability.supported', true);
            })
            ->where('profile.source_id', $sourceId)
            ->where('profile.profile_status', 'enabled')
            ->where('profile.poll_enabled', true)
            ->orderBy('profile.resource_type')
            ->pluck('profile.resource_type')
            ->map(fn (mixed $value): string => (string) $value)
            ->all();
    }

    private function resourceType(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^[A-Z][A-Za-z]{1,79}$/', $value) !== 1) {
            throw new InvalidArgumentException('fhir_resource_type_invalid');
        }

        return $value;
    }

    private function reasonCode(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-z][a-z0-9_]{0,79}$/', $value) === 1
            ? $value
            : 'fhir_resource_profile_changed';
    }

    private function correlation(?string $value): ?string
    {
        return $value !== null && Str::isUuid($value) ? $value : null;
    }

    private function changeReason(string $value): string
    {
        $value = trim($value);
        if (mb_strlen($value) < 10 || mb_strlen($value) > 500) {
            throw new InvalidArgumentException('fhir_resource_profile_change_reason_invalid');
        }

        return $value;
    }

    private function optionalString(mixed $value, int $maxLength): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException('fhir_resource_profile_value_too_long');
        }

        return $value;
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('fhir_resource_profile_limit_invalid');
        }
        $value = (int) $value;
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('fhir_resource_profile_limit_invalid');
        }

        return $value;
    }

    private function different(mixed $left, mixed $right): bool
    {
        if (is_bool($right)) {
            return (bool) $left !== $right;
        }
        if (is_int($right)) {
            return (int) $left !== $right;
        }

        return $left !== $right;
    }

    private function assertProfileMutable(int $sourceId): void
    {
        $source = DB::table('integration.sources')->where('source_id', $sourceId)->firstOrFail();
        if ((string) $source->lifecycle_state !== 'retired'
            && (string) $source->environment !== 'production'
            && ! (bool) $source->phi_allowed) {
            return;
        }

        $this->configuration->assertChildConfigurationMutable($sourceId, 'FHIR resource profile');
    }

    private function scopeAllows(int $sourceId, string $resourceType): bool
    {
        $payload = DB::table('integration.smart_backend_credentials')
            ->where('source_id', $sourceId)
            ->orderBy('smart_backend_credential_id')
            ->value('scope_payload');
        $scopes = is_string($payload) ? json_decode($payload, true) : $payload;
        if (! is_array($scopes)) {
            return false;
        }

        foreach ($scopes as $scope) {
            if (! is_string($scope)
                || preg_match('/^system\/(\*|[A-Z][A-Za-z]{1,79})\.(read|[cruds]+)$/', $scope, $matches) !== 1
                || ! in_array($matches[1], ['*', $resourceType], true)) {
                continue;
            }
            if ($matches[2] === 'read' || str_contains($matches[2], 'r')) {
                return true;
            }
        }

        return false;
    }
}
