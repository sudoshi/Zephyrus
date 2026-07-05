<?php

namespace App\Http\Controllers\Api\Deployment;

use App\Http\Controllers\Controller;
use App\Models\Org\Facility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GET /api/deployment/facilities[?state=&idn_role=&service_line=&capability_level=]
 * GET /api/deployment/facilities/{facilityKey}          (+ capability matrix, programs, transfers)
 * GET /api/deployment/facilities/{facilityKey}/spaces   (Layer-4 physical mapping)
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 6, §6)
 */
class FacilityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Facility::query()->where('is_active', true);

        if (is_string($state = $request->query('state')) && $state !== '') {
            $query->where('state', $state);
        }
        if (is_string($role = $request->query('idn_role')) && $role !== '') {
            $query->where('idn_role', $role);
        }

        $serviceLine = $request->query('service_line');
        $level = $request->query('capability_level');
        if ((is_string($serviceLine) && $serviceLine !== '') || (is_string($level) && $level !== '')) {
            $query->whereExists(function ($sub) use ($serviceLine, $level): void {
                $sub->from('hosp_org.facility_service_capabilities as fsc')
                    ->whereColumn('fsc.facility_id', 'facilities.facility_id');
                if (is_string($serviceLine) && $serviceLine !== '') {
                    $sub->where('fsc.service_line_code', $serviceLine);
                }
                if (is_string($level) && $level !== '') {
                    $sub->where('fsc.capability_level', $level);
                }
            });
        }

        $data = $query->orderBy('facility_name')
            ->get(['facility_key', 'facility_name', 'short_name', 'idn_role', 'state', 'region', 'county', 'licensed_beds', 'trauma_level_adult', 'stroke_level', 'cad_facility_code', 'review_status'])
            ->map(fn (Facility $f): array => [
                'facility_key' => $f->facility_key,
                'facility_name' => $f->facility_name,
                'short_name' => $f->short_name,
                'idn_role' => $f->idn_role,
                'state' => $f->state,
                'region' => $f->region,
                'county' => $f->county,
                'licensed_beds' => $f->licensed_beds !== null ? (int) $f->licensed_beds : null,
                'trauma_level_adult' => $f->trauma_level_adult,
                'stroke_level' => $f->stroke_level,
                'cad_facility_code' => $f->cad_facility_code,
                'review_status' => $f->review_status,
            ])->all();

        return response()->json(['data' => $data, 'meta' => ['count' => count($data)]]);
    }

    public function show(string $facilityKey): JsonResponse
    {
        $facility = Facility::query()->where('facility_key', $facilityKey)->first();

        if ($facility === null) {
            return response()->json(['message' => "Unknown facility '{$facilityKey}'."], 404);
        }

        return response()->json(['data' => [
            'facility' => [
                'facility_key' => $facility->facility_key,
                'facility_name' => $facility->facility_name,
                'short_name' => $facility->short_name,
                'idn_role' => $facility->idn_role,
                'state' => $facility->state,
                'region' => $facility->region,
                'county' => $facility->county,
                'licensed_beds' => $facility->licensed_beds !== null ? (int) $facility->licensed_beds : null,
                'trauma_level_adult' => $facility->trauma_level_adult,
                'stroke_level' => $facility->stroke_level,
                'maternal_level' => $facility->maternal_level,
                'neonatal_level' => $facility->neonatal_level,
                'burn_center_status' => $facility->burn_center_status,
                'transplant_center_status' => $facility->transplant_center_status,
                'cad_facility_code' => $facility->cad_facility_code,
                'review_status' => $facility->review_status,
            ],
            'capabilities' => $this->capabilityRows($facilityKey),
            'transfers' => $this->transferRows($facilityKey),
        ]]);
    }

    public function spaces(string $facilityKey): JsonResponse
    {
        if (! Schema::hasTable('hosp_space.facility_spaces')) {
            return response()->json(['data' => [], 'meta' => ['count' => 0]]);
        }

        $spaces = DB::table('hosp_space.facility_spaces')
            ->where('facility_key', $facilityKey)
            ->orderBy('floor_number')
            ->orderBy('space_code')
            ->get(['facility_space_id', 'space_code', 'space_name', 'space_category', 'floor_number', 'service_line_code', 'location_role', 'acuity_level', 'capability_tags', 'status']);

        $serviceLinesBySpace = $this->serviceLinesBySpace($spaces->pluck('facility_space_id')->all());
        $targetsBySpace = $this->operationalTargetsBySpace($spaces->pluck('facility_space_id')->all());

        $data = $spaces->map(fn (object $s): array => [
            'space_code' => $s->space_code,
            'space_name' => $s->space_name,
            'space_category' => $s->space_category,
            'floor_number' => $s->floor_number !== null ? (int) $s->floor_number : null,
            'primary_service_line' => $s->service_line_code,
            'location_role' => $s->location_role,
            'acuity_level' => $s->acuity_level,
            'service_lines' => $serviceLinesBySpace[(int) $s->facility_space_id] ?? [],
            'capability_tags' => \App\Casts\PgTextArray::parse($s->capability_tags),
            'operational_targets' => $targetsBySpace[(int) $s->facility_space_id] ?? [],
            'status' => $s->status,
        ])->all();

        return response()->json(['data' => $data, 'meta' => ['count' => count($data)]]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function capabilityRows(string $facilityKey): array
    {
        if (! Schema::hasTable('hosp_org.facility_service_capabilities')) {
            return [];
        }

        return DB::table('hosp_org.facility_service_capabilities as fsc')
            ->leftJoin('hosp_ref.service_lines as sl', 'sl.service_line_code', '=', 'fsc.service_line_code')
            ->where('fsc.facility_key', $facilityKey)
            ->orderBy('sl.sort_order')
            ->get(['fsc.service_line_code', 'sl.display_name', 'fsc.capability_level', 'fsc.coverage_model', 'fsc.hours', 'fsc.programs_present', 'fsc.source_evidence_type', 'fsc.review_status'])
            ->map(fn (object $r): array => [
                'service_line_code' => $r->service_line_code,
                'service_line_name' => $r->display_name,
                'capability_level' => $r->capability_level,
                'coverage_model' => $r->coverage_model,
                'hours' => $r->hours,
                'programs_present' => \App\Casts\PgTextArray::parse($r->programs_present),
                'source_evidence_type' => $r->source_evidence_type,
                'review_status' => $r->review_status,
            ])->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function transferRows(string $facilityKey): array
    {
        if (! Schema::hasTable('hosp_org.transfer_relationships')) {
            return [];
        }

        return DB::table('hosp_org.transfer_relationships')
            ->where('is_active', true)
            ->where(function ($q) use ($facilityKey): void {
                $q->where('source_facility_key', $facilityKey)->orWhere('destination_facility_key', $facilityKey);
            })
            ->orderBy('service_line_code')
            ->get(['source_facility_key', 'destination_facility_key', 'destination_external_name', 'service_line_code', 'transport_mode', 'direction', 'typical_minutes', 'is_external_partner'])
            ->map(fn (object $r): array => [
                'source_facility_key' => $r->source_facility_key,
                'destination_facility_key' => $r->destination_facility_key,
                'destination_external_name' => $r->destination_external_name,
                'service_line_code' => $r->service_line_code,
                'transport_mode' => $r->transport_mode,
                'direction' => $r->direction,
                'typical_minutes' => $r->typical_minutes !== null ? (int) $r->typical_minutes : null,
                'is_external_partner' => (bool) $r->is_external_partner,
            ])->all();
    }

    /**
     * @param  list<int>  $spaceIds
     * @return array<int,list<string>>
     */
    private function serviceLinesBySpace(array $spaceIds): array
    {
        if ($spaceIds === [] || ! Schema::hasTable('hosp_space.facility_space_service_lines')) {
            return [];
        }

        $out = [];
        DB::table('hosp_space.facility_space_service_lines')
            ->whereIn('facility_space_id', $spaceIds)
            ->orderByDesc('primary_flag')
            ->get(['facility_space_id', 'service_line_code'])
            ->each(function (object $r) use (&$out): void {
                $out[(int) $r->facility_space_id][] = $r->service_line_code;
            });

        return $out;
    }

    /**
     * @param  list<int>  $spaceIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function operationalTargetsBySpace(array $spaceIds): array
    {
        if ($spaceIds === [] || ! Schema::hasTable('hosp_space.operational_space_maps')) {
            return [];
        }

        $out = [];
        DB::table('hosp_space.operational_space_maps')
            ->whereIn('facility_space_id', $spaceIds)
            ->get(['facility_space_id', 'location_id', 'room_id', 'unit_id', 'bed_id'])
            ->each(function (object $r) use (&$out): void {
                $kind = match (true) {
                    $r->unit_id !== null => 'unit',
                    $r->room_id !== null => 'room',
                    $r->bed_id !== null => 'bed',
                    $r->location_id !== null => 'location',
                    default => 'unknown',
                };
                $out[(int) $r->facility_space_id][] = [
                    'target_kind' => $kind,
                    'target_id' => (int) ($r->unit_id ?? $r->room_id ?? $r->bed_id ?? $r->location_id ?? 0),
                ];
            });

        return $out;
    }
}
