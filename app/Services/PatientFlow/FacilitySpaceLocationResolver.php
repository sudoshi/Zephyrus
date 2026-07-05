<?php

namespace App\Services\PatientFlow;

use App\Models\Facility\FacilitySpace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

class FacilitySpaceLocationResolver
{
    /** @var array<string, array<string, mixed>|null> */
    private array $cache = [];

    private ?bool $bridgeAvailable = null;

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(?string $sourceLocationCode, string $facilityCode = 'ZEPHYRUS-500'): ?array
    {
        $sourceLocationCode = trim((string) $sourceLocationCode);
        if ($sourceLocationCode === '') {
            return null;
        }

        $cacheKey = "{$facilityCode}:{$sourceLocationCode}";
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $row = DB::table('hosp_space.facility_spaces')
            ->where('space_code', "{$facilityCode}:{$sourceLocationCode}")
            ->orWhere(function ($query) use ($facilityCode, $sourceLocationCode) {
                $query->where('space_code', 'like', "{$facilityCode}:%")
                    ->whereRaw("geometry->>'source_object_code' = ?", [$sourceLocationCode]);
            })
            ->orWhere(function ($query) use ($facilityCode, $sourceLocationCode) {
                $query->where('space_code', 'like', "{$facilityCode}:%")
                    ->whereRaw("attributes->>'source_object_code' = ?", [$sourceLocationCode]);
            })
            ->first();

        $this->cache[$cacheKey] = $row ? $this->spaceRowToPayload($row) : null;

        return $this->cache[$cacheKey];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allNavigatorLocations(string $facilityCode = 'ZEPHYRUS-500'): array
    {
        $rows = DB::table('hosp_space.facility_spaces')
            ->where('space_code', 'like', "{$facilityCode}:%")
            ->whereIn('space_category', [
                'bed',
                'bay',
                'room',
                'procedure_room',
                'imaging',
                'support',
                'utility',
                'helipad',
            ])
            ->orderBy('space_code')
            ->get();

        $serviceLinesBySpace = $this->serviceLinesForMany(
            $rows->pluck('facility_space_id')->map(fn ($id): int => (int) $id)->all()
        );

        $locations = [];
        foreach ($rows as $row) {
            $payload = $this->spaceRowToPayload($row, $serviceLinesBySpace[(int) $row->facility_space_id] ?? []);
            $locations[$payload['location_code']] = $payload;
        }

        return $locations;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function spaceToPayload(FacilitySpace|stdClass|null $space): ?array
    {
        if (! $space) {
            return null;
        }

        if ($space instanceof FacilitySpace) {
            return $this->spaceRowToPayload((object) [
                'facility_space_id' => $space->facility_space_id,
                'space_code' => $space->space_code,
                'space_name' => $space->space_name,
                'space_category' => $space->space_category,
                'floor_number' => $space->floor_number,
                'service_line_code' => $space->service_line_code,
                'location_role' => $space->location_role,
                'acuity_level' => $space->acuity_level,
                'geometry' => json_encode($space->geometry ?? [], JSON_THROW_ON_ERROR),
                'attributes' => json_encode($space->attributes ?? [], JSON_THROW_ON_ERROR),
            ]);
        }

        return $this->spaceRowToPayload($space);
    }

    /**
     * @param  list<string>|null  $serviceLines  pre-loaded bridge codes; null lazy-loads for this space
     * @return array<string, mixed>
     */
    private function spaceRowToPayload(stdClass $row, ?array $serviceLines = null): array
    {
        $facilitySpaceId = (int) $row->facility_space_id;
        $serviceLines ??= $this->serviceLinesFor($facilitySpaceId);
        $geometry = $this->decodeJson($row->geometry ?? '{}');
        $attributes = $this->decodeJson($row->attributes ?? '{}');
        $sourceCode = $geometry['source_object_code']
            ?? $attributes['source_object_code']
            ?? $this->sourceCodeFromSpaceCode((string) $row->space_code);
        $positionFt = $geometry['position_ft'] ?? [];
        $positionM = null;

        if (isset($positionFt['x'], $positionFt['z'])) {
            $level = (float) ($positionFt['level'] ?? $positionFt['y'] ?? 0);
            $positionM = [
                'x' => (float) $positionFt['x'] * 0.3048,
                'y' => $level * 0.3048 + 1.1,
                'z' => (float) $positionFt['z'] * 0.3048,
            ];
        }

        return [
            'facility_space_id' => $facilitySpaceId,
            'location_code' => $sourceCode,
            'source_location_code' => $sourceCode,
            'name' => (string) $row->space_name,
            'category' => (string) $row->space_category,
            'floor' => $row->floor_number !== null ? (int) $row->floor_number : null,
            'unit_code' => $attributes['unit_code'] ?? null,
            'service_line' => $row->service_line_code ?: ($attributes['service_line'] ?? null),
            'service_lines' => $serviceLines,
            'location_role' => ($row->location_role ?? null) ?: null,
            'acuity' => $row->acuity_level ?: ($attributes['acuity'] ?? null),
            'position_ft' => $positionFt,
            'position_m' => $positionM,
            'metadata' => $attributes,
        ];
    }

    /**
     * All service-line codes a space can serve (Layer 4 bridge), primary first.
     *
     * @return list<string>
     */
    private function serviceLinesFor(int $facilitySpaceId): array
    {
        if (! $this->bridgeAvailable()) {
            return [];
        }

        return DB::table('hosp_space.facility_space_service_lines')
            ->where('facility_space_id', $facilitySpaceId)
            ->orderByDesc('primary_flag')
            ->orderBy('service_line_code')
            ->pluck('service_line_code')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Batch variant to avoid N+1 when resolving many spaces at once.
     *
     * @param  list<int>  $facilitySpaceIds
     * @return array<int, list<string>>
     */
    private function serviceLinesForMany(array $facilitySpaceIds): array
    {
        if (! $this->bridgeAvailable() || $facilitySpaceIds === []) {
            return [];
        }

        $rows = DB::table('hosp_space.facility_space_service_lines')
            ->whereIn('facility_space_id', $facilitySpaceIds)
            ->orderByDesc('primary_flag')
            ->orderBy('service_line_code')
            ->get(['facility_space_id', 'service_line_code']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->facility_space_id][] = (string) $row->service_line_code;
        }

        foreach ($map as $id => $codes) {
            $map[$id] = array_values(array_unique($codes));
        }

        return $map;
    }

    private function bridgeAvailable(): bool
    {
        return $this->bridgeAvailable ??= Schema::hasTable('hosp_space.facility_space_service_lines');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function sourceCodeFromSpaceCode(string $spaceCode): string
    {
        if (str_contains($spaceCode, ':')) {
            return substr($spaceCode, (int) strrpos($spaceCode, ':') + 1);
        }

        return $spaceCode;
    }
}
