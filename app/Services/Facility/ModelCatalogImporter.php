<?php

namespace App\Services\Facility;

use App\Casts\PgTextArray;
use App\Models\Bed;
use App\Models\Facility\BlueprintImport;
use App\Models\Facility\BlueprintObject;
use App\Models\Facility\FacilitySpace;
use App\Models\Unit;
use App\Services\Deployment\CapabilityTagBackfiller;
use App\Services\Deployment\ServiceLineNormalizer;
use App\Services\Deployment\ServiceLineRegistrar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class ModelCatalogImporter
{
    public function __construct(
        private readonly ServiceLineNormalizer $normalizer,
        private readonly ServiceLineRegistrar $registrar,
        private readonly CapabilityTagBackfiller $tagBackfiller,
    ) {}

    /**
     * @return array{import_id:int, checksum:string, objects:int, spaces:int, service_lines:int, units:int, beds:int, maps:int, conflicts:int}
     *
     * @throws JsonException
     */
    public function import(string $path, array $options = []): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Catalog file is not readable: {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read catalog file: {$path}");
        }

        $catalog = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $objects = $catalog['objects'] ?? null;
        if (! is_array($objects)) {
            throw new RuntimeException('Catalog JSON must include an objects array.');
        }

        $facilityCode = (string) ($options['facility_code'] ?? 'ZEPHYRUS-500');
        $facilityName = (string) ($options['facility_name'] ?? ($catalog['model_name'] ?? 'Summit Regional Medical Center'));
        $sourceName = (string) ($options['source_name'] ?? basename($path));
        $mapOperational = (bool) ($options['map_operational'] ?? false);
        $checksum = hash('sha256', $contents);

        return DB::transaction(function () use ($catalog, $objects, $path, $facilityCode, $facilityName, $sourceName, $mapOperational, $checksum): array {
            // The service_line_code / location_role FKs (2026_07_04_000160) require the
            // registry to exist, so make a catalog import self-sufficient. Idempotent.
            $this->ensureRegistry();
            $knownLocationRoles = $this->lookupCodes('hosp_ref.location_roles', 'code');
            $knownPrograms = $this->lookupCodes('hosp_ref.programs', 'program_code');

            $import = $this->upsertImport($catalog, $path, $facilityCode, $facilityName, $sourceName, $checksum);
            $categories = $this->ensureCategories($objects);
            $floorLabels = $this->floorLabels($objects);
            $bedToRoom = $this->bedToRoomMap($objects);

            $objectModels = $this->upsertObjects($import, $objects, $categories, $floorLabels);
            $this->assignObjectParents($objectModels, $objects, $floorLabels, $bedToRoom);

            $spaces = $this->upsertSpaces($facilityCode, $objectModels, $objects, $categories, $floorLabels, $knownLocationRoles, $knownPrograms);
            $this->assignSpaceParents($spaces, $objects);
            $bridgeCount = $this->syncSpaceServiceLines($spaces, $objects, $knownLocationRoles, $knownPrograms);

            $operational = $mapOperational
                ? $this->mapOperationalUnitsAndBeds($facilityCode, $objects, $spaces)
                : ['units' => 0, 'beds' => 0, 'maps' => 0, 'conflicts' => 0];

            $import->update([
                'status' => 'published',
                'completed_at' => now(),
            ]);

            return [
                'import_id' => (int) $import->blueprint_import_id,
                'checksum' => $checksum,
                'objects' => count($objectModels),
                'spaces' => count($spaces),
                'service_lines' => $bridgeCount,
                'units' => $operational['units'],
                'beds' => $operational['beds'],
                'maps' => $operational['maps'],
                'conflicts' => $operational['conflicts'],
            ];
        });
    }

    private function upsertImport(array $catalog, string $path, string $facilityCode, string $facilityName, string $sourceName, string $checksum): BlueprintImport
    {
        $import = BlueprintImport::query()
            ->where('source_checksum', $checksum)
            ->where('source_name', $sourceName)
            ->where('facility_code', $facilityCode)
            ->first();

        $payload = [
            'source_name' => $sourceName,
            'source_type' => 'catalog_json',
            'source_uri' => realpath($path) ?: $path,
            'source_checksum' => $checksum,
            'facility_code' => $facilityCode,
            'facility_name' => $facilityName,
            'coordinate_units' => 'ft',
            'coordinate_system' => 'local_facility_ft',
            'floor_height_ft' => 15.00,
            'status' => 'parsed',
            'metadata' => [
                'model_name' => $catalog['model_name'] ?? null,
                'generated_at' => $catalog['generated_at'] ?? null,
                'summary' => $catalog['summary'] ?? [],
                'categories' => $catalog['categories'] ?? [],
                'floors' => $catalog['floors'] ?? [],
                'standard_strategy' => $catalog['standard_strategy'] ?? null,
            ],
            'started_at' => now(),
            'completed_at' => null,
        ];

        if ($import) {
            $import->update($payload);

            return $import;
        }

        return BlueprintImport::create($payload);
    }

    /**
     * @return array<string, array{canonical:string,target_schema:string,target_table:string,prod_table:?string}>
     */
    private function ensureCategories(array $objects): array
    {
        $existing = DB::table('hosp_ref.facility_object_categories')
            ->get()
            ->keyBy('category_code');

        foreach ($objects as $object) {
            $category = (string) ($object['category'] ?? 'support_infrastructure');
            if ($existing->has($category)) {
                continue;
            }

            DB::table('hosp_ref.facility_object_categories')->insert([
                'category_code' => $category,
                'category_name' => Str::headline(str_replace('_', ' ', $category)),
                'source_category' => $category,
                'canonical_space_category' => 'support',
                'target_hosp_schema' => 'hosp_space',
                'target_hosp_table' => 'facility_spaces',
                'prod_table' => null,
                'import_notes' => 'Auto-created by model catalog importer for an unknown source category.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $existing = DB::table('hosp_ref.facility_object_categories')
                ->get()
                ->keyBy('category_code');
        }

        return $existing
            ->map(fn ($row): array => [
                'canonical' => (string) $row->canonical_space_category,
                'target_schema' => (string) $row->target_hosp_schema,
                'target_table' => (string) $row->target_hosp_table,
                'prod_table' => $row->prod_table ? (string) $row->prod_table : null,
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function floorLabels(array $objects): array
    {
        $labels = [];
        foreach ($objects as $object) {
            if (($object['category'] ?? null) !== 'floor') {
                continue;
            }

            $floor = (int) ($object['floor'] ?? 0);
            $label = $object['metadata']['floor_code'] ?? null;
            if ($label) {
                $labels[$floor] = (string) $label;
            }
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    private function bedToRoomMap(array $objects): array
    {
        $map = [];
        foreach ($objects as $object) {
            if (($object['category'] ?? null) !== 'patient_room') {
                continue;
            }

            $bedCode = $object['metadata']['bed_code'] ?? null;
            $roomCode = $object['code'] ?? null;
            if ($bedCode && $roomCode) {
                $map[(string) $bedCode] = (string) $roomCode;
            }
        }

        return $map;
    }

    /**
     * @return array<string, BlueprintObject>
     */
    private function upsertObjects(BlueprintImport $import, array $objects, array $categories, array $floorLabels): array
    {
        $models = [];

        foreach ($objects as $object) {
            $code = (string) ($object['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $category = (string) ($object['category'] ?? 'support_infrastructure');
            $position = $object['position_ft'] ?? [];
            $size = $object['size_ft'] ?? [];
            $bounds = $this->bounds($position, $size);
            $metadata = $object['metadata'] ?? [];
            $floorNumber = (int) ($object['floor'] ?? 0);

            $model = BlueprintObject::updateOrCreate(
                [
                    'blueprint_import_id' => $import->blueprint_import_id,
                    'object_code' => $code,
                ],
                [
                    'source_object_id' => $code,
                    'object_name' => (string) ($object['name'] ?? $code),
                    'object_category' => $category,
                    'source_material' => $object['material'] ?? null,
                    'floor_label' => $floorLabels[$floorNumber] ?? null,
                    'floor_number' => $floorNumber,
                    'geometry_kind' => $this->geometryKind($category),
                    'position_ft' => $position,
                    'size_ft' => $size,
                    'bounds_ft' => $bounds,
                    'centroid_x_ft' => $position['x'] ?? null,
                    'centroid_y_ft' => $position['level'] ?? null,
                    'centroid_z_ft' => $position['z'] ?? null,
                    'gross_area_sqft' => $this->area($size),
                    'net_area_sqft' => $this->netArea($category, $size),
                    'metadata' => $metadata,
                    'classification' => [
                        'source_category' => $category,
                        'canonical_space_category' => $categories[$category]['canonical'] ?? 'support',
                        'target_schema' => $categories[$category]['target_schema'] ?? 'hosp_space',
                        'target_table' => $categories[$category]['target_table'] ?? 'facility_spaces',
                        'source' => 'model_catalog_json',
                    ],
                    'extraction_confidence' => 0.98,
                    'review_status' => 'auto_accepted',
                    'canonical_schema' => 'hosp_space',
                    'canonical_table' => 'facility_spaces',
                ],
            );

            $models[$code] = $model;
        }

        return $models;
    }

    private function assignObjectParents(array $models, array $objects, array $floorLabels, array $bedToRoom): void
    {
        foreach ($objects as $object) {
            $code = (string) ($object['code'] ?? '');
            if (! isset($models[$code])) {
                continue;
            }

            $parentCode = $this->parentObjectCode($object, $floorLabels, $bedToRoom);
            $parent = $parentCode && isset($models[$parentCode]) ? $models[$parentCode] : null;

            $models[$code]->update([
                'parent_blueprint_object_id' => $parent?->blueprint_object_id,
            ]);
        }
    }

    /**
     * @param  array<string, bool>  $knownLocationRoles
     * @param  array<string, bool>  $knownPrograms
     * @return array<string, FacilitySpace>
     */
    private function upsertSpaces(string $facilityCode, array $models, array $objects, array $categories, array $floorLabels, array $knownLocationRoles, array $knownPrograms): array
    {
        $spaces = [];

        foreach ($objects as $object) {
            $code = (string) ($object['code'] ?? '');
            if (! isset($models[$code])) {
                continue;
            }

            $category = (string) ($object['category'] ?? 'support_infrastructure');
            $canonicalCategory = $categories[$category]['canonical'] ?? 'support';
            $floorNumber = (int) ($object['floor'] ?? 0);
            $metadata = $object['metadata'] ?? [];

            // Fold legacy codes to canonical; keep the raw value in attributes if it is
            // not (yet) in the registry so an active FK never rejects a catalog import.
            [$serviceLine, $unmappedServiceLine] = $this->resolveServiceLine($metadata['service_line'] ?? null);
            $locationRole = $this->validCodeOrNull($metadata['location_role'] ?? null, $knownLocationRoles);
            $programCode = $this->validCodeOrNull($metadata['program_code'] ?? $metadata['program'] ?? null, $knownPrograms);

            $attributes = $metadata + [
                'source_category' => $category,
                'source_material' => $object['material'] ?? null,
            ];
            if ($unmappedServiceLine !== null) {
                $attributes['unmapped_service_line'] = $unmappedServiceLine;
            }

            $space = FacilitySpace::updateOrCreate(
                ['space_code' => $this->spaceCode($facilityCode, $code)],
                [
                    'blueprint_object_id' => $models[$code]->blueprint_object_id,
                    'space_name' => (string) ($object['name'] ?? $code),
                    'space_category' => $this->facilitySpaceCategory($category, $canonicalCategory),
                    'floor_label' => $floorLabels[$floorNumber] ?? null,
                    'floor_number' => $floorNumber,
                    'service_line_code' => $serviceLine,
                    'location_role' => $locationRole,
                    'program_code' => $programCode,
                    'acuity_level' => $metadata['acuity'] ?? null,
                    'status' => 'planned',
                    'geometry' => [
                        'source_object_code' => $code,
                        'position_ft' => $object['position_ft'] ?? [],
                        'size_ft' => $object['size_ft'] ?? [],
                        'bounds_ft' => $models[$code]->bounds_ft,
                        'material' => $object['material'] ?? null,
                    ],
                    'attributes' => $attributes,
                    'source_system' => 'model_catalog_json',
                    'source_confidence' => 0.98,
                ],
            );

            // capability_tags is a Postgres text[] — write it with an explicit ?::text[]
            // cast (Eloquent binds arrays as text, which the driver won't coerce).
            $tags = $metadata['capability_tags'] ?? null;
            if (is_array($tags) && $tags !== []) {
                DB::update(
                    'UPDATE hosp_space.facility_spaces SET capability_tags = ?::text[], updated_at = now() WHERE facility_space_id = ?',
                    [PgTextArray::literal(array_values(array_map('strval', $tags))), $space->facility_space_id]
                );
            }

            $spaces[$code] = $space;
        }

        return $spaces;
    }

    /**
     * Seed the service-line registry so facility_spaces FKs onto hosp_ref have valid
     * targets. Idempotent; skipped if the registry table has not been migrated yet.
     */
    private function ensureRegistry(): void
    {
        if (! Schema::hasTable('hosp_ref.service_lines')) {
            return;
        }

        $this->registrar->seed();
        ServiceLineNormalizer::flush();
    }

    /**
     * @return array<string, bool> code => true, for O(1) membership tests
     */
    private function lookupCodes(string $table, string $column): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->pluck($column)
            ->mapWithKeys(fn ($code): array => [(string) $code => true])
            ->all();
    }

    /**
     * Fold a raw service-line code to canonical.
     *
     * @return array{0: ?string, 1: ?string} [canonical-or-null, unmapped-raw-or-null]
     */
    private function resolveServiceLine(mixed $raw): array
    {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return [null, null];
        }

        $canonical = $this->normalizer->canonical($raw);

        return $this->normalizer->isKnown($canonical) ? [$canonical, null] : [null, $raw];
    }

    /**
     * @param  array<string, bool>  $known
     */
    private function validCodeOrNull(mixed $code, array $known): ?string
    {
        if (! is_string($code) || $code === '') {
            return null;
        }

        return isset($known[$code]) ? $code : null;
    }

    /**
     * Write Layer 4 bridge rows: one primary per space, plus any shared service lines
     * the catalog declares in metadata.service_lines. Idempotent.
     *
     * @param  array<string, FacilitySpace>  $spaces
     * @param  array<string, bool>  $knownLocationRoles
     * @param  array<string, bool>  $knownPrograms
     *
     * @throws JsonException
     */
    private function syncSpaceServiceLines(array $spaces, array $objects, array $knownLocationRoles, array $knownPrograms): int
    {
        if (! Schema::hasTable('hosp_space.facility_space_service_lines')) {
            return 0;
        }

        $written = 0;

        foreach ($objects as $object) {
            $code = (string) ($object['code'] ?? '');
            $space = $spaces[$code] ?? null;
            if (! $space) {
                continue;
            }

            $metadata = $object['metadata'] ?? [];
            [$primary] = $this->resolveServiceLine($metadata['service_line'] ?? null);
            if ($primary === null) {
                continue;
            }

            $locationRole = $this->validCodeOrNull($metadata['location_role'] ?? null, $knownLocationRoles);

            $written += $this->upsertBridgeRow((int) $space->facility_space_id, $primary, null, $locationRole, true);

            foreach ($this->sharedServiceLines($metadata, $primary) as $shared) {
                $program = $this->validCodeOrNull($shared['program_code'] ?? null, $knownPrograms);
                $written += $this->upsertBridgeRow((int) $space->facility_space_id, $shared['service_line_code'], $program, $locationRole, false);
            }
        }

        return $written;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<array{service_line_code:string, program_code:?string}>
     */
    private function sharedServiceLines(array $metadata, string $primary): array
    {
        $out = [];

        foreach (($metadata['service_lines'] ?? []) as $entry) {
            if (is_string($entry)) {
                $canonical = $this->normalizer->canonical($entry);
                $programCode = null;
            } elseif (is_array($entry)) {
                $canonical = $this->normalizer->canonical((string) ($entry['service_line'] ?? $entry['service_line_code'] ?? ''));
                $programCode = $entry['program_code'] ?? null;
            } else {
                continue;
            }

            if ($canonical === '' || $canonical === $primary || ! $this->normalizer->isKnown($canonical)) {
                continue;
            }

            $out[] = ['service_line_code' => $canonical, 'program_code' => $programCode ? (string) $programCode : null];
        }

        return $out;
    }

    /**
     * Insert one bridge row if it does not already exist. Claims primary only when the
     * space has no primary yet (respects uq_fssl_one_primary).
     *
     * @throws JsonException
     */
    private function upsertBridgeRow(int $facilitySpaceId, string $serviceLineCode, ?string $programCode, ?string $locationRole, bool $primary): int
    {
        $existing = DB::table('hosp_space.facility_space_service_lines')
            ->where('facility_space_id', $facilitySpaceId)
            ->where('service_line_code', $serviceLineCode)
            ->when($programCode === null, fn ($q) => $q->whereNull('program_code'))
            ->when($programCode !== null, fn ($q) => $q->where('program_code', $programCode))
            ->exists();

        if ($existing) {
            return 0;
        }

        $primaryFlag = $primary && ! DB::table('hosp_space.facility_space_service_lines')
            ->where('facility_space_id', $facilitySpaceId)
            ->where('primary_flag', true)
            ->exists();

        DB::insert(
            'INSERT INTO hosp_space.facility_space_service_lines
                (facility_space_id, service_line_code, program_code, location_role, primary_flag, capability_tags, evidence, updated_at)
             VALUES (?, ?, ?, ?, ?, ?::text[], ?::jsonb, now())',
            [
                $facilitySpaceId,
                $serviceLineCode,
                $programCode,
                $locationRole,
                $primaryFlag,
                '{}',
                json_encode(['source' => 'model_catalog_json'], JSON_THROW_ON_ERROR),
            ]
        );

        return 1;
    }

    private function assignSpaceParents(array $spaces, array $objects): void
    {
        foreach ($spaces as $code => $space) {
            $parentObjectId = $space->blueprintObject?->parent_blueprint_object_id;
            if (! $parentObjectId) {
                continue;
            }

            $parentCode = null;
            foreach ($objects as $object) {
                if (($spaces[(string) ($object['code'] ?? '')]->blueprint_object_id ?? null) === $parentObjectId) {
                    $parentCode = (string) $object['code'];
                    break;
                }
            }

            if ($parentCode && isset($spaces[$parentCode])) {
                $space->update(['parent_space_id' => $spaces[$parentCode]->facility_space_id]);
            }
        }
    }

    /**
     * @return array{units:int,beds:int,maps:int,conflicts:int}
     */
    private function mapOperationalUnitsAndBeds(string $facilityCode, array $objects, array $spaces): array
    {
        $units = [];
        $unitCount = 0;
        $bedCount = 0;
        $mapCount = 0;
        $conflicts = 0;

        foreach ($objects as $object) {
            if (($object['category'] ?? null) !== 'care_unit') {
                continue;
            }

            $code = (string) ($object['code'] ?? '');
            $space = $spaces[$code] ?? null;
            if (! $space) {
                continue;
            }

            $metadata = $object['metadata'] ?? [];
            $unitCode = (string) ($metadata['unit_code'] ?? Str::after($code, 'UNIT-'));
            $unit = Unit::query()->where('abbreviation', $unitCode)->first();

            if (! $unit) {
                $unit = Unit::create([
                    'name' => (string) ($object['name'] ?? $unitCode),
                    'abbreviation' => $unitCode,
                    'type' => $this->unitType((string) ($metadata['acuity'] ?? ''), (string) ($metadata['service_line'] ?? '')),
                    'staffed_bed_count' => (int) ($metadata['planned_beds'] ?? 0),
                    'ratio_floor' => $this->ratioFloor((string) ($metadata['acuity'] ?? '')),
                    'access_standard_minutes' => $this->accessStandardMinutes((string) ($metadata['acuity'] ?? '')),
                    'facility_space_id' => $space->facility_space_id,
                    'is_deleted' => false,
                ]);
                $unitCount++;
            } elseif (! $unit->facility_space_id || (int) $unit->facility_space_id === (int) $space->facility_space_id) {
                $unit->facility_space_id = $space->facility_space_id;
                $unit->save();
            } else {
                $conflicts++;

                continue;
            }

            $units[$unitCode] = $unit;
            $mapCount += $this->upsertOperationalMap($space, 'unit_id', $unit->unit_id, [
                'facility_code' => $facilityCode,
                'object_code' => $code,
                'mapping_basis' => 'care_unit.unit_code',
            ]);
        }

        foreach ($objects as $object) {
            if (($object['category'] ?? null) !== 'bed') {
                continue;
            }

            $code = (string) ($object['code'] ?? '');
            $space = $spaces[$code] ?? null;
            if (! $space) {
                continue;
            }

            $metadata = $object['metadata'] ?? [];
            $unitCode = (string) ($metadata['unit_code'] ?? '');
            $unit = $units[$unitCode] ?? Unit::query()->where('abbreviation', $unitCode)->first();
            if (! $unit) {
                $conflicts++;

                continue;
            }

            $bed = Bed::query()
                ->where('unit_id', $unit->unit_id)
                ->where('label', $code)
                ->first();

            if (! $bed) {
                $bed = Bed::create([
                    'unit_id' => $unit->unit_id,
                    'label' => $code,
                    'status' => 'available',
                    'bed_type' => $this->bedType((string) ($metadata['acuity'] ?? '')),
                    'isolation_capable' => (bool) ($metadata['negative_pressure_capable'] ?? $metadata['protective_environment_capable'] ?? false),
                    'facility_space_id' => $space->facility_space_id,
                    'is_deleted' => false,
                ]);
                $bedCount++;
            } elseif (! $bed->facility_space_id || (int) $bed->facility_space_id === (int) $space->facility_space_id) {
                $bed->facility_space_id = $space->facility_space_id;
                $bed->save();
            } else {
                $conflicts++;

                continue;
            }

            $mapCount += $this->upsertOperationalMap($space, 'bed_id', $bed->bed_id, [
                'facility_code' => $facilityCode,
                'object_code' => $code,
                'unit_code' => $unitCode,
                'mapping_basis' => 'bed.unit_code_and_code',
            ]);

            // Seed default capability tags for the bed (non-destructive; client roster overrides).
            $this->tagBackfiller->applyToBed(
                (int) $bed->bed_id,
                $this->tagBackfiller->defaultBedTags(
                    $metadata['acuity'] ?? null,
                    $metadata['service_line'] ?? null,
                    $this->bedType((string) ($metadata['acuity'] ?? ''))
                )
            );
        }

        return [
            'units' => $unitCount,
            'beds' => $bedCount,
            'maps' => $mapCount,
            'conflicts' => $conflicts,
        ];
    }

    private function upsertOperationalMap(FacilitySpace $space, string $targetColumn, int $targetId, array $evidence): int
    {
        $existing = DB::table('hosp_space.operational_space_maps')
            ->where('facility_space_id', $space->facility_space_id)
            ->where($targetColumn, $targetId)
            ->first();

        $payload = [
            'facility_space_id' => $space->facility_space_id,
            'mapping_type' => 'canonical',
            'mapping_confidence' => 0.98,
            'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR),
            'active' => true,
            'updated_at' => now(),
        ];

        foreach (['location_id', 'room_id', 'unit_id', 'bed_id'] as $column) {
            $payload[$column] = $column === $targetColumn ? $targetId : null;
        }

        if ($existing) {
            DB::table('hosp_space.operational_space_maps')
                ->where('operational_space_map_id', $existing->operational_space_map_id)
                ->update($payload);

            return 0;
        }

        $payload['created_at'] = now();
        DB::table('hosp_space.operational_space_maps')->insert($payload);

        return 1;
    }

    private function parentObjectCode(array $object, array $floorLabels, array $bedToRoom): ?string
    {
        $category = $object['category'] ?? null;
        $metadata = $object['metadata'] ?? [];
        $floorNumber = (int) ($object['floor'] ?? 0);
        $floorCode = isset($floorLabels[$floorNumber]) ? 'FLOOR-'.$floorLabels[$floorNumber] : null;

        if ($category === 'care_unit') {
            return $floorCode;
        }

        if ($category === 'patient_room' && ! empty($metadata['unit_code'])) {
            return 'UNIT-'.$metadata['unit_code'];
        }

        if ($category === 'bed') {
            $code = (string) ($object['code'] ?? '');
            if (isset($bedToRoom[$code])) {
                return $bedToRoom[$code];
            }

            return ! empty($metadata['unit_code']) ? 'UNIT-'.$metadata['unit_code'] : $floorCode;
        }

        if (in_array($category, ['corridor', 'emergency_department', 'procedure_room', 'procedure_support', 'imaging', 'helipad', 'support_infrastructure'], true)) {
            return $floorCode;
        }

        return null;
    }

    private function facilitySpaceCategory(string $sourceCategory, string $canonicalCategory): string
    {
        return match ($sourceCategory) {
            'procedure_room' => 'procedure_room',
            'imaging' => 'imaging',
            'helipad' => 'helipad',
            'support_infrastructure', 'procedure_support' => 'support',
            default => $canonicalCategory,
        };
    }

    private function geometryKind(string $category): string
    {
        return match ($category) {
            'corridor' => 'polyline',
            'helipad' => 'polygon',
            default => 'box',
        };
    }

    /**
     * @return array<string, float|null>
     */
    private function bounds(array $position, array $size): array
    {
        $x = isset($position['x']) ? (float) $position['x'] : null;
        $y = isset($position['level']) ? (float) $position['level'] : null;
        $z = isset($position['z']) ? (float) $position['z'] : null;
        $sx = isset($size['x']) ? (float) $size['x'] : null;
        $sy = isset($size['y']) ? (float) $size['y'] : null;
        $sz = isset($size['z']) ? (float) $size['z'] : null;

        return [
            'min_x' => $x !== null && $sx !== null ? $x - $sx / 2 : null,
            'min_y' => $y !== null && $sy !== null ? $y - $sy / 2 : null,
            'min_z' => $z !== null && $sz !== null ? $z - $sz / 2 : null,
            'max_x' => $x !== null && $sx !== null ? $x + $sx / 2 : null,
            'max_y' => $y !== null && $sy !== null ? $y + $sy / 2 : null,
            'max_z' => $z !== null && $sz !== null ? $z + $sz / 2 : null,
        ];
    }

    private function area(array $size): ?float
    {
        if (! isset($size['x'], $size['z'])) {
            return null;
        }

        return round((float) $size['x'] * (float) $size['z'], 2);
    }

    private function netArea(string $category, array $size): ?float
    {
        if (! in_array($category, ['patient_room', 'emergency_department', 'procedure_room', 'procedure_support', 'imaging', 'support_infrastructure'], true)) {
            return null;
        }

        return $this->area($size);
    }

    private function unitType(string $acuity, string $serviceLine): string
    {
        if ($acuity === 'icu' || str_contains($acuity, 'icu')) {
            return 'icu';
        }

        if ($acuity === 'telemetry' || $acuity === 'stepdown') {
            return 'step_down';
        }

        if ($serviceLine === 'emergency' || $acuity === 'emergency') {
            return 'ed';
        }

        return 'med_surg';
    }

    private function ratioFloor(string $acuity): int
    {
        return match ($acuity) {
            'icu', 'burn_icu', 'picu', 'nicu' => 2,
            'telemetry', 'stepdown' => 3,
            default => 4,
        };
    }

    private function accessStandardMinutes(string $acuity): int
    {
        return match ($acuity) {
            'icu', 'burn_icu', 'picu', 'nicu', 'emergency' => 60,
            default => 120,
        };
    }

    private function bedType(string $acuity): string
    {
        return match ($acuity) {
            'icu', 'burn_icu', 'picu', 'nicu' => 'icu',
            'telemetry', 'stepdown' => 'step_down',
            'rehab' => 'rehab',
            'behavioral_health' => 'behavioral_health',
            default => 'standard',
        };
    }

    private function spaceCode(string $facilityCode, string $objectCode): string
    {
        return "{$facilityCode}:{$objectCode}";
    }
}
