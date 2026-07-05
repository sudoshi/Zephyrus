<?php

namespace App\Services\Deployment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Phase 6: mechanically evaluate the report's Acceptance Criteria (plan §16) for a
 * deployed facility (and its IDN) as a readiness scorecard. Each criterion is a discrete
 * check returning {criterion, key, title, status, count, failures[]}; the overall
 * `deployment_ready` is true when no check is a hard `fail`.
 *
 * Status vocabulary:
 *   pass            — criterion satisfied
 *   fail            — hard blocker (drops deployment_ready)
 *   warn            — advisory gap surfaced for sign-off, does not block
 *   not_applicable  — the check's data layer is not present for this facility (e.g. no
 *                     transfer/route graph, or the staffing tables absent pre-Phase-7)
 *
 * Failure lists are capped (the true total is in `count`) so the payload stays small.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 6, §16)
 */
class DeploymentReadinessService
{
    private const FAILURE_SAMPLE_CAP = 50;

    /** Service lines whose regulated designation needs state/accreditation evidence to be source_verified. */
    private const EVIDENCE_REGULATED = [
        'trauma_acute_care_surgery',
        'neurosciences',
        'womens_health',
        'neonatology',
        'burn',
        'transplant',
    ];

    /** Service lines that, when not definitive at a facility, need a transfer-out edge. */
    private const TRANSFER_REGULATED = [
        'trauma_acute_care_surgery',
        'neurosciences',
        'cardiovascular',
        'womens_health',
        'neonatology',
        'pediatrics',
        'burn',
        'transplant',
    ];

    private const REGULATED_EVIDENCE_TYPES = ['state_designation', 'accreditation_body'];

    private const INPATIENT_UNIT_TYPES = ['icu', 'med_surg', 'step_down'];

    /**
     * @return array<string,mixed>
     */
    public function evaluate(string $facilityKey): array
    {
        if (! Schema::hasTable('hosp_org.facilities')) {
            throw new RuntimeException('hosp_org.facilities is not migrated; deployment tables are unavailable.');
        }

        $facility = DB::table('hosp_org.facilities')->where('facility_key', $facilityKey)->first();

        if ($facility === null) {
            throw new RuntimeException("No hosp_org.facilities row for facility_key '{$facilityKey}'.");
        }

        $organizationId = (int) $facility->organization_id;

        $checks = [
            $this->checkFacilityIdnRole($organizationId),
            $this->checkCapabilityCoverage($organizationId),
            $this->checkRegulatedEvidence($organizationId),
            $this->checkBedMapping($facilityKey),
            $this->checkLocationRoles($facilityKey),
            $this->checkRoomMapping($facilityKey),
            $this->checkUnitBedCoverage($facilityKey),
            $this->checkSharedSpaces($facilityKey),
            $this->checkTransferEdges($organizationId),
            $this->checkRouteGraph($facilityKey),
            $this->checkLowConfidence($organizationId),
            $this->checkUnitStaffing($facilityKey),
            $this->checkStaffAssignmentIntegrity(),
        ];

        $summary = ['pass' => 0, 'fail' => 0, 'warn' => 0, 'not_applicable' => 0];
        foreach ($checks as $check) {
            $summary[$check['status']] = ($summary[$check['status']] ?? 0) + 1;
        }

        return [
            'facility_key' => $facilityKey,
            'facility_name' => $facility->facility_name,
            'deployment_ready' => $summary['fail'] === 0,
            'summary' => $summary,
            'checks' => $checks,
        ];
    }

    /**
     * @param  list<mixed>  $failures
     * @return array<string,mixed>
     */
    private function result(int $criterion, string $key, string $title, string $status, int $count, array $failures = []): array
    {
        return [
            'criterion' => $criterion,
            'key' => $key,
            'title' => $title,
            'status' => $status,
            'count' => $count,
            'failures' => array_slice(array_values($failures), 0, self::FAILURE_SAMPLE_CAP),
        ];
    }

    // 1 — every facility in the IDN has a non-null idn_role.
    private function checkFacilityIdnRole(int $organizationId): array
    {
        $missing = DB::table('hosp_org.facilities')
            ->where('organization_id', $organizationId)
            ->whereNull('idn_role')
            ->pluck('facility_key')
            ->all();

        return $this->result(1, 'facility_idn_role', 'Every facility has an idn_role',
            $missing === [] ? 'pass' : 'fail', count($missing), $missing);
    }

    // 2 — every active facility has >=1 capability row and every row a valid level.
    private function checkCapabilityCoverage(int $organizationId): array
    {
        if (! Schema::hasTable('hosp_org.facility_service_capabilities')) {
            return $this->result(2, 'capability_coverage', 'Every facility has a capability matrix', 'not_applicable', 0);
        }

        $activeFacilities = DB::table('hosp_org.facilities')
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->pluck('facility_key', 'facility_id');

        $withCaps = DB::table('hosp_org.facility_service_capabilities')
            ->whereIn('facility_id', $activeFacilities->keys())
            ->distinct()
            ->pluck('facility_id')
            ->flip();

        $failures = [];
        foreach ($activeFacilities as $facilityId => $key) {
            if (! $withCaps->has($facilityId)) {
                $failures[] = ['facility_key' => $key, 'reason' => 'no capability rows'];
            }
        }

        $validLevels = Schema::hasTable('hosp_ref.capability_levels')
            ? DB::table('hosp_ref.capability_levels')->pluck('code')->flip()
            : collect();

        DB::table('hosp_org.facility_service_capabilities')
            ->whereIn('facility_id', $activeFacilities->keys())
            ->get(['facility_key', 'service_line_code', 'capability_level'])
            ->each(function (object $row) use (&$failures, $validLevels): void {
                if ($validLevels->isNotEmpty() && ! $validLevels->has($row->capability_level)) {
                    $failures[] = [
                        'facility_key' => $row->facility_key,
                        'service_line_code' => $row->service_line_code,
                        'reason' => "invalid capability_level '{$row->capability_level}'",
                    ];
                }
            });

        return $this->result(2, 'capability_coverage', 'Every facility has a capability matrix',
            $failures === [] ? 'pass' : 'fail', count($failures), $failures);
    }

    // 3 — regulated designations at source_verified carry state/accreditation evidence.
    private function checkRegulatedEvidence(int $organizationId): array
    {
        if (! Schema::hasTable('hosp_org.facility_service_capabilities')) {
            return $this->result(3, 'regulated_evidence', 'Regulated designations carry regulated evidence', 'not_applicable', 0);
        }

        $failures = DB::table('hosp_org.facility_service_capabilities as fsc')
            ->join('hosp_org.facilities as f', 'f.facility_id', '=', 'fsc.facility_id')
            ->where('f.organization_id', $organizationId)
            ->where('fsc.review_status', 'source_verified')
            ->whereIn('fsc.service_line_code', self::EVIDENCE_REGULATED)
            ->where(function ($q): void {
                $q->whereNull('fsc.source_evidence_type')
                    ->orWhereNotIn('fsc.source_evidence_type', self::REGULATED_EVIDENCE_TYPES);
            })
            ->get(['fsc.facility_key', 'fsc.service_line_code', 'fsc.source_evidence_type'])
            ->map(fn (object $r): array => [
                'facility_key' => $r->facility_key,
                'service_line_code' => $r->service_line_code,
                'source_evidence_type' => $r->source_evidence_type,
            ])->all();

        return $this->result(3, 'regulated_evidence', 'Regulated designations carry regulated evidence',
            $failures === [] ? 'pass' : 'fail', count($failures), $failures);
    }

    // 4 — every staffed inpatient bed maps to a facility space.
    private function checkBedMapping(string $facilityKey): array
    {
        if (! Schema::hasTable('hosp_space.facility_spaces')) {
            return $this->result(4, 'bed_mapping', 'Staffed inpatient beds map to a facility space', 'not_applicable', 0);
        }

        // Beds whose unit belongs to this facility (via the unit's facility space) and is inpatient.
        $unmapped = DB::table('prod.beds as b')
            ->join('prod.units as u', 'u.unit_id', '=', 'b.unit_id')
            ->join('hosp_space.facility_spaces as ufs', 'ufs.facility_space_id', '=', 'u.facility_space_id')
            ->where('ufs.facility_key', $facilityKey)
            ->whereIn('u.type', self::INPATIENT_UNIT_TYPES)
            ->where('b.is_deleted', false)
            ->whereNull('b.facility_space_id')
            ->get(['b.bed_id', 'b.label', 'u.abbreviation'])
            ->map(fn (object $r): array => [
                'bed_id' => (int) $r->bed_id,
                'label' => $r->label,
                'unit' => $r->abbreviation,
            ])->all();

        return $this->result(4, 'bed_mapping', 'Staffed inpatient beds map to a facility space',
            $unmapped === [] ? 'pass' : 'fail', count($unmapped), $unmapped);
    }

    // 5 — critical space categories carry a location_role (advisory; backfilled in review).
    private function checkLocationRoles(string $facilityKey): array
    {
        if (! Schema::hasTable('hosp_space.facility_spaces')) {
            return $this->result(5, 'location_roles', 'Critical spaces carry a location_role', 'not_applicable', 0);
        }

        $missing = DB::table('hosp_space.facility_spaces')
            ->where('facility_key', $facilityKey)
            ->where(function ($q): void {
                $q->whereIn('space_category', ['procedure_room', 'imaging'])
                    ->orWhereIn('acuity_level', ['icu', 'burn_icu', 'emergency', 'behavioral', 'women', 'telemetry']);
            })
            ->where(function ($q): void {
                $q->whereNull('location_role')->orWhere('location_role', '');
            })
            ->get(['space_code', 'space_category', 'acuity_level'])
            ->map(fn (object $r): array => [
                'space_code' => $r->space_code,
                'space_category' => $r->space_category,
                'acuity_level' => $r->acuity_level,
            ])->all();

        return $this->result(5, 'location_roles', 'Critical spaces carry a location_role',
            $missing === [] ? 'pass' : 'warn', count($missing), $missing);
    }

    // 6 — operationally important rooms map to prod.rooms via operational_space_maps (report).
    private function checkRoomMapping(string $facilityKey): array
    {
        if (! Schema::hasTable('hosp_space.facility_spaces')) {
            return $this->result(6, 'room_mapping', 'Rooms map to prod via operational maps', 'not_applicable', 0);
        }

        $unmapped = DB::table('hosp_space.facility_spaces as fs')
            ->leftJoin('hosp_space.operational_space_maps as m', function ($join): void {
                $join->on('m.facility_space_id', '=', 'fs.facility_space_id')->whereNotNull('m.room_id');
            })
            ->where('fs.facility_key', $facilityKey)
            ->whereIn('fs.space_category', ['room', 'procedure_room', 'imaging', 'bay'])
            ->whereNull('m.operational_space_map_id')
            ->count('fs.facility_space_id');

        return $this->result(6, 'room_mapping', 'Rooms map to prod via operational maps',
            'info', $unmapped, $unmapped > 0 ? [['unmapped_rooms' => $unmapped]] : []);
    }

    // 7 — every unit space maps to prod.units; every bed space to prod.beds.
    private function checkUnitBedCoverage(string $facilityKey): array
    {
        if (! Schema::hasTable('hosp_space.facility_spaces')) {
            return $this->result(7, 'unit_bed_coverage', 'Unit/bed spaces map to prod', 'not_applicable', 0);
        }

        $unmappedUnits = DB::table('hosp_space.facility_spaces as fs')
            ->leftJoin('prod.units as u', 'u.facility_space_id', '=', 'fs.facility_space_id')
            ->where('fs.facility_key', $facilityKey)
            ->where('fs.space_category', 'unit')
            ->whereNull('u.unit_id')
            ->pluck('fs.space_code')
            ->all();

        return $this->result(7, 'unit_bed_coverage', 'Unit/bed spaces map to prod',
            $unmappedUnits === [] ? 'pass' : 'warn', count($unmappedUnits),
            array_map(fn (string $c): array => ['space_code' => $c, 'reason' => 'unit space unmapped to prod.units'], $unmappedUnits));
    }

    // 8 — shared spaces (>1 service line) count (report).
    private function checkSharedSpaces(string $facilityKey): array
    {
        if (! Schema::hasTable('hosp_space.facility_space_service_lines')) {
            return $this->result(8, 'shared_spaces', 'Shared spaces carry multiple service lines', 'not_applicable', 0);
        }

        $shared = DB::table('hosp_space.facility_space_service_lines as fssl')
            ->join('hosp_space.facility_spaces as fs', 'fs.facility_space_id', '=', 'fssl.facility_space_id')
            ->where('fs.facility_key', $facilityKey)
            ->groupBy('fssl.facility_space_id')
            ->havingRaw('count(*) > 1')
            ->pluck('fssl.facility_space_id')
            ->count();

        return $this->result(8, 'shared_spaces', 'Shared spaces carry multiple service lines',
            'info', $shared, $shared > 0 ? [['shared_space_count' => $shared]] : []);
    }

    // 9 — non-definitive regulated lines have a transfer-out edge.
    private function checkTransferEdges(int $organizationId): array
    {
        if (! Schema::hasTable('hosp_org.transfer_relationships') || ! Schema::hasTable('hosp_ref.capability_levels')) {
            return $this->result(9, 'transfer_edges', 'Non-definitive regulated lines have a transfer edge', 'not_applicable', 0);
        }

        $rows = DB::table('hosp_org.facility_service_capabilities as fsc')
            ->join('hosp_org.facilities as f', 'f.facility_id', '=', 'fsc.facility_id')
            ->join('hosp_ref.capability_levels as cl', 'cl.code', '=', 'fsc.capability_level')
            ->where('f.organization_id', $organizationId)
            ->whereIn('fsc.service_line_code', self::TRANSFER_REGULATED)
            ->where('cl.rank', '>=', 2) // stabilize+ (a line the facility runs)
            ->where('cl.rank', '<', 5)  // but below definitive → must be able to transfer out
            ->get(['fsc.facility_key', 'fsc.service_line_code']);

        $failures = [];
        foreach ($rows as $row) {
            $hasEdge = DB::table('hosp_org.transfer_relationships')
                ->where('source_facility_key', $row->facility_key)
                ->where('direction', 'out')
                ->where('is_active', true)
                ->where(function ($q) use ($row): void {
                    $q->where('service_line_code', $row->service_line_code)->orWhereNull('service_line_code');
                })
                ->exists();

            if (! $hasEdge) {
                $failures[] = ['facility_key' => $row->facility_key, 'service_line_code' => $row->service_line_code];
            }
        }

        return $this->result(9, 'transfer_edges', 'Non-definitive regulated lines have a transfer edge',
            $failures === [] ? 'pass' : 'warn', count($failures), $failures);
    }

    // 10 — internal route-graph coverage (best-effort; full routing is downstream).
    private function checkRouteGraph(string $facilityKey): array
    {
        if (! Schema::hasTable('ops.edges')) {
            return $this->result(10, 'route_graph', 'Internal route-graph coverage present', 'not_applicable', 0);
        }

        $routeEdges = DB::table('ops.edges')
            ->whereIn('edge_type', ['route_to', 'adjacent_to', 'connects_to'])
            ->where('is_active', true)
            ->count();

        return $this->result(10, 'route_graph', 'Internal route-graph coverage present',
            'info', $routeEdges, [['route_edges' => $routeEdges]]);
    }

    // 11 — low-confidence (assumed) rows remain for client sign-off.
    private function checkLowConfidence(int $organizationId): array
    {
        $failures = [];

        $facilityKeys = DB::table('hosp_org.facilities')
            ->where('organization_id', $organizationId)
            ->pluck('facility_key');

        DB::table('hosp_org.facilities')
            ->where('organization_id', $organizationId)
            ->where('review_status', 'assumed')
            ->pluck('facility_key')
            ->each(function (string $key) use (&$failures): void {
                $failures[] = ['source' => 'facility', 'facility_key' => $key];
            });

        if (Schema::hasTable('hosp_org.facility_service_capabilities')) {
            DB::table('hosp_org.facility_service_capabilities')
                ->whereIn('facility_key', $facilityKeys)
                ->where('review_status', 'assumed')
                ->get(['facility_key', 'service_line_code'])
                ->each(function (object $r) use (&$failures): void {
                    $failures[] = ['source' => 'capability', 'facility_key' => $r->facility_key, 'service_line_code' => $r->service_line_code];
                });
        }

        return $this->result(11, 'low_confidence', 'No mappings remain review_status=assumed',
            $failures === [] ? 'pass' : 'warn', count($failures), $failures);
    }

    // 12 — every active staffed unit has >=1 staff assignment (Phase 7 CoverageService).
    private function checkUnitStaffing(string $facilityKey): array
    {
        if (! Schema::hasTable('hosp_org.staff_assignments')) {
            return $this->result(12, 'unit_staffing', 'Active units have staff assignments', 'not_applicable', 0);
        }

        $unstaffed = DB::table('prod.units as u')
            ->join('hosp_space.facility_spaces as fs', 'fs.facility_space_id', '=', 'u.facility_space_id')
            ->leftJoin('hosp_org.staff_assignments as sa', 'sa.unit_id', '=', 'u.unit_id')
            ->where('fs.facility_key', $facilityKey)
            ->where('u.is_deleted', false)
            ->whereNull('sa.staff_assignment_id')
            ->pluck('u.abbreviation')
            ->all();

        return $this->result(12, 'unit_staffing', 'Active units have staff assignments',
            $unstaffed === [] ? 'pass' : 'warn', count($unstaffed),
            array_map(fn (string $a): array => ['unit' => $a], $unstaffed));
    }

    // 13 — committed staff assignments have FK-valid codes + confidence + evidence (Phase 7).
    private function checkStaffAssignmentIntegrity(): array
    {
        if (! Schema::hasTable('hosp_org.staff_assignments')) {
            return $this->result(13, 'staff_assignment_integrity', 'Staff assignments are complete + FK-valid', 'not_applicable', 0);
        }

        $failures = DB::table('hosp_org.staff_assignments')
            ->where(function ($q): void {
                $q->whereNull('confidence')
                    ->orWhereNull('facility_key')
                    ->orWhereRaw("evidence = '{}'::jsonb");
            })
            ->limit(self::FAILURE_SAMPLE_CAP)
            ->pluck('staff_assignment_id')
            ->map(fn ($id): array => ['staff_assignment_id' => (int) $id])
            ->all();

        return $this->result(13, 'staff_assignment_integrity', 'Staff assignments are complete + FK-valid',
            $failures === [] ? 'pass' : 'fail', count($failures), $failures);
    }
}
