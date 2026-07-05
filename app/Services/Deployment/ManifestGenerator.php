<?php

namespace App\Services\Deployment;

use App\Models\Org\Facility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 5: assemble a hospital manifest (matching the config/hospital/hospital-1.php
 * shape) for a given facility_key from the deployment tables — hosp_org (geography +
 * capability matrix), hosp_ref (service-line registry), hosp_space (canonical spaces +
 * service-line bridge), and prod (operational units) — so a new client deploys from its
 * own capability matrix and facility-space import instead of copying Summit.
 *
 * Only structural blocks that are derivable from the deployment DB are populated
 * (facility, network_facilities, service_lines, units, census_demo_targets). Demo-only
 * pools that have no deployment source yet (providers, nurses, care_teams, transport
 * vendors, post-acute partners, ancillary teams) are emitted with valid, empty shapes;
 * they are filled by the Staffing Alignment Wizard (Phase 7) and per-client config.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 5)
 */
class ManifestGenerator
{
    public function __construct(private readonly ServiceLineNormalizer $normalizer) {}

    /**
     * Build the manifest array for one facility_key.
     *
     * @return array<string,mixed>
     */
    public function generate(string $facilityKey): array
    {
        $facility = Facility::query()->where('facility_key', $facilityKey)->first();

        if ($facility === null) {
            throw new RuntimeException(
                "No hosp_org.facilities row for facility_key '{$facilityKey}'. ".
                'Import the facility first (deployment:import-facilities).'
            );
        }

        $units = $this->buildUnits($facilityKey);

        return [
            'facility' => $this->buildFacility($facility),
            'network_facilities' => $this->buildNetworkFacilities($facility),
            'service_lines' => $this->buildServiceLines($facilityKey),
            'units' => $units,
            'providers' => [],
            'nurses' => [],
            'care_teams' => [],
            'transport' => $this->buildTransport($facility),
            'post_acute_network' => [],
            'ancillary_teams' => [],
            'census_demo_targets' => $this->buildCensusDemoTargets($units),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildFacility(Facility $facility): array
    {
        $meta = is_array($facility->metadata) ? $facility->metadata : [];

        return [
            'code' => $meta['code'] ?? $facility->facility_key,
            'name' => $facility->facility_name,
            'short_name' => $facility->short_name ?? $facility->facility_name,
            'city' => $meta['city'] ?? null,
            'state' => $facility->state,
            'region' => $facility->region,
            'type' => $meta['type'] ?? $this->deriveType($facility),
            'licensed_beds' => $facility->licensed_beds !== null ? (int) $facility->licensed_beds : null,
            // Physical-capacity denominator carried on the manifest (round-tripped via
            // hosp_org metadata); positioned to match the reference facility block.
            'discharge_lounge_chairs' => isset($meta['discharge_lounge_chairs']) ? (int) $meta['discharge_lounge_chairs'] : null,
            'cad_facility_code' => $facility->cad_facility_code,
            'tagline' => $meta['tagline'] ?? 'Operations Bridge',
        ];
    }

    /**
     * Every active facility in the same organization, flagship first (matches the manifest
     * convention where index 0 is the fully-modelled flagship).
     *
     * @return list<array<string,mixed>>
     */
    private function buildNetworkFacilities(Facility $facility): array
    {
        $siblings = Facility::query()
            ->where('organization_id', $facility->organization_id)
            ->where('is_active', true)
            ->orderBy('facility_name')
            ->get();

        $rows = $siblings->map(function (Facility $f): array {
            $meta = is_array($f->metadata) ? $f->metadata : [];

            return [
                'name' => $f->facility_name,
                'code' => $meta['code'] ?? $f->facility_key,
                'role' => $f->idn_role === 'flagship_quaternary_hub' ? 'flagship' : 'affiliate',
            ];
        })->all();

        // Flagship(s) first, then affiliates, preserving the alphabetical order within each group.
        usort($rows, fn (array $a, array $b): int => ($a['role'] === 'flagship' ? 0 : 1) <=> ($b['role'] === 'flagship' ? 0 : 1));

        return array_values($rows);
    }

    /**
     * The service lines this facility actually runs — every capability row at
     * `stabilize` or higher (a facility that stabilizes-and-transfers still operates the
     * line). Codes are already canonical in the registry; display names come from
     * hosp_ref. Ordered by the registry sort order.
     *
     * @return list<array<string,mixed>>
     */
    private function buildServiceLines(string $facilityKey): array
    {
        if (! Schema::hasTable('hosp_org.facility_service_capabilities')) {
            return [];
        }

        $rows = DB::table('hosp_org.facility_service_capabilities as fsc')
            ->join('hosp_ref.service_lines as sl', 'sl.service_line_code', '=', 'fsc.service_line_code')
            ->join('hosp_ref.capability_levels as cl', 'cl.code', '=', 'fsc.capability_level')
            ->where('fsc.facility_key', $facilityKey)
            ->where('cl.rank', '>=', 2) // stabilize(2)+; none/screen are "not a service line here"
            ->orderBy('sl.sort_order')
            ->orderBy('sl.display_name')
            ->get(['sl.service_line_code', 'sl.display_name']);

        $seen = [];
        $lines = [];

        foreach ($rows as $row) {
            $code = (string) $row->service_line_code;
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;

            $lines[] = [
                'code' => $code,
                'name' => (string) $row->display_name,
            ];
        }

        return $lines;
    }

    /**
     * Operational units for the facility, reconstructed from the canonical facility
     * spaces (cad_code, service line, acuity, floor) joined to their prod.units row
     * (abbr, staffed bed count, type). Falls back to prod.units directly for the default
     * facility before a facility-space import has run.
     *
     * @return list<array<string,mixed>>
     */
    private function buildUnits(string $facilityKey): array
    {
        if (Schema::hasTable('hosp_space.facility_spaces')) {
            $rows = DB::table('hosp_space.facility_spaces as fs')
                ->join('prod.units as u', 'u.facility_space_id', '=', 'fs.facility_space_id')
                ->where('fs.facility_key', $facilityKey)
                ->where('u.is_deleted', false)
                ->orderBy('fs.floor_number')
                ->orderBy('u.abbreviation')
                ->get([
                    'fs.facility_space_id',
                    'fs.space_code',
                    'fs.space_name',
                    'fs.service_line_code',
                    'fs.acuity_level',
                    'fs.floor_number',
                    'fs.attributes',
                    'u.abbreviation',
                    'u.name as unit_name',
                    'u.type',
                    'u.staffed_bed_count',
                    'u.ratio_floor',
                ]);

            if ($rows->isNotEmpty()) {
                $primaryByspace = $this->primaryServiceLinesBySpace($rows->pluck('facility_space_id')->all());

                return $rows->map(function (object $row) use ($primaryByspace): array {
                    $attrs = $this->decodeJson($row->attributes);
                    $serviceLine = $primaryByspace[(int) $row->facility_space_id]
                        ?? ($row->service_line_code !== null ? $this->normalizer->canonical((string) $row->service_line_code) : null);

                    return [
                        'abbr' => (string) $row->abbreviation,
                        'cad_code' => (string) $row->space_code,
                        'name' => (string) ($row->space_name ?: $row->unit_name),
                        'short_name' => (string) ($attrs['short_name'] ?? $row->unit_name),
                        'floor' => $row->floor_number !== null ? (int) $row->floor_number : ($attrs['floor'] ?? null),
                        'service_line' => $serviceLine ?: null,
                        'acuity' => $row->acuity_level ?: ($attrs['acuity'] ?? null),
                        'type' => (string) $row->type,
                        'staffed_bed_count' => (int) $row->staffed_bed_count,
                        'nurse_ratio' => isset($attrs['nurse_ratio']) ? (float) $attrs['nurse_ratio'] : (float) $row->ratio_floor,
                        'inpatient' => $attrs['inpatient'] ?? $this->isInpatientType((string) $row->type),
                    ];
                })->values()->all();
            }
        }

        // Pre-import fallback: the default facility's units live directly in prod.units
        // (seeded from the reference manifest) with no facility-space link yet.
        if ($facilityKey === (string) config('hospital.default_facility', 'SUMMIT_REGIONAL')) {
            return $this->buildUnitsFromProd();
        }

        return [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildUnitsFromProd(): array
    {
        $rows = DB::table('prod.units')
            ->where('is_deleted', false)
            ->orderBy('abbreviation')
            ->get(['abbreviation', 'name', 'type', 'staffed_bed_count', 'ratio_floor']);

        return $rows->map(fn (object $row): array => [
            'abbr' => (string) $row->abbreviation,
            'cad_code' => (string) $row->abbreviation,
            'name' => (string) $row->name,
            'short_name' => (string) $row->name,
            'floor' => null,
            'service_line' => null,
            'acuity' => null,
            'type' => (string) $row->type,
            'staffed_bed_count' => (int) $row->staffed_bed_count,
            'nurse_ratio' => (float) $row->ratio_floor,
            'inpatient' => $this->isInpatientType((string) $row->type),
        ])->values()->all();
    }

    /**
     * @param  list<int>  $spaceIds
     * @return array<int,string> facility_space_id => canonical primary service line
     */
    private function primaryServiceLinesBySpace(array $spaceIds): array
    {
        if ($spaceIds === [] || ! Schema::hasTable('hosp_space.facility_space_service_lines')) {
            return [];
        }

        $out = [];

        DB::table('hosp_space.facility_space_service_lines')
            ->whereIn('facility_space_id', $spaceIds)
            ->where('primary_flag', true)
            ->get(['facility_space_id', 'service_line_code'])
            ->each(function (object $row) use (&$out): void {
                $out[(int) $row->facility_space_id] = $this->normalizer->canonical((string) $row->service_line_code);
            });

        return $out;
    }

    /**
     * Demo census targets are derived from the unit roster (not stored in the deployment
     * DB): inpatient units default to 85% occupancy, throughput units (ED/perioperative)
     * to zero. Tuned per-client in the generated config afterwards.
     *
     * @param  list<array<string,mixed>>  $units
     * @return list<array<string,mixed>>
     */
    private function buildCensusDemoTargets(array $units): array
    {
        $targets = [];

        foreach ($units as $unit) {
            $beds = (int) ($unit['staffed_bed_count'] ?? 0);
            $inpatient = (bool) ($unit['inpatient'] ?? false);
            $occupancy = $inpatient ? 0.85 : 0.0;

            $targets[] = [
                'abbr' => (string) $unit['abbr'],
                'staffed_beds' => $beds,
                'target_occupancy' => $occupancy,
                'target_occupied' => (int) round($beds * $occupancy),
            ];
        }

        return $targets;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildTransport(Facility $facility): array
    {
        $short = $facility->short_name ?? $facility->facility_name;

        return [
            'internal_team' => [
                'name' => trim($short.' Patient Transport'),
                'key' => 'patient_transport',
            ],
            'vendors' => [],
        ];
    }

    private function deriveType(Facility $facility): string
    {
        $label = match ($facility->idn_role) {
            'flagship_quaternary_hub' => 'Quaternary Academic Medical Center',
            'regional_referral_hub' => 'Regional Referral Hospital',
            'academic_tertiary_hub' => 'Academic Tertiary Hospital',
            'community_hospital' => 'Community Hospital',
            'critical_access_or_rural_hospital' => 'Critical Access Hospital',
            default => Str::headline((string) ($facility->idn_role ?? 'Hospital')),
        };

        $trauma = $facility->trauma_level_adult;
        if (is_string($trauma) && $trauma !== '') {
            return trim("{$trauma} Trauma {$label}");
        }

        return $label;
    }

    private function isInpatientType(string $type): bool
    {
        return in_array($type, ['icu', 'med_surg', 'step_down'], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
