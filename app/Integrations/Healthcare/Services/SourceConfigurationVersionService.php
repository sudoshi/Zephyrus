<?php

namespace App\Integrations\Healthcare\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SourceConfigurationVersionService
{
    /** @var list<string> */
    private const CONFIGURATION_COLUMNS = [
        'organization_id',
        'facility_id',
        'tenant_key',
        'facility_key',
        'source_name',
        'vendor',
        'system_class',
        'environment',
        'base_url',
        'interface_type',
        'fhir_version',
        'us_core_version',
        'smart_supported',
        'bulk_supported',
        'subscriptions_supported',
        'contract_status',
        'baa_status',
        'phi_allowed',
        'metadata',
    ];

    /** @var list<string> */
    private const MUTABLE_INPUTS = [
        'source_name',
        'vendor',
        'system_class',
        'environment',
        'base_url',
        'interface_type',
        'fhir_version',
        'us_core_version',
        'smart_supported',
        'bulk_supported',
        'subscriptions_supported',
        'contract_status',
        'baa_status',
        'phi_allowed',
    ];

    /**
     * Capture a source's current projection as its initial immutable version.
     * Existing versioned sources are returned without creating a duplicate.
     */
    public function initialize(
        int $sourceId,
        ?int $actorUserId,
        string $reason,
        string $correlationId,
        string $kind = 'initial',
    ): object {
        return DB::transaction(function () use ($sourceId, $actorUserId, $reason, $correlationId, $kind): object {
            $source = $this->source($sourceId, lock: true);
            if ($source->current_configuration_version_id !== null) {
                return $this->version($sourceId, (int) $source->current_configuration_version_id);
            }

            $version = $this->insertVersion(
                $source,
                $this->configurationFromSource($source),
                $actorUserId,
                $reason,
                $correlationId,
                $kind,
            );
            $this->project($sourceId, $version);

            return $version;
        });
    }

    /**
     * Create and immediately apply a new version. Intended for sources which
     * have not crossed the governed approval boundary.
     *
     * @param  array<string, mixed>  $updates
     */
    public function reviseAndApply(
        int $sourceId,
        array $updates,
        int $expectedVersionId,
        ?int $actorUserId,
        string $reason,
        string $correlationId,
    ): object {
        return DB::transaction(function () use (
            $sourceId,
            $updates,
            $expectedVersionId,
            $actorUserId,
            $reason,
            $correlationId,
        ): object {
            $source = $this->source($sourceId, lock: true);
            $this->assertExpectedVersion($source, $expectedVersionId);
            $configuration = $this->mergeConfiguration($this->configurationFromSource($source), $updates);
            $this->assertChanged($source, $configuration);
            $version = $this->insertVersion(
                $source,
                $configuration,
                $actorUserId,
                $reason,
                $correlationId,
                'direct_update',
            );
            $this->project($sourceId, $version);

            return $version;
        });
    }

    /**
     * Create an immutable proposal without changing the effective projection.
     *
     * @param  array<string, mixed>  $updates
     */
    public function propose(
        int $sourceId,
        array $updates,
        int $expectedVersionId,
        ?int $actorUserId,
        string $reason,
        string $correlationId,
    ): array {
        return DB::transaction(function () use (
            $sourceId,
            $updates,
            $expectedVersionId,
            $actorUserId,
            $reason,
            $correlationId,
        ): array {
            $source = $this->source($sourceId, lock: true);
            $this->assertExpectedVersion($source, $expectedVersionId);
            $configuration = $this->mergeConfiguration($this->configurationFromSource($source), $updates);
            $this->assertChanged($source, $configuration);
            $version = $this->insertVersion(
                $source,
                $configuration,
                $actorUserId,
                $reason,
                $correlationId,
                'proposal',
            );

            return $this->payload($version);
        });
    }

    public function apply(int $sourceId, int $versionId): object
    {
        return DB::transaction(function () use ($sourceId, $versionId): object {
            $source = $this->source($sourceId, lock: true);
            $version = $this->version($sourceId, $versionId);
            if ((int) $source->current_configuration_version_id === $versionId) {
                throw ValidationException::withMessages([
                    'configuration_version_id' => 'This configuration version is already effective.',
                ]);
            }
            $this->project($sourceId, $version);

            return $version;
        });
    }

    /** @return list<array<string, mixed>> */
    public function history(int $sourceId): array
    {
        $this->source($sourceId);
        $versions = $this->versionQuery()->where('version.source_id', $sourceId)
            ->orderByDesc('version.version_number')->get();

        return $versions->map(fn (object $version): array => $this->payload($version))->all();
    }

    /** @return array<string, mixed> */
    public function payloadFor(int $sourceId, int $versionId): array
    {
        return $this->payload($this->version($sourceId, $versionId));
    }

    public function current(int $sourceId): object
    {
        $source = $this->source($sourceId);
        if ($source->current_configuration_version_id === null) {
            throw ValidationException::withMessages([
                'configuration_version_id' => 'The source does not have an immutable configuration version.',
            ]);
        }

        return $this->version($sourceId, (int) $source->current_configuration_version_id);
    }

    public function record(int $sourceId, int $versionId): object
    {
        return $this->version($sourceId, $versionId);
    }

    /** @return array<string, int|string> */
    public function identity(object $version): array
    {
        return [
            'configuration_version_id' => (int) $version->source_configuration_version_id,
            'configuration_version_number' => (int) $version->version_number,
            'configuration_sha256' => (string) $version->configuration_sha256,
        ];
    }

    private function source(int $sourceId, bool $lock = false): object
    {
        $query = DB::table('integration.sources')->where('source_id', $sourceId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function version(int $sourceId, int $versionId): object
    {
        return $this->versionQuery()
            ->where('version.source_id', $sourceId)
            ->where('version.source_configuration_version_id', $versionId)
            ->firstOrFail();
    }

    private function versionQuery(): Builder
    {
        return DB::table('integration.source_configuration_versions as version')
            ->join('integration.sources as source', 'source.source_id', '=', 'version.source_id')
            ->select([
                'version.*',
                'source.current_configuration_version_id',
            ]);
    }

    /** @return array<string, mixed> */
    private function configurationFromSource(object $source): array
    {
        $configuration = [];
        foreach (self::CONFIGURATION_COLUMNS as $column) {
            $value = $source->{$column};
            if ($column === 'metadata') {
                $value = $this->decodeMap($value);
            }
            if (in_array($column, ['smart_supported', 'bulk_supported', 'subscriptions_supported', 'phi_allowed'], true)) {
                $value = (bool) $value;
            }
            if (in_array($column, ['organization_id', 'facility_id'], true) && $value !== null) {
                $value = (int) $value;
            }
            $configuration[$column] = $value;
        }

        return $configuration;
    }

    /** @param array<string, mixed> $configuration @param array<string, mixed> $updates @return array<string, mixed> */
    private function mergeConfiguration(array $configuration, array $updates): array
    {
        foreach (self::MUTABLE_INPUTS as $field) {
            if (array_key_exists($field, $updates)) {
                $configuration[$field] = in_array($field, ['smart_supported', 'bulk_supported', 'subscriptions_supported', 'phi_allowed'], true)
                    ? (bool) $updates[$field]
                    : $updates[$field];
            }
        }

        $metadata = $this->decodeMap($configuration['metadata'] ?? null);
        if (array_key_exists('owner', $updates)) {
            $metadata['owner'] = $updates['owner'];
        }
        if (array_key_exists('expected_cadence_minutes', $updates)) {
            $metadata['expected_cadence_minutes'] = $updates['expected_cadence_minutes'];
        }
        $configuration['metadata'] = array_filter(
            $metadata,
            fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return $configuration;
    }

    /** @param array<string, mixed> $configuration */
    private function insertVersion(
        object $source,
        array $configuration,
        ?int $actorUserId,
        string $reason,
        string $correlationId,
        string $kind,
    ): object {
        $reason = $this->reason($reason);
        $configuration['metadata'] = (object) $this->decodeMap($configuration['metadata'] ?? null);
        $canonical = $this->canonicalize($configuration);
        $encoded = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $previousVersionId = $source->current_configuration_version_id !== null
            ? (int) $source->current_configuration_version_id
            : null;
        $versionNumber = (int) DB::table('integration.source_configuration_versions')
            ->where('source_id', $source->source_id)->max('version_number') + 1;

        $versionId = (int) DB::table('integration.source_configuration_versions')->insertGetId([
            'source_id' => $source->source_id,
            'version_number' => $versionNumber,
            'previous_version_id' => $previousVersionId,
            'configuration' => $encoded,
            'configuration_sha256' => hash('sha256', $encoded),
            'change_kind' => $kind,
            'change_reason' => $reason,
            'created_by_user_id' => $actorUserId,
            'correlation_id' => Str::isUuid($correlationId) ? $correlationId : null,
            'created_at' => now(),
        ], 'source_configuration_version_id');

        return $this->version((int) $source->source_id, $versionId);
    }

    private function project(int $sourceId, object $version): void
    {
        $configuration = $this->decodeMap($version->configuration);
        $updates = collect($configuration)->only(self::CONFIGURATION_COLUMNS)->all();
        $updates['metadata'] = json_encode((object) ($updates['metadata'] ?? []), JSON_THROW_ON_ERROR);
        $updates['current_configuration_version_id'] = $version->source_configuration_version_id;
        $updates['updated_at'] = now();
        DB::table('integration.sources')->where('source_id', $sourceId)->update($updates);
    }

    /** @param array<string, mixed> $configuration */
    private function assertChanged(object $source, array $configuration): void
    {
        $current = $this->current((int) $source->source_id);
        $currentConfiguration = $this->canonicalize($this->decodeMap($current->configuration));
        if ($currentConfiguration === $this->canonicalize($configuration)) {
            throw ValidationException::withMessages([
                'configuration' => 'The proposed source configuration is identical to the effective version.',
            ]);
        }
    }

    private function assertExpectedVersion(object $source, int $expectedVersionId): void
    {
        if ((int) $source->current_configuration_version_id !== $expectedVersionId) {
            throw ValidationException::withMessages([
                'expected_configuration_version_id' => 'The source configuration changed after this form was loaded. Refresh and review the latest version.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(object $version): array
    {
        $configuration = $this->decodeMap($version->configuration);
        $previous = $version->previous_version_id !== null
            ? DB::table('integration.source_configuration_versions')
                ->where('source_configuration_version_id', $version->previous_version_id)->first()
            : null;
        $previousConfiguration = $previous ? $this->decodeMap($previous->configuration) : [];

        return [
            'configurationVersionId' => (int) $version->source_configuration_version_id,
            'sourceId' => (int) $version->source_id,
            'versionNumber' => (int) $version->version_number,
            'previousVersionId' => $version->previous_version_id !== null ? (int) $version->previous_version_id : null,
            'configurationSha256' => (string) $version->configuration_sha256,
            'changeKind' => (string) $version->change_kind,
            'changeReason' => (string) $version->change_reason,
            'createdByUserId' => $version->created_by_user_id !== null ? (int) $version->created_by_user_id : null,
            'createdAtIso' => $version->created_at !== null ? (string) $version->created_at : null,
            'isEffective' => (int) $version->current_configuration_version_id === (int) $version->source_configuration_version_id,
            'configuration' => $this->sanitize($configuration),
            'changedFields' => $this->changedFields($previousConfiguration, $configuration),
        ];
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    private function sanitize(array $configuration): array
    {
        $metadata = $this->decodeMap($configuration['metadata'] ?? null);

        return [
            'organizationId' => isset($configuration['organization_id']) ? (int) $configuration['organization_id'] : null,
            'facilityId' => isset($configuration['facility_id']) ? (int) $configuration['facility_id'] : null,
            'sourceName' => $configuration['source_name'] ?? null,
            'vendor' => $configuration['vendor'] ?? null,
            'systemClass' => $configuration['system_class'] ?? null,
            'environment' => $configuration['environment'] ?? null,
            'baseUrlConfigured' => filled($configuration['base_url'] ?? null),
            'baseUrlOrigin' => $this->urlOrigin($configuration['base_url'] ?? null),
            'interfaceType' => $configuration['interface_type'] ?? null,
            'fhirVersion' => $configuration['fhir_version'] ?? null,
            'usCoreVersion' => $configuration['us_core_version'] ?? null,
            'smartSupported' => (bool) ($configuration['smart_supported'] ?? false),
            'bulkSupported' => (bool) ($configuration['bulk_supported'] ?? false),
            'subscriptionsSupported' => (bool) ($configuration['subscriptions_supported'] ?? false),
            'contractStatus' => $configuration['contract_status'] ?? null,
            'baaStatus' => $configuration['baa_status'] ?? null,
            'phiAllowed' => (bool) ($configuration['phi_allowed'] ?? false),
            'owner' => $metadata['owner'] ?? null,
            'expectedCadenceMinutes' => isset($metadata['expected_cadence_minutes']) ? (int) $metadata['expected_cadence_minutes'] : null,
        ];
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @return list<string> */
    private function changedFields(array $before, array $after): array
    {
        if ($before === []) {
            return array_keys($this->sanitize($after));
        }

        $fields = [];
        foreach (self::CONFIGURATION_COLUMNS as $field) {
            $beforeValue = $this->canonicalize($before[$field] ?? null);
            $afterValue = $this->canonicalize($after[$field] ?? null);
            if ($beforeValue !== $afterValue) {
                $fields[] = match ($field) {
                    'base_url' => 'baseUrl',
                    'metadata' => 'metadata',
                    default => Str::camel($field),
                };
            }
        }

        return $fields;
    }

    private function reason(string $reason): string
    {
        $reason = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $reason) ?? '');
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'change_reason' => 'A 10-500 character configuration change reason is required.',
            ]);
        }

        return $reason;
    }

    /** @return array<string, mixed> */
    private function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $nested): mixed => $this->canonicalize($nested), $value);
    }

    private function urlOrigin(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host'])
            .(isset($parts['port']) ? ':'.(int) $parts['port'] : '');
    }
}
