<?php

namespace App\Console\Commands;

use App\Services\CarePathways\CatalogImportService;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

class CarePathwaysAdoptReleaseCommand extends Command
{
    protected $signature = 'care-pathways:adopt-raw-release
        {releaseId=1 : Raw verification release identifier}
        {--actor= : Stable data-steward or service actor identifier}
        {--dry-run : Recompute and report every release control without writing}
        {--json : Emit the bounded summary as JSON}';

    protected $description = 'Reconcile and adopt a raw DRG care-pathway verification release into the inactive canonical catalog.';

    public function handle(CatalogImportService $importer): int
    {
        $releaseId = filter_var($this->argument('releaseId'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $actor = trim((string) $this->option('actor'));

        if ($releaseId === false) {
            $this->error('releaseId must be a positive integer.');

            return self::INVALID;
        }

        if ($actor === '') {
            $this->error('The --actor option is required; source adoption must have an accountable actor.');

            return self::INVALID;
        }

        try {
            $summary = $importer->adopt($releaseId, $actor, (bool) $this->option('dry-run'));
        } catch (JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info($summary['dry_run'] ? 'Care-pathway release controls passed (dry run).' : 'Care-pathway release adopted inactive.');
        $this->line('Catalog release ID: '.$summary['catalog_release_id']);
        $this->line('Dataset key: '.$summary['dataset_key']);
        $this->line('State: '.$summary['state']);
        $this->line('Pathways: '.$summary['pathways']);
        $this->line('MS-DRG codebook entries: '.$summary['drg_codebook_entries']);
        $this->line('Pathway-to-DRG associations: '.$summary['drg_mappings']);
        $this->line('Evidence claims: '.$summary['claims']);
        $this->line('Sources: '.$summary['sources']);
        $this->line('Source changes: '.$summary['changes']);
        $this->line('Clinical signoff complete: no');

        if ($summary['reused']) {
            $this->warn('Exact immutable release already existed; no duplicate rows were written.');
        }

        return self::SUCCESS;
    }
}
