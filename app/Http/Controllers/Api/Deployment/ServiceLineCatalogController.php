<?php

namespace App\Http\Controllers\Api\Deployment;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GET /api/deployment/service-lines — the Layer-2 registry: service lines, programs,
 * capability tags, and the vocab lookups (capability levels, idn roles, location roles,
 * evidence classes). Read-only projection of hosp_ref; safe to cache client-side.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 6, §6)
 */
class ServiceLineCatalogController extends Controller
{
    public function index(): JsonResponse
    {
        if (! Schema::hasTable('hosp_ref.service_lines')) {
            return response()->json(['data' => [
                'service_lines' => [], 'programs' => [], 'capability_tags' => [],
                'capability_levels' => [], 'idn_roles' => [], 'location_roles' => [], 'evidence_classes' => [],
            ]]);
        }

        $serviceLines = DB::table('hosp_ref.service_lines')
            ->orderBy('sort_order')->orderBy('display_name')
            ->get(['service_line_code', 'display_name', 'clinical_domain', 'adult_or_pediatric', 'care_setting_default', 'default_workflow', 'sort_order', 'is_active'])
            ->map(fn (object $r): array => [
                'code' => $r->service_line_code,
                'name' => $r->display_name,
                'clinical_domain' => $r->clinical_domain,
                'adult_or_pediatric' => $r->adult_or_pediatric,
                'care_setting_default' => $r->care_setting_default,
                'default_workflow' => $r->default_workflow,
                'sort_order' => (int) $r->sort_order,
                'is_active' => (bool) $r->is_active,
            ])->all();

        return response()->json(['data' => [
            'service_lines' => $serviceLines,
            'programs' => $this->rows('hosp_ref.programs', ['program_code', 'service_line_code', 'display_name', 'designation_type', 'designation_body']),
            'capability_tags' => $this->rows('hosp_ref.capability_tags', ['tag_code', 'tag_category', 'display_name']),
            'capability_levels' => $this->rows('hosp_ref.capability_levels', ['code', 'display_name', 'rank']),
            'idn_roles' => $this->rows('hosp_ref.idn_roles', ['code', 'display_name', 'sort_order']),
            'location_roles' => $this->rows('hosp_ref.location_roles', ['code', 'display_name', 'sort_order']),
            'evidence_classes' => $this->rows('hosp_ref.evidence_classes', ['code', 'display_name', 'is_regulated']),
        ]]);
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string,mixed>>
     */
    private function rows(string $table, array $columns): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)->get($columns)->map(fn (object $r): array => (array) $r)->all();
    }
}
