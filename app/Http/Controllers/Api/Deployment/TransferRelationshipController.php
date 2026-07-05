<?php

namespace App\Http\Controllers\Api\Deployment;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Serves interfacility transfer subgraphs (Layer 1 transfer graph) filtered by service
 * line, facility, and direction. weight = typical_minutes is returned verbatim so a
 * downstream router can consume it without recomputation (Phase 4, task 3).
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (§6, Phase 4)
 */
class TransferRelationshipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('hosp_org.transfer_relationships')) {
            return response()->json(['data' => [], 'meta' => ['count' => 0]]);
        }

        $serviceLine = $request->query('service_line');
        $facility = $request->query('facility');
        $direction = $request->query('direction');

        $query = DB::table('hosp_org.transfer_relationships')
            ->where('is_active', true);

        if (is_string($serviceLine) && $serviceLine !== '') {
            $query->where('service_line_code', $serviceLine);
        }
        if (is_string($direction) && $direction !== '') {
            $query->where('direction', $direction);
        }
        if (is_string($facility) && $facility !== '') {
            $query->where(function ($q) use ($facility): void {
                $q->where('source_facility_key', $facility)
                    ->orWhere('destination_facility_key', $facility);
            });
        }

        $rows = $query
            ->orderBy('source_facility_key')
            ->orderBy('destination_facility_key')
            ->orderBy('service_line_code')
            ->get();

        $data = $rows->map(fn (object $row): array => [
            'transfer_relationship_id' => (int) $row->transfer_relationship_id,
            'source_facility_key' => $row->source_facility_key,
            'destination_facility_key' => $row->destination_facility_key,
            'destination_external_name' => $row->destination_external_name,
            'service_line_code' => $row->service_line_code,
            'program_code' => $row->program_code,
            'transport_mode' => $row->transport_mode,
            'direction' => $row->direction,
            'weight' => $row->typical_minutes !== null ? (int) $row->typical_minutes : null,
            'typical_minutes' => $row->typical_minutes !== null ? (int) $row->typical_minutes : null,
            'typical_miles' => $row->typical_miles !== null ? (float) $row->typical_miles : null,
            'is_external_partner' => (bool) $row->is_external_partner,
            'review_status' => $row->review_status,
        ])->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => ['count' => count($data)],
        ]);
    }
}
