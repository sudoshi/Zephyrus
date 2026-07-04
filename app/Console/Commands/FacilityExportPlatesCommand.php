<?php

namespace App\Console\Commands;

use App\Services\Flow\FloorPlateAssetService;
use Illuminate\Console\Command;

/**
 * Precompute the simplified 2D floor-plate asset for the mobile Flow map
 * (FLOW-WINDOW-PLAN §6.1 W1). Run after `facility:import-catalog` or any
 * hosp_space geometry change; the endpoint self-heals if the asset is
 * missing, so this is an optimization + verification step, not a gate.
 */
class FacilityExportPlatesCommand extends Command
{
    protected $signature = 'facility:export-plates {--check : also report staffed beds with no mapped facility space}';

    protected $description = 'Export simplified per-floor 2D plate polygons for the mobile Flow map.';

    public function handle(FloorPlateAssetService $plates): int
    {
        $document = $plates->write();

        $floorCount = count($document['floors']);
        $shapeTotal = array_sum(array_column($document['floors'], 'shape_count'));
        $bytes = strlen(json_encode($document, JSON_UNESCAPED_SLASHES));
        $gzBytes = strlen(gzencode(json_encode($document, JSON_UNESCAPED_SLASHES), 9));

        $this->info("Exported {$document['version']}: {$floorCount} floors, {$shapeTotal} shapes, ".
            number_format($bytes / 1024, 1).' KB raw / '.number_format($gzBytes / 1024, 1).' KB gzipped → storage/app/'.FloorPlateAssetService::ASSET_PATH);

        foreach ($document['floors'] as $floor) {
            if ($floor['shape_count'] > 500) {
                $this->warn("Floor {$floor['floor']} has {$floor['shape_count']} shapes (budget is ≤500 — consider simplification).");
            }
        }

        if ($this->option('check')) {
            $unmapped = $plates->unmappedBeds();
            if ($unmapped === []) {
                $this->info('Plausibility: every staffed bed maps to a facility space.');
            } else {
                $this->warn('Plausibility: '.count($unmapped).' staffed bed(s) have no facility_space_id (they will not render on plates):');
                foreach (array_slice($unmapped, 0, 20) as $bed) {
                    $this->line("  bed_id={$bed['bed_id']} unit_id={$bed['unit_id']} label={$bed['label']}");
                }
                if (count($unmapped) > 20) {
                    $this->line('  … and '.(count($unmapped) - 20).' more');
                }
            }
        }

        return self::SUCCESS;
    }
}
