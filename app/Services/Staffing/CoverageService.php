<?php

namespace App\Services\Staffing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7: reports staffed vs unstaffed units per service line for a facility
 * (staff_assignments x facility_spaces x prod.units). Feeds parent Acceptance
 * Criterion 12 (DeploymentReadinessService::checkUnitStaffing) and the wizard's
 * post-commit Coverage Dashboard.
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§6.1, §14)
 */
class CoverageService
{
    /**
     * Full coverage report for a facility.
     *
     * @return array{facility_key:string, summary:array<string,int>, service_lines:list<array<string,mixed>>, units:list<array<string,mixed>>}
     */
    public function report(string $facilityKey): array
    {
        $units = $this->units($facilityKey);

        $byServiceLine = [];
        foreach ($units as $unit) {
            $code = $unit['service_line_code'] ?? 'unassigned';
            $byServiceLine[$code] ??= ['service_line_code' => $code, 'units_total' => 0, 'units_staffed' => 0, 'assignments' => 0];
            $byServiceLine[$code]['units_total']++;
            $byServiceLine[$code]['assignments'] += $unit['assignment_count'];
            if ($unit['staffed']) {
                $byServiceLine[$code]['units_staffed']++;
            }
        }

        $staffed = count(array_filter($units, fn (array $u): bool => $u['staffed']));

        return [
            'facility_key' => $facilityKey,
            'summary' => [
                'units_total' => count($units),
                'units_staffed' => $staffed,
                'units_unstaffed' => count($units) - $staffed,
            ],
            'service_lines' => array_values($byServiceLine),
            'units' => $units,
        ];
    }

    /**
     * Active units in the facility with no active staff assignment.
     *
     * @return list<array<string, mixed>>
     */
    public function unstaffedUnits(string $facilityKey): array
    {
        return array_values(array_filter($this->units($facilityKey), fn (array $u): bool => ! $u['staffed']));
    }

    /**
     * Active units for the facility, each with its assignment count + staffed flag.
     *
     * @return list<array<string, mixed>>
     */
    private function units(string $facilityKey): array
    {
        if (! Schema::hasTable('hosp_space.facility_spaces') || ! Schema::hasTable('hosp_org.staff_assignments')) {
            return [];
        }

        $rows = DB::table('prod.units as u')
            ->join('hosp_space.facility_spaces as fs', 'fs.facility_space_id', '=', 'u.facility_space_id')
            ->leftJoin('hosp_org.staff_assignments as sa', function ($join): void {
                $join->on('sa.unit_id', '=', 'u.unit_id')->where('sa.is_active', true);
            })
            ->where('fs.facility_key', $facilityKey)
            ->where('u.is_deleted', false)
            ->groupBy('u.unit_id', 'u.abbreviation', 'u.name', 'fs.service_line_code')
            ->select([
                'u.unit_id',
                'u.abbreviation',
                'u.name',
                'fs.service_line_code',
                DB::raw('count(sa.staff_assignment_id) as assignment_count'),
            ])
            ->orderBy('u.abbreviation')
            ->get();

        return $rows->map(fn ($row): array => [
            'unit_id' => (int) $row->unit_id,
            'abbreviation' => $row->abbreviation,
            'name' => $row->name,
            'service_line_code' => $row->service_line_code,
            'assignment_count' => (int) $row->assignment_count,
            'staffed' => (int) $row->assignment_count > 0,
        ])->all();
    }
}
