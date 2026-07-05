<?php

namespace App\Services\Flow;

use App\Models\Bed;
use App\Models\Facility\FacilitySpace;
use App\Models\Unit;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * 3D space-anchor asset for the NATIVE viewer (NATIVE-4D-VIEWER-PLAN §4 W3, D4).
 *
 * Ships the per-space 3D centroid (metres) + unit/bed bridges so the SceneKit /
 * Filament renderers can place patient tokens and duty markers by centroid over
 * the GLB shell — no fragile GLB-node mapping. Same source geometry as the 2D
 * plates (hosp_space.facility_spaces.geometry.position_ft {x, level, z}), just
 * projected to 3D metres. PHI-free, versioned by content hash, ETag-served at
 * GET /api/mobile/v1/flow/spaces3d.
 */
class Spaces3dAssetService
{
    public const ASSET_PATH = 'flow/spaces3d.json';

    private const FEET_TO_M = 0.3048;

    private const CATEGORIES = [
        'floor', 'zone', 'unit', 'room', 'bay', 'bed', 'corridor',
        'vertical_transport', 'procedure_room', 'imaging',
    ];

    public function __construct(private readonly HospitalManifest $manifest) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $spaces = FacilitySpace::query()
            ->whereIn('space_category', self::CATEGORIES)
            ->whereNotNull('floor_number')
            ->orderBy('floor_number')
            ->orderBy('space_code')
            ->get(['facility_space_id', 'space_code', 'space_category', 'floor_number', 'geometry']);

        $unitBySpace = Unit::query()->where('is_deleted', false)->whereNotNull('facility_space_id')
            ->pluck('unit_id', 'facility_space_id');
        $bedBySpace = Bed::query()->where('is_deleted', false)->whereNotNull('facility_space_id')
            ->get(['bed_id', 'unit_id', 'facility_space_id'])->keyBy('facility_space_id');

        $out = [];
        foreach ($spaces as $space) {
            $centroid = $this->centroid($space->geometry ?? []);
            if ($centroid === null) {
                continue;
            }

            $row = [
                'space_ref' => $space->space_code,
                'floor' => (int) $space->floor_number,
                'category' => $space->space_category,
                'unit_id' => ($u = $unitBySpace[$space->facility_space_id] ?? null) !== null ? (int) $u : null,
                'bed_id' => null,
                'centroid_m' => $centroid,
            ];
            if (($bed = $bedBySpace[$space->facility_space_id] ?? null) !== null) {
                $row['bed_id'] = (int) $bed->bed_id;
                $row['unit_id'] = (int) $bed->unit_id;
            }
            $out[] = $row;
        }

        $document = [
            'facility' => [
                'code' => $this->manifest->facilityCode(),
                'cad_code' => $this->manifest->cadFacilityCode(),
                'name' => $this->manifest->facilityName(),
            ],
            'units' => 'centroid_m {x, y, z} in metres; y is the vertical (floor) axis',
            'spaces' => $out,
        ];
        $document['version'] = 'v1-'.substr(sha1(json_encode($document['spaces'])), 0, 12);

        return $document;
    }

    /** @return array<string, mixed> */
    public function write(): array
    {
        $document = $this->build();
        Storage::disk('local')->put(self::ASSET_PATH, json_encode($document, JSON_UNESCAPED_SLASHES));
        Cache::forget('flow:spaces3d:doc');

        return $document;
    }

    /** @return array<string, mixed> */
    public function load(): array
    {
        return Cache::remember('flow:spaces3d:doc', 300, function (): array {
            $disk = Storage::disk('local');
            if ($disk->exists(self::ASSET_PATH)) {
                $decoded = json_decode((string) $disk->get(self::ASSET_PATH), true);
                if (is_array($decoded) && isset($decoded['version'])) {
                    return $decoded;
                }
            }

            return $this->write();
        });
    }

    /**
     * Center-origin CAD geometry (feet) → 3D centroid metres {x, y, z}.
     *
     * @param  array<string, mixed>  $geometry
     * @return array{x: float, y: float, z: float}|null
     */
    private function centroid(array $geometry): ?array
    {
        $position = $geometry['position_ft'] ?? null;
        if (! is_array($position) || ! isset($position['x'], $position['z'])) {
            return null;
        }

        return [
            'x' => round(((float) $position['x']) * self::FEET_TO_M, 3),
            'y' => round(((float) ($position['level'] ?? 0)) * self::FEET_TO_M, 3),
            'z' => round(((float) $position['z']) * self::FEET_TO_M, 3),
        ];
    }
}
