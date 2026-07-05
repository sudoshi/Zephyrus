<?php

namespace App\Http\Controllers\Api\Deployment;

use App\Http\Controllers\Controller;
use App\Models\Org\Organization;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/deployment/organizations[/{key}] — the IDN tree (organizations → markets →
 * facilities). Layer-1 geography. Gated by viewDeploymentConsole.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 6, §6)
 */
class OrganizationController extends Controller
{
    public function index(): JsonResponse
    {
        $data = Organization::query()
            ->withCount(['markets', 'facilities'])
            ->orderBy('name')
            ->get()
            ->map(fn (Organization $org): array => [
                'organization_key' => $org->organization_key,
                'name' => $org->name,
                'short_name' => $org->short_name,
                'kind' => $org->kind,
                'headquarters_state' => $org->headquarters_state,
                'markets_count' => (int) $org->markets_count,
                'facilities_count' => (int) $org->facilities_count,
            ])->all();

        return response()->json(['data' => $data, 'meta' => ['count' => count($data)]]);
    }

    public function show(string $key): JsonResponse
    {
        $org = Organization::query()
            ->with(['markets' => fn ($q) => $q->orderBy('name')])
            ->where('organization_key', $key)
            ->first();

        if ($org === null) {
            return response()->json(['message' => "Unknown organization '{$key}'."], 404);
        }

        $facilities = $org->facilities()
            ->orderBy('facility_name')
            ->get(['facility_id', 'facility_key', 'facility_name', 'short_name', 'market_id', 'idn_role', 'state', 'region', 'licensed_beds', 'cad_facility_code', 'review_status', 'is_active']);

        return response()->json(['data' => [
            'organization_key' => $org->organization_key,
            'name' => $org->name,
            'short_name' => $org->short_name,
            'kind' => $org->kind,
            'headquarters_state' => $org->headquarters_state,
            'markets' => $org->markets->map(fn ($m): array => [
                'market_key' => $m->market_key,
                'name' => $m->name,
                'region' => $m->region,
                'state' => $m->state,
            ])->all(),
            'facilities' => $facilities->map(fn ($f): array => [
                'facility_key' => $f->facility_key,
                'facility_name' => $f->facility_name,
                'short_name' => $f->short_name,
                'idn_role' => $f->idn_role,
                'state' => $f->state,
                'region' => $f->region,
                'licensed_beds' => $f->licensed_beds !== null ? (int) $f->licensed_beds : null,
                'cad_facility_code' => $f->cad_facility_code,
                'review_status' => $f->review_status,
                'is_active' => (bool) $f->is_active,
            ])->all(),
        ]]);
    }
}
