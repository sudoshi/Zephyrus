<?php

namespace App\Http\Controllers\Api\Facility;

use App\Http\Controllers\Controller;
use App\Models\Facility\BlueprintImport;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FacilityModelController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $requestedFacilityCode = $this->requestedFacilityCode($request);
        $latestImport = $this->latestImport($requestedFacilityCode);
        $facilityCode = $requestedFacilityCode ?: $latestImport?->facility_code;

        $imports = $this->importsQuery($facilityCode);
        $objects = $this->objectQuery($latestImport);
        $spaces = $this->spaceQuery($facilityCode);
        $maps = $this->mapQuery($facilityCode);

        return response()->json([
            'data' => [
                'facility_code' => $facilityCode,
                'latest_import' => $this->serializeImport($latestImport),
                'imports' => [
                    'total' => (int) (clone $imports)->count(),
                    'by_status' => $this->countsBy((clone $imports), 'imports.status'),
                ],
                'blueprint_objects' => [
                    'latest_import_id' => $latestImport?->blueprint_import_id,
                    'total' => (int) (clone $objects)->count(),
                    'by_category' => $this->countsBy((clone $objects), 'objects.object_category'),
                    'by_review_status' => $this->countsBy((clone $objects), 'objects.review_status'),
                    'by_floor' => $this->countsBy(
                        (clone $objects),
                        'COALESCE(objects.floor_label, objects.floor_number::text)'
                    ),
                ],
                'facility_spaces' => [
                    'total' => (int) (clone $spaces)->count(),
                    'by_category' => $this->countsBy((clone $spaces), 'spaces.space_category'),
                    'by_status' => $this->countsBy((clone $spaces), 'spaces.status'),
                    'by_floor' => $this->countsBy(
                        (clone $spaces),
                        'COALESCE(spaces.floor_label, spaces.floor_number::text)'
                    ),
                ],
                'operational_mappings' => [
                    'total_active' => (int) (clone $maps)->count(),
                    'locations' => $this->mappedTargetCount((clone $maps), 'maps.location_id'),
                    'rooms' => $this->mappedTargetCount((clone $maps), 'maps.room_id'),
                    'units' => $this->mappedTargetCount((clone $maps), 'maps.unit_id'),
                    'beds' => $this->mappedTargetCount((clone $maps), 'maps.bed_id'),
                    'unmapped_spaces' => $this->unmappedSpaceCount($facilityCode),
                ],
                'prod_links' => [
                    'locations' => $this->linkedProdCount('locations', $facilityCode),
                    'rooms' => $this->linkedProdCount('rooms', $facilityCode),
                    'units' => $this->linkedProdCount('units', $facilityCode),
                    'beds' => $this->linkedProdCount('beds', $facilityCode),
                ],
            ],
        ]);
    }

    private function requestedFacilityCode(Request $request): ?string
    {
        $facilityCode = $request->query('facility_code');

        return is_string($facilityCode) && trim($facilityCode) !== ''
            ? trim($facilityCode)
            : null;
    }

    private function latestImport(?string $facilityCode): ?BlueprintImport
    {
        return BlueprintImport::query()
            ->when($facilityCode, fn ($query) => $query->where('facility_code', $facilityCode))
            ->orderByRaw('completed_at DESC NULLS LAST')
            ->orderByDesc('blueprint_import_id')
            ->first();
    }

    private function importsQuery(?string $facilityCode): Builder
    {
        return DB::table('hosp_ingest.blueprint_imports as imports')
            ->when($facilityCode, fn (Builder $query) => $query->where('imports.facility_code', $facilityCode));
    }

    private function objectQuery(?BlueprintImport $latestImport): Builder
    {
        $query = DB::table('hosp_ingest.blueprint_objects as objects');

        return $latestImport
            ? $query->where('objects.blueprint_import_id', $latestImport->blueprint_import_id)
            : $query->whereRaw('1 = 0');
    }

    private function spaceQuery(?string $facilityCode): Builder
    {
        return DB::table('hosp_space.facility_spaces as spaces')
            ->when($facilityCode, fn (Builder $query) => $query->where('spaces.space_code', 'like', "{$facilityCode}:%"));
    }

    private function mapQuery(?string $facilityCode): Builder
    {
        return DB::table('hosp_space.operational_space_maps as maps')
            ->join('hosp_space.facility_spaces as spaces', 'spaces.facility_space_id', '=', 'maps.facility_space_id')
            ->where('maps.active', true)
            ->when($facilityCode, fn (Builder $query) => $query->where('spaces.space_code', 'like', "{$facilityCode}:%"));
    }

    private function countsBy(Builder $query, string $bucketExpression): array
    {
        $bucketSql = "COALESCE(({$bucketExpression})::text, 'unknown')";

        return $query
            ->selectRaw("{$bucketSql} AS bucket")
            ->selectRaw('COUNT(*)::int AS total')
            ->groupByRaw($bucketSql)
            ->orderBy('bucket')
            ->get()
            ->mapWithKeys(fn (object $row) => [(string) $row->bucket => (int) $row->total])
            ->all();
    }

    private function mappedTargetCount(Builder $query, string $column): int
    {
        return (int) $query->whereNotNull($column)->count();
    }

    private function unmappedSpaceCount(?string $facilityCode): int
    {
        return (int) $this->spaceQuery($facilityCode)
            ->leftJoin('hosp_space.operational_space_maps as maps', function ($join) {
                $join->on('maps.facility_space_id', '=', 'spaces.facility_space_id')
                    ->where('maps.active', true);
            })
            ->whereNull('maps.operational_space_map_id')
            ->count();
    }

    private function linkedProdCount(string $table, ?string $facilityCode): int
    {
        return (int) DB::table("prod.{$table} as records")
            ->join('hosp_space.facility_spaces as spaces', 'spaces.facility_space_id', '=', 'records.facility_space_id')
            ->whereNotNull('records.facility_space_id')
            ->when($facilityCode, fn (Builder $query) => $query->where('spaces.space_code', 'like', "{$facilityCode}:%"))
            ->count();
    }

    private function serializeImport(?BlueprintImport $import): ?array
    {
        if (! $import) {
            return null;
        }

        return [
            'id' => (int) $import->blueprint_import_id,
            'source_name' => $import->source_name,
            'source_type' => $import->source_type,
            'source_checksum' => $import->source_checksum,
            'facility_code' => $import->facility_code,
            'facility_name' => $import->facility_name,
            'status' => $import->status,
            'coordinate_units' => $import->coordinate_units,
            'coordinate_system' => $import->coordinate_system,
            'started_at' => $import->started_at?->toISOString(),
            'completed_at' => $import->completed_at?->toISOString(),
            'model_name' => $import->metadata['model_name'] ?? null,
            'source_summary' => $import->metadata['summary'] ?? [],
        ];
    }
}
