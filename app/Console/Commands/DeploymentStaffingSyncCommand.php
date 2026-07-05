<?php

namespace App\Console\Commands;

use App\Models\Org\StaffingSource;
use App\Services\Staffing\StaffImportOrchestrator;
use App\Services\Staffing\StaffingConnectorFactory;
use App\Services\Staffing\Support\PullWindow;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 7: stage -> resolve -> bucket a staffing source (dry-run by default; commit
 * the auto-approved bucket with --commit). Usable for scheduled headless runs.
 *
 *   deployment:staffing-sync CSV_UPLOAD --facility=SUMMIT_REGIONAL --file=roster.csv
 *   deployment:staffing-sync EPIC_FHIR  --facility=SUMMIT_REGIONAL --fhir=bundle.json --commit
 *
 * Plan: docs/superpowers/plans/2026-07-04-staffing-alignment-wizard-implementation.md (§6 task 8)
 */
class DeploymentStaffingSyncCommand extends Command
{
    protected $signature = 'deployment:staffing-sync
        {source : staffing_sources.source_key}
        {--facility= : target facility_key (defaults to the source or config default)}
        {--file= : path to a CSV roster (file_upload transport)}
        {--fhir= : path to a FHIR Bundle JSON (fhir_practitioner transport)}
        {--since= : ISO date for an incremental pull}
        {--commit : write the auto-approved bucket + provision linked accounts}';

    protected $description = 'Import a staffing source into the operational assignment graph (dry-run unless --commit).';

    public function handle(StaffingConnectorFactory $factory, StaffImportOrchestrator $orchestrator): int
    {
        $source = StaffingSource::where('source_key', $this->argument('source'))->first();
        if ($source === null) {
            $this->error("Unknown staffing source '{$this->argument('source')}'.");

            return self::FAILURE;
        }

        $facilityKey = $this->option('facility')
            ?? ($source->metadata['default_facility_key'] ?? null)
            ?? config('hospital.default_facility', 'SUMMIT_REGIONAL');

        try {
            $connector = $factory->make($source, [
                'file' => $this->option('file'),
                'fhir' => $this->option('fhir'),
            ]);

            $probe = $connector->testConnection();
            if (! $probe->ok) {
                $this->error("Connection failed: {$probe->message}");

                return self::FAILURE;
            }

            $window = $this->option('since')
                ? PullWindow::since(new DateTimeImmutable($this->option('since')))
                : PullWindow::full();

            $result = $orchestrator->run($source, $connector, $facilityKey, $window, ['dry_run' => ! $this->option('commit')]);
        } catch (Throwable $e) {
            $this->error('Staffing sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $counts = $result->counts();
        $this->info("Staged {$counts['total']} staff for {$facilityKey} (source {$source->source_key}, run #{$result->run->staff_import_run_id}).");
        $this->table(
            ['bucket', 'count'],
            collect($result::BUCKETS)->map(fn (string $b): array => [$b, (string) $counts[$b]])->all(),
        );

        if ($this->option('commit')) {
            $summary = $orchestrator->commit($result, $facilityKey);
            $source->update(['last_synced_at' => now()]);
            $this->info(sprintf(
                'Committed: %d members, %d assignments, %d accounts provisioned.',
                $summary['members'], $summary['assignments'], $summary['provisioned'],
            ));
        } else {
            $this->line('Dry run — no assignments written. Re-run with --commit to apply the auto-approved bucket.');
        }

        return self::SUCCESS;
    }
}
