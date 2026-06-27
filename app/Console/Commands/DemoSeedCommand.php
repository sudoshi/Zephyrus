<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * One-command demo provisioning.
 *
 * `php artisan db:seed` alone cannot light up every page: the Patient Flow 4D
 * Navigator (flow_core.*), Facility Model (hosp_space.*) and the ED Flow viewer
 * are populated only by standalone import commands that read git-tracked fixture
 * data. This orchestrator chains seeding + those imports so a fresh database
 * becomes fully demonstrable from a single command, and is safe to re-run
 * (db:seed is idempotent and both importers upsert).
 */
class DemoSeedCommand extends Command
{
    protected $signature = 'zephyrus:demo-seed
        {--migrate : Run database migrations (--force) before seeding}
        {--skip-imports : Seed only; skip the facility catalog + patient-flow imports}';

    protected $description = 'Provision a complete Zephyrus demo: db:seed + facility catalog + synthetic patient-flow import.';

    private const FACILITY_CODE = 'ZEPHYRUS-500';

    private const FACILITY_NAME = '500-Bed Level I Trauma Academic Medical Center';

    private const CATALOG_PATH = 'patient-flow-4d-navigator/hospital-cad-model/data/model_catalog.json';

    private const FLOW_NDJSON = 'patient-flow-4d-navigator/data/hl7_messages.ndjson';

    public function handle(): int
    {
        if ($this->option('migrate')) {
            $this->components->task('Migrating database', fn () => Artisan::call('migrate', ['--force' => true]) === 0);
        }

        $this->components->task('Seeding demo database (db:seed)', fn () => Artisan::call('db:seed', ['--force' => true]) === 0);

        if ($this->option('skip-imports')) {
            $this->components->info('Imports skipped (--skip-imports).');

            return self::SUCCESS;
        }

        $catalog = base_path(self::CATALOG_PATH);
        $flow = base_path(self::FLOW_NDJSON);

        if (! is_file($catalog)) {
            $this->components->warn("Facility catalog not found at {$catalog}; skipping facility + patient-flow imports.");

            return self::SUCCESS;
        }

        // Facility catalog first: it creates the facility_spaces (+ operational
        // unit/bed maps) that the patient-flow importer looks up by facility code.
        $this->components->task('Importing facility blueprint catalog', fn () => Artisan::call('facility:import-catalog', [
            'path' => $catalog,
            '--facility-code' => self::FACILITY_CODE,
            '--facility-name' => self::FACILITY_NAME,
            '--source-name' => 'patient-flow-4d-navigator-catalog',
            '--map-operational' => true,
        ]) === 0);

        if (is_file($flow)) {
            $this->components->task('Importing synthetic patient-flow events', fn () => Artisan::call('patient-flow:import-synthetic', [
                'path' => $flow,
                '--source-key' => 'synthetic-flow-ehr',
                '--facility-code' => self::FACILITY_CODE,
            ]) === 0);
        } else {
            $this->components->warn("Patient-flow NDJSON not found at {$flow}; skipping flow import.");
        }

        $this->newLine();
        $this->components->info('Zephyrus demo data ready — Patient Flow Navigator, Facility Model and ED Flow should now render.');

        return self::SUCCESS;
    }
}
