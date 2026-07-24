<?php

namespace App\Services\Deployment;

use RuntimeException;

/**
 * ENT-REG — normalizes an inbound enterprise registry payload into a canonical,
 * deterministic set of per-entity records, and owns the per-entity table spec
 * (target table, natural-key column, upsert conflict keys, array/json columns).
 *
 * The normalizer is intentionally strict and additive: it drops unknown keys,
 * coerces types, sorts records by natural key, and never emits a destructive
 * operation. Only the entity kinds Zephyrus recognizes are accepted; anything
 * else in the payload is ignored so the hash stays stable across payload noise.
 *
 * Plan: docs/plans/ADMIN-INTEROPERABILITY-CONTROL-PLANE-PLAN-2026-07-12.md (ENT-REG)
 */
final class EnterpriseRegistryNormalizer
{
    private const NATURAL_KEY_PATTERN = '/^[A-Za-z0-9_.:\-]{1,190}$/';

    private const EXTERNAL_NAMESPACE_PATTERN = '/^[a-z][a-z0-9_.\-]{0,39}$/';

    private const SOURCE_OF_TRUTH_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';

    /** Physical space categories accepted for imported locations (matches facility_spaces CHECK). */
    private const SPACE_CATEGORIES = [
        'campus', 'building', 'floor', 'zone', 'unit', 'room', 'bay', 'bed',
        'corridor', 'vertical_transport', 'utility', 'exterior', 'helipad',
        'procedure_room', 'imaging', 'support', 'equipment',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function entitySpecs(): array
    {
        return [
            'organizations' => [
                'table' => 'hosp_org.organizations',
                'natural_key_column' => 'organization_key',
                'conflict_keys' => ['organization_key'],
                'array_columns' => [],
                'json_columns' => ['metadata'],
                'change_history_type' => 'organization',
                'payload_keys' => ['organizations'],
                'create_requirements' => ['name'],
            ],
            'markets' => [
                'table' => 'hosp_org.markets',
                'natural_key_column' => 'market_key',
                'conflict_keys' => ['organization_id', 'market_key'],
                'array_columns' => [],
                'json_columns' => ['metadata'],
                'change_history_type' => 'market',
                'payload_keys' => ['markets'],
                'create_requirements' => ['organization_id', 'name'],
            ],
            'facilities' => [
                'table' => 'hosp_org.facilities',
                'natural_key_column' => 'facility_key',
                'conflict_keys' => ['facility_key'],
                'array_columns' => [],
                'json_columns' => ['metadata'],
                'change_history_type' => 'facility',
                'payload_keys' => ['facilities'],
                'create_requirements' => ['organization_id', 'facility_name', 'idn_role'],
            ],
            'service_lines' => [
                'table' => 'hosp_ref.service_lines',
                'natural_key_column' => 'service_line_code',
                'conflict_keys' => ['service_line_code'],
                'array_columns' => [],
                'json_columns' => ['metadata'],
                'change_history_type' => 'service_line',
                'payload_keys' => ['service_lines', 'serviceLines'],
                'create_requirements' => ['display_name', 'clinical_domain'],
            ],
            'locations' => [
                'table' => 'hosp_space.facility_spaces',
                'natural_key_column' => 'space_code',
                'conflict_keys' => ['space_code'],
                'array_columns' => [],
                'json_columns' => [],
                'change_history_type' => 'location',
                'payload_keys' => ['locations', 'spaces'],
                'create_requirements' => ['space_name', 'space_category'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function entitySpec(string $entityType): array
    {
        $specs = $this->entitySpecs();
        if (! isset($specs[$entityType])) {
            throw new RuntimeException("Unknown enterprise entity type: {$entityType}");
        }

        return $specs[$entityType];
    }

    /**
     * Normalize an inbound payload into canonical records per entity type.
     * Only entity types with at least one record are returned; each record list
     * is sorted by natural key so the plan hash is deterministic.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, list<array<string, mixed>>>
     */
    public function normalize(array $payload): array
    {
        $normalized = [];

        foreach ($this->entitySpecs() as $entityType => $spec) {
            $records = [];
            foreach ($spec['payload_keys'] as $payloadKey) {
                foreach ($this->extractList($payload, $payloadKey) as $raw) {
                    if (! is_array($raw)) {
                        continue;
                    }
                    $record = $this->normalizeRecord($entityType, $raw);
                    if ($record !== null) {
                        $records[$record['natural_key']] = $record;
                    }
                }
            }
            if ($records !== []) {
                ksort($records);
                $normalized[$entityType] = array_values($records);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function naturalKeyBinding(string $entityType, array $record): array
    {
        $spec = $this->entitySpec($entityType);

        return [$spec['natural_key_column'] => $record['natural_key']];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<mixed>
     */
    private function extractList(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function normalizeRecord(string $entityType, array $raw): ?array
    {
        $naturalKey = $this->naturalKey($entityType, $raw);
        if ($naturalKey === null) {
            return null;
        }

        $attributes = $this->attributes($entityType, $raw);
        if ($attributes === null) {
            return null;
        }

        return [
            'natural_key' => $naturalKey,
            'display_name' => $this->displayName($entityType, $raw, $naturalKey),
            'attributes' => $attributes,
            'references' => $this->references($entityType, $raw),
            'external_identifiers' => $this->externalIdentifiers($raw),
            'owner_name' => $this->boundedString($raw['owner'] ?? $raw['owner_name'] ?? null, 190),
            'steward_name' => $this->boundedString($raw['steward'] ?? $raw['steward_name'] ?? null, 190),
            'source_of_truth' => $this->sourceOfTruth($raw),
            'valid_from' => $this->timestamp($raw['valid_from'] ?? null),
            'valid_until' => $this->timestamp($raw['valid_until'] ?? null),
        ];
    }

    /**
     * Natural-key references the service resolves to FK ids at plan/apply time
     * (an organization may be created within the same import). Never a raw id.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    private function references(string $entityType, array $raw): array
    {
        $references = [];
        if (in_array($entityType, ['markets', 'facilities'], true)) {
            $orgKey = $this->boundedString($raw['organization_key'] ?? $raw['organization'] ?? null, 190);
            if ($orgKey !== null) {
                $references['organization_key'] = $orgKey;
            }
        }
        if ($entityType === 'facilities') {
            $idnRole = $this->boundedString($raw['idn_role'] ?? null, 80);
            if ($idnRole !== null) {
                $references['idn_role'] = $idnRole;
            }
        }

        return $references;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function naturalKey(string $entityType, array $raw): ?string
    {
        $candidates = match ($entityType) {
            'organizations' => [$raw['key'] ?? null, $raw['organization_key'] ?? null],
            'markets' => [$raw['key'] ?? null, $raw['market_key'] ?? null],
            'facilities' => [$raw['facility_key'] ?? null, $raw['key'] ?? null],
            'service_lines' => [$raw['service_line_code'] ?? null, $raw['code'] ?? null],
            'locations' => [$raw['space_code'] ?? null, $raw['code'] ?? null],
            default => [null],
        };

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match(self::NATURAL_KEY_PATTERN, $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Coerce the recognized, non-destructive attribute set per entity. Absent
     * keys are simply not upserted (the upsert only writes the columns present).
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function attributes(string $entityType, array $raw): ?array
    {
        return match ($entityType) {
            'organizations' => $this->pruneNull([
                'name' => $this->boundedString($raw['name'] ?? null, 190),
                'short_name' => $this->boundedString($raw['short_name'] ?? null, 190),
                'kind' => $this->boundedString($raw['kind'] ?? null, 40),
                'headquarters_state' => $this->boundedString($raw['headquarters_state'] ?? null, 40),
            ]),
            'markets' => $this->pruneNull([
                'organization_id' => $this->foreignId($raw['organization_id'] ?? null),
                'name' => $this->boundedString($raw['name'] ?? null, 190),
                'region' => $this->boundedString($raw['region'] ?? null, 80),
                'state' => $this->boundedString($raw['state'] ?? null, 40),
            ]),
            'facilities' => $this->pruneNull([
                'facility_name' => $this->boundedString($raw['facility_name'] ?? $raw['name'] ?? null, 190),
                'short_name' => $this->boundedString($raw['short_name'] ?? null, 190),
                'region' => $this->boundedString($raw['region'] ?? null, 80),
                'state' => $this->boundedString($raw['state'] ?? null, 40),
                'county' => $this->boundedString($raw['county'] ?? null, 80),
                'licensed_beds' => $this->intOrNull($raw['licensed_beds'] ?? null),
                'cad_facility_code' => $this->boundedString($raw['cad_facility_code'] ?? null, 80),
            ]),
            'service_lines' => $this->pruneNull([
                'display_name' => $this->boundedString($raw['display_name'] ?? $raw['name'] ?? null, 190),
                'clinical_domain' => $this->boundedString($raw['clinical_domain'] ?? $raw['domain'] ?? null, 80),
            ]),
            // facility_spaces.space_name and space_category are NOT NULL (category is
            // CHECK-constrained). A location record without a valid category is dropped
            // rather than risk a failed insert; space_name falls back to the code so a
            // create always satisfies the NOT NULL constraint.
            'locations' => $this->locationAttributes($raw),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function locationAttributes(array $raw): ?array
    {
        $category = $this->boundedString($raw['space_category'] ?? $raw['category'] ?? null, 40);
        if ($category === null || ! in_array($category, self::SPACE_CATEGORIES, true)) {
            return null;
        }
        $name = $this->boundedString($raw['space_name'] ?? $raw['name'] ?? null, 190)
            ?? $this->boundedString($raw['space_code'] ?? $raw['code'] ?? null, 190);

        return $this->pruneNull([
            'space_name' => $name,
            'space_category' => $category,
            'service_line_code' => $this->boundedString($raw['service_line_code'] ?? null, 80),
            'acuity_level' => $this->boundedString($raw['acuity_level'] ?? null, 40),
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function displayName(string $entityType, array $raw, string $naturalKey): string
    {
        $candidates = [
            $raw['name'] ?? null,
            $raw['facility_name'] ?? null,
            $raw['display_name'] ?? null,
            $raw['space_name'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $bounded = $this->boundedString($candidate, 190);
            if ($bounded !== null) {
                return $bounded;
            }
        }

        return $naturalKey;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string> namespace => value, sorted
     */
    private function externalIdentifiers(array $raw): array
    {
        $source = $raw['external_identifiers'] ?? $raw['external_ids'] ?? [];
        if (! is_array($source) || array_is_list($source)) {
            return [];
        }

        $result = [];
        foreach ($source as $namespace => $value) {
            if (! is_string($namespace) || preg_match(self::EXTERNAL_NAMESPACE_PATTERN, $namespace) !== 1) {
                continue;
            }
            $bounded = $this->boundedString($value, 190);
            if ($bounded !== null) {
                $result[$namespace] = $bounded;
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function sourceOfTruth(array $raw): string
    {
        $value = $raw['source_of_truth'] ?? null;
        if (is_string($value) && preg_match(self::SOURCE_OF_TRUTH_PATTERN, strtolower($value)) === 1) {
            return strtolower($value);
        }

        return 'import';
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function pruneNull(array $attributes): array
    {
        return array_filter($attributes, static fn (mixed $value): bool => $value !== null);
    }

    private function boundedString(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $max);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function foreignId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $ts = strtotime($value);

        return $ts === false ? null : date('Y-m-d H:i:sP', $ts);
    }
}
