<?php

namespace App\Services\Flow;

use App\Models\Bed;
use App\Models\Facility\FacilitySpace;
use App\Models\Unit;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Support\Facades\Storage;

/**
 * Precomputed, simplified 2D floor plates for the mobile Flow map —
 * FLOW-WINDOW-PLAN §6.1 (W1) and D4 ("mobile renders plates, not the GLB").
 *
 * Source geometry is hosp_space.facility_spaces.geometry (center-origin
 * position_ft {x, level, z} + size_ft {x, y, z} from the CAD catalog import).
 * Each space is reduced to an axis-aligned plan-view rect [x, y, w, h] in
 * feet, where x/y are the top-left corner (x − w/2, z − h/2). That keeps a
 * whole floor under a few hundred drawn shapes and well below the 60 KB
 * gzipped budget — no 3D engine, no 771 KB GLB on the phone.
 *
 * The asset is versioned by content hash and persisted to
 * storage/app/private/flow/floor-plates.json so `GET /api/mobile/v1/flow/floors`
 * can serve it with a strong ETag.
 */
class FloorPlateAssetService
{
    public const ASSET_PATH = 'flow/floor-plates.json';

    /** Plan-view categories worth drawing (≤500 shapes per floor). */
    private const PLATE_CATEGORIES = [
        'floor', 'zone', 'unit', 'room', 'bay', 'bed', 'corridor',
        'vertical_transport', 'procedure_room', 'imaging',
    ];

    public function __construct(private readonly HospitalManifest $manifest) {}

    /**
     * Build the full plates document from hosp_space + operational bridges.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $spaces = FacilitySpace::query()
            ->whereIn('space_category', self::PLATE_CATEGORIES)
            ->whereNotNull('floor_number')
            ->orderBy('floor_number')
            ->orderBy('space_code')
            ->get(['facility_space_id', 'space_code', 'space_name', 'space_category', 'floor_number', 'geometry']);

        $unitBySpace = Unit::query()
            ->where('is_deleted', false)
            ->whereNotNull('facility_space_id')
            ->pluck('unit_id', 'facility_space_id');

        $bedBySpace = Bed::query()
            ->where('is_deleted', false)
            ->whereNotNull('facility_space_id')
            ->get(['bed_id', 'unit_id', 'facility_space_id'])
            ->keyBy('facility_space_id');

        $floors = [];
        foreach ($spaces as $space) {
            $rect = $this->rectFor($space->geometry ?? []);
            if ($rect === null) {
                continue;
            }

            $floor = (int) $space->floor_number;
            $plate = [
                'id' => (int) $space->facility_space_id,
                'code' => $space->space_code,
                'category' => $space->space_category,
                'label' => $space->space_name,
                'rect' => $rect,
            ];

            if (($unitId = $unitBySpace[$space->facility_space_id] ?? null) !== null) {
                $plate['unit_id'] = (int) $unitId;
            }
            if (($bed = $bedBySpace[$space->facility_space_id] ?? null) !== null) {
                $plate['bed_id'] = (int) $bed->bed_id;
                $plate['unit_id'] = (int) $bed->unit_id;
            }

            if ($space->space_category === 'floor') {
                $floors[$floor]['bounds'] = $rect;
            } else {
                $floors[$floor]['spaces'][] = $plate;
            }

            $floors[$floor]['floor'] = $floor;
        }

        ksort($floors);

        $floorDocs = array_values(array_map(function (array $row): array {
            $spaces = $row['spaces'] ?? [];

            return [
                'floor' => $row['floor'],
                'label' => "Floor {$row['floor']}",
                'bounds' => $row['bounds'] ?? $this->boundsOf($spaces),
                'shape_count' => count($spaces),
                'spaces' => $spaces,
            ];
        }, $floors));

        $document = [
            'facility' => [
                'code' => $this->manifest->facilityCode(),
                'cad_code' => $this->manifest->cadFacilityCode(),
                'name' => $this->manifest->facilityName(),
            ],
            'units' => 'plan-view feet, top-left origin per rect [x, y, w, h]',
            'floors' => $floorDocs,
        ];

        $document['version'] = 'v1-'.substr(sha1(json_encode($document['floors'])), 0, 12);

        return $document;
    }

    /**
     * Build + persist the versioned asset. Returns the document.
     *
     * @return array<string, mixed>
     */
    public function write(): array
    {
        $document = $this->build();
        Storage::disk('local')->put(self::ASSET_PATH, json_encode($document, JSON_UNESCAPED_SLASHES));
        \Illuminate\Support\Facades\Cache::forget('flow:plates:doc');

        return $document;
    }

    /**
     * The persisted asset, building it on first access so the endpoint
     * works against a freshly seeded database without a manual export.
     * Short-cached so polling clients don't re-read + re-decode the JSON
     * from disk on every request (write() invalidates).
     *
     * @return array<string, mixed>
     */
    public function load(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('flow:plates:doc', 300, function (): array {
            $disk = Storage::disk('local');
            if ($disk->exists(self::ASSET_PATH)) {
                $decoded = json_decode((string) $disk->get(self::ASSET_PATH), true);
                if (is_array($decoded) && isset($decoded['version'])) {
                    return $decoded;
                }
            }

            // First access on a fresh database: build AND persist (write()'s
            // Cache::forget is harmless here — remember() puts afterwards).
            return $this->write();
        });
    }

    /**
     * Beds whose staffed reality has no mapped space — the W1 plausibility
     * gate (every staffed bed must map to a facility space).
     *
     * @return list<array{bed_id: int, unit_id: int, label: ?string}>
     */
    public function unmappedBeds(): array
    {
        return Bed::query()
            ->where('is_deleted', false)
            ->whereNull('facility_space_id')
            ->orderBy('bed_id')
            ->get(['bed_id', 'unit_id', 'label'])
            ->map(fn (Bed $bed): array => [
                'bed_id' => (int) $bed->bed_id,
                'unit_id' => (int) $bed->unit_id,
                'label' => $bed->label,
            ])
            ->all();
    }

    /**
     * Center-origin CAD geometry → plan-view rect [x, y, w, h] (feet, 1 dp).
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function rectFor(array $geometry): ?array
    {
        $position = $geometry['position_ft'] ?? null;
        $size = $geometry['size_ft'] ?? null;

        if (! is_array($position) || ! is_array($size)) {
            return null;
        }

        $w = (float) ($size['x'] ?? 0);
        $h = (float) ($size['z'] ?? 0);
        if ($w <= 0 || $h <= 0) {
            return null;
        }

        $x = (float) ($position['x'] ?? 0) - $w / 2;
        $y = (float) ($position['z'] ?? 0) - $h / 2;

        return [round($x, 1), round($y, 1), round($w, 1), round($h, 1)];
    }

    /**
     * Fallback floor bounds from member spaces when no 'floor' slab exists.
     *
     * @param  list<array<string, mixed>>  $spaces
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function boundsOf(array $spaces): array
    {
        if ($spaces === []) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        $minX = $minY = INF;
        $maxX = $maxY = -INF;
        foreach ($spaces as $space) {
            [$x, $y, $w, $h] = $space['rect'];
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x + $w);
            $maxY = max($maxY, $y + $h);
        }

        return [round($minX, 1), round($minY, 1), round($maxX - $minX, 1), round($maxY - $minY, 1)];
    }
}
