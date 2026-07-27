<?php

namespace App\Console\Commands;

use App\Services\CarePathways\OcelActivityCoverageService;
use Illuminate\Console\Command;

class CarePathwaysOcelCoverageCommand extends Command
{
    protected $signature = 'care-pathways:ocel-coverage
        {--json : Emit the coverage report as JSON}';

    protected $description = 'Report which governed care-pathway milestones map to Arena OCEL activities — the honest gap list for clinical mapping review (FLOW-4D plan D2).';

    public function handle(OcelActivityCoverageService $service): int
    {
        $report = $service->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $totals = $report['totals'];

        if ($totals['executable_versions'] === 0) {
            $this->info('No governed version has a persisted executable milestone layer yet.');
            $this->line('The DRG catalog release is inactive by design; draft an executable layer with');
            $this->line('  php artisan care-pathways:draft-executable-layer');
            $this->line('then re-run this command to see the OCEL mapping gap.');

            return self::SUCCESS;
        }

        $this->table(
            ['Pathway', 'Version', 'Activities', 'Mapped', 'Unmapped', 'Coverage'],
            array_map(static fn (array $p): array => [
                $p['pathway_key'],
                substr((string) $p['version_uuid'], 0, 8),
                (string) $p['activities'],
                (string) $p['mapped'],
                (string) count($p['unmapped']),
                $p['coverage'] === null ? '—' : number_format((float) $p['coverage'] * 100, 1).'%',
            ], $report['pathways']),
        );

        $this->line(sprintf(
            'Coverage: %d of %d milestone activities map to an OCEL activity (%s).',
            $totals['mapped'],
            $totals['activities'],
            $totals['coverage'] === null ? 'n/a' : number_format((float) $totals['coverage'] * 100, 1).'%',
        ));

        foreach ($report['pathways'] as $pathway) {
            if ($pathway['unmapped'] !== []) {
                $this->warn(sprintf('%s — unmapped: %s', $pathway['pathway_key'], implode(', ', $pathway['unmapped'])));
            }
            if ($pathway['unknown_targets'] !== []) {
                $this->error(sprintf('%s — target not in OCEL vocabulary: %s', $pathway['pathway_key'], implode(', ', $pathway['unknown_targets'])));
            }
        }

        return self::SUCCESS;
    }
}
