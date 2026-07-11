<?php

namespace App\Console\Commands;

use App\Services\Demo\OperationalDemoDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DemoRollForwardCommand extends Command
{
    protected $signature = 'zephyrus:demo-roll-forward
        {--facility=SUMMIT_REGIONAL : Synthetic facility key}
        {--anchor= : Scenario anchor time in the facility timezone}
        {--dry-run : Report counts and collisions without writing}
        {--commit : Replace only rows owned by the configured synthetic scenario}';

    protected $description = 'Safely roll the isolated Summit Workforce, Staffing, and Transport scenario to a current anchor time.';

    public function handle(OperationalDemoDataService $demo): int
    {
        if (! config('demo_data.enabled')) {
            $this->components->error('Demo data is disabled. Set DEMO_DATA_ENABLED=true only for an approved synthetic environment.');

            return self::FAILURE;
        }

        $facility = (string) $this->option('facility');
        if (! in_array($facility, config('demo_data.facility_allowlist', []), true)) {
            $this->components->error("Facility {$facility} is not in DEMO_DATA_FACILITY_ALLOWLIST.");

            return self::FAILURE;
        }

        if ($facility !== (string) config('hospital.default_facility')) {
            $this->components->error('The resolved HospitalManifest does not match the requested facility.');

            return self::FAILURE;
        }

        if (! $this->option('dry-run') && ! $this->option('commit')) {
            $this->components->error('Choose --dry-run or --commit explicitly.');

            return self::FAILURE;
        }
        if ($this->option('dry-run') && $this->option('commit')) {
            $this->components->error('--dry-run and --commit are mutually exclusive.');

            return self::FAILURE;
        }

        foreach (['prod.units', 'prod.staffing_plans', 'prod.staffing_requests', 'prod.transport_requests', 'prod.transport_events', 'hosp_ref.service_lines', 'hosp_ref.staff_roles', 'hosp_org.staff_members', 'hosp_org.staff_assignments'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->components->error("Required table {$table} is missing; apply the reviewed migration chain first.");

                return self::FAILURE;
            }
        }
        if (DB::table('hosp_ref.service_lines')->doesntExist() || DB::table('hosp_ref.staff_roles')->doesntExist()) {
            $this->components->error('Canonical staffing references are empty. Run deployment:seed-registry and deployment:seed-staff-roles first.');

            return self::FAILURE;
        }

        try {
            $anchor = $this->option('anchor') ? Carbon::parse((string) $this->option('anchor')) : now();
            $preview = $demo->preview($anchor);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], collect($preview)
            ->except('collisions')
            ->map(fn (mixed $value, string $key): array => [$key, (string) $value])
            ->values()
            ->all());

        if ($preview['collisions'] !== []) {
            $this->components->error('Non-scenario staffing slots already exist at this anchor; no writes are allowed.');
            foreach ($preview['collisions'] as $collision) {
                $this->line(' - '.$collision['slot']);
            }

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->components->info('Dry run complete. No rows were changed.');

            return self::SUCCESS;
        }

        try {
            $result = $demo->rollForward($anchor);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        Log::info('Synthetic operations demo rolled forward', $result + [
            'facility' => $facility,
            'command' => $this->getName(),
        ]);
        $this->components->info(sprintf(
            'Scenario %s committed: %d staffing plans, %d active transports, %d historical transports.',
            $result['scenario_id'],
            $result['staffing_plans'],
            $result['transport_active'],
            $result['transport_history'],
        ));

        return self::SUCCESS;
    }
}
