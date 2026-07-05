<?php

namespace App\Http\Controllers\Api\Deployment;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GET /api/deployment/capability-matrix?facility={key} — the Layer-3 grid (service line ×
 * capability level) for a facility, with evidence + review status per cell.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 6, §6)
 */
class CapabilityMatrixController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $facilityKey = $request->query('facility');

        if (! is_string($facilityKey) || $facilityKey === '') {
            return response()->json(['message' => 'A facility query parameter is required.'], 422);
        }

        if (! Schema::hasTable('hosp_org.facility_service_capabilities')) {
            return response()->json(['data' => ['facility_key' => $facilityKey, 'cells' => []], 'meta' => ['count' => 0]]);
        }

        $cells = DB::table('hosp_org.facility_service_capabilities as fsc')
            ->leftJoin('hosp_ref.service_lines as sl', 'sl.service_line_code', '=', 'fsc.service_line_code')
            ->leftJoin('hosp_ref.capability_levels as cl', 'cl.code', '=', 'fsc.capability_level')
            ->where('fsc.facility_key', $facilityKey)
            ->orderBy('sl.sort_order')
            ->orderBy('fsc.service_line_code')
            ->get([
                'fsc.service_line_code', 'sl.display_name', 'sl.clinical_domain',
                'fsc.capability_level', 'cl.rank', 'fsc.coverage_model', 'fsc.hours',
                'fsc.source_evidence_type', 'fsc.review_status',
            ])
            ->map(fn (object $r): array => [
                'service_line_code' => $r->service_line_code,
                'service_line_name' => $r->display_name,
                'clinical_domain' => $r->clinical_domain,
                'capability_level' => $r->capability_level,
                'capability_rank' => $r->rank !== null ? (int) $r->rank : null,
                'coverage_model' => $r->coverage_model,
                'hours' => $r->hours,
                'source_evidence_type' => $r->source_evidence_type,
                'review_status' => $r->review_status,
            ])->all();

        return response()->json([
            'data' => ['facility_key' => $facilityKey, 'cells' => $cells],
            'meta' => ['count' => count($cells)],
        ]);
    }
}
