<?php

namespace App\Console\Commands;

use App\Models\Bed;
use App\Models\Facility\FacilitySpace;
use App\Models\Unit;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Console\Command;

/**
 * Backfill prod.units / prod.beds → hosp_space.facility_spaces links for the
 * MANIFEST roster (FLOW-WINDOW-PLAN §6.1 W1: "backfill any
 * prod.beds.facility_space_id gaps").
 *
 * Why this exists: `facility:import-catalog --map-operational` creates its
 * own units/beds keyed by CAD code (MS4A, MICU3, …) — a parallel roster next
 * to the RtdcSeeder manifest roster (4W, MICU, …). Re-running RtdcSeeder
 * soft-deletes the CAD-keyed units, but the manifest units/beds are left
 * with no facility_space_id, so nothing renders on the Flow floor plates.
 *
 * This command joins the two worlds through the manifest's cad_code:
 *   manifest unit abbr → cad_code → facility space (attributes.unit_code)
 * and assigns bed spaces to the unit's beds in stable label/code order.
 * It also soft-deletes the CAD-duplicate units AND their beds so counts,
 * rollups, and plates all describe one roster. Idempotent.
 */
class FacilityLinkOperationalCommand extends Command
{
    protected $signature = 'facility:link-operational {--dry-run : report without writing}';

    protected $description = 'Link the manifest unit/bed roster to facility spaces and retire CAD-duplicate units.';

    public function handle(HospitalManifest $manifest): int
    {
        $dry = (bool) $this->option('dry-run');
        $linkedUnits = $linkedBeds = 0;

        // Retire the CAD-duplicate roster FIRST (units whose abbreviation is
        // a cad_code, not a manifest abbr) and release their space claims so
        // the manifest beds can take them over.
        $manifestAbbrs = $manifest->unitAbbrs();
        $cadCodes = array_values(array_filter(array_column($manifest->units(), 'cad_code')));
        $duplicates = Unit::query()
            ->where('is_deleted', false)
            ->whereNotIn('abbreviation', $manifestAbbrs)
            ->whereIn('abbreviation', $cadCodes)
            ->get();

        $retiredBeds = 0;
        foreach ($duplicates as $duplicate) {
            $retiredBeds += Bed::where('unit_id', $duplicate->unit_id)->where('is_deleted', false)->count();
            if (! $dry) {
                Bed::where('unit_id', $duplicate->unit_id)
                    ->update(['is_deleted' => true, 'facility_space_id' => null]);
                $duplicate->update(['is_deleted' => true, 'facility_space_id' => null]);
            }
        }

        foreach ($manifest->units() as $entry) {
            $abbr = (string) ($entry['abbr'] ?? '');
            $cadCode = (string) ($entry['cad_code'] ?? '');
            if ($abbr === '' || $cadCode === '') {
                continue;
            }

            $unit = Unit::where('is_deleted', false)->where('abbreviation', $abbr)->first();
            if (! $unit) {
                continue;
            }

            $unitSpace = FacilitySpace::query()
                ->where('space_category', 'unit')
                ->where('attributes->unit_code', $cadCode)
                ->first();

            if ($unitSpace && $unit->facility_space_id === null) {
                $linkedUnits++;
                if (! $dry) {
                    $unit->update(['facility_space_id' => $unitSpace->facility_space_id]);
                }
            }

            // Bed spaces for this CAD unit, in stable code order; assign to
            // the unit's unmapped beds in stable label order.
            $bedSpaces = FacilitySpace::query()
                ->where('space_category', 'bed')
                ->where('attributes->unit_code', $cadCode)
                ->orderBy('space_code')
                ->pluck('facility_space_id')
                ->all();

            $claimed = Bed::query()
                ->where('is_deleted', false)
                ->whereIn('facility_space_id', $bedSpaces)
                ->pluck('facility_space_id')
                ->all();
            $free = array_values(array_diff($bedSpaces, $claimed));

            $beds = Bed::query()
                ->where('unit_id', $unit->unit_id)
                ->where('is_deleted', false)
                ->whereNull('facility_space_id')
                ->orderBy('label')
                ->get();

            foreach ($beds as $index => $bed) {
                if (! isset($free[$index])) {
                    break; // more staffed beds than modeled spaces — surfaced by export-plates --check
                }
                $linkedBeds++;
                if (! $dry) {
                    $bed->update(['facility_space_id' => $free[$index]]);
                }
            }
        }

        $mode = $dry ? '[dry-run] would link' : 'Linked';
        $this->info("{$mode} {$linkedUnits} units and {$linkedBeds} beds to facility spaces; retired "
            .$duplicates->count()." CAD-duplicate units ({$retiredBeds} beds).");
        $this->line('Verify with: php artisan facility:export-plates --check');

        return self::SUCCESS;
    }
}
