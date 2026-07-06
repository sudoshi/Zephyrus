<?php

namespace App\Services\Flow;

use App\Models\Bed;
use App\Models\Facility\FacilitySpace;
use App\Models\Unit;
use App\Services\Deployment\ServiceLineNormalizer;
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

    /**
     * Bump on any change to the emitted document SHAPE (not its data). `load()` rebuilds
     * when the on-disk asset predates the current schema, so a deploy that adds a field
     * (e.g. the service-line legend) self-heals instead of serving the stale cached file.
     */
    private const SCHEMA_VERSION = 2;

    private const FEET_TO_M = 0.3048;

    private const CATEGORIES = [
        'floor', 'zone', 'unit', 'room', 'bay', 'bed', 'corridor',
        'vertical_transport', 'procedure_room', 'imaging',
    ];

    public function __construct(
        private readonly HospitalManifest $manifest,
        private readonly ServiceLineNormalizer $serviceLines,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $spaces = FacilitySpace::query()
            ->whereIn('space_category', self::CATEGORIES)
            ->whereNotNull('floor_number')
            ->orderBy('floor_number')
            ->orderBy('space_code')
            ->get(['facility_space_id', 'space_code', 'space_category', 'floor_number', 'service_line_code', 'geometry']);

        $unitBySpace = Unit::query()->where('is_deleted', false)->whereNotNull('facility_space_id')
            ->pluck('unit_id', 'facility_space_id');
        $bedBySpace = Bed::query()->where('is_deleted', false)->whereNotNull('facility_space_id')
            ->get(['bed_id', 'unit_id', 'facility_space_id'])->keyBy('facility_space_id');

        $out = [];
        $present = [];
        foreach ($spaces as $space) {
            $centroid = $this->centroid($space->geometry ?? []);
            if ($centroid === null) {
                continue;
            }

            // Fold legacy catalog codes (cardiology, medicine, trauma_surgery, …) onto the
            // canonical code so the map colors by one vocabulary regardless of import vintage.
            $serviceLine = null;
            if (($raw = trim((string) ($space->service_line_code ?? ''))) !== '') {
                $serviceLine = $this->serviceLines->canonical($raw);
                $present[$serviceLine] = true;
            }

            $row = [
                'space_ref' => $space->space_code,
                'floor' => (int) $space->floor_number,
                'category' => $space->space_category,
                'service_line' => $serviceLine,
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

        $legend = $this->legend(array_keys($present));

        $document = [
            'facility' => [
                'code' => $this->manifest->facilityCode(),
                'cad_code' => $this->manifest->cadFacilityCode(),
                'name' => $this->manifest->facilityName(),
            ],
            'units' => 'centroid_m {x, y, z} in metres; y is the vertical (floor) axis',
            'service_lines' => $legend,
            'spaces' => $out,
            'schema' => self::SCHEMA_VERSION,
        ];
        // Hash covers spaces + legend so a palette or catalog change bumps the ETag.
        $document['version'] = 'v1-'.substr(sha1(json_encode(['s' => $out, 'l' => $legend])), 0, 12);

        return $document;
    }

    /**
     * The colored legend for the service lines actually present in this asset: canonical
     * code -> {name, domain, color}. Sorted by the registry sort order so the on-map legend
     * reads in a stable clinical sequence. Names/domains from config/hospital/service-lines.php;
     * an `unassigned` entry gives corridors/unmapped rooms a neutral swatch.
     *
     * @param  list<string>  $codes  canonical service-line codes present in the spaces
     * @return array<string, array{name: string, domain: string|null, color: string, sort: int}>
     */
    private function legend(array $codes): array
    {
        $config = require base_path('config/hospital/service-lines.php');
        $definitions = $config['service_lines'] ?? [];
        $colors = $config['service_line_colors'] ?? [];
        $unassigned = $config['service_line_unassigned_color'] ?? '#556072';

        $legend = [];
        foreach ($codes as $code) {
            $definition = $definitions[$code] ?? [];
            $legend[$code] = [
                'name' => $definition['name'] ?? ucwords(str_replace('_', ' ', $code)),
                'domain' => $definition['domain'] ?? null,
                'color' => $colors[$code] ?? $unassigned,
                'sort' => (int) ($definition['sort'] ?? 999),
            ];
        }

        uasort($legend, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $legend['unassigned'] = [
            'name' => 'Unassigned / shared',
            'domain' => null,
            'color' => $unassigned,
            'sort' => 1000,
        ];

        return $legend;
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
                if (is_array($decoded) && isset($decoded['version'])
                    && ($decoded['schema'] ?? 1) === self::SCHEMA_VERSION) {
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
