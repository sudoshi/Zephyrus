<?php

namespace App\Console\Commands;

use App\Domain\Ocel\OcelJsonExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Zephyrus 2.0 — Part X (X0). Exports the relational ocel.* store to canonical
 * OCEL 2.0 JSON (the format pm4py / ocpa ingest). `--validate` runs the
 * structural + referential-integrity check that X0's acceptance criterion
 * requires ("a validated OCEL-JSON export that pm4py ingests without error").
 */
class OcelExportCommand extends Command
{
    protected $signature = 'ocel:export
        {--out= : output path; defaults to storage/app/ocel/ocel-export.json}
        {--validate : run OCEL 2.0 structural + referential validation, non-zero exit on problems}';

    protected $description = 'Export ocel.* to OCEL 2.0 JSON for pm4py / ocpa interchange.';

    public function handle(OcelJsonExporter $exporter): int
    {
        $doc = $exporter->export();

        $this->table(['section', 'count'], [
            ['objectTypes', count($doc['objectTypes'])],
            ['eventTypes', count($doc['eventTypes'])],
            ['objects', count($doc['objects'])],
            ['events', count($doc['events'])],
        ]);

        if ($this->option('validate')) {
            $problems = $exporter->validate($doc);
            if ($problems !== []) {
                $this->error(count($problems).' OCEL 2.0 validation problem(s):');
                foreach (array_slice($problems, 0, 25) as $p) {
                    $this->line("  • {$p}");
                }

                return self::FAILURE;
            }
            $this->info('OCEL 2.0 export is structurally valid (types declared, all E2O/O2O references resolve).');
        }

        $out = $this->option('out') ?: storage_path('app/ocel/ocel-export.json');
        File::ensureDirectoryExists(dirname($out));
        File::put($out, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info('Wrote OCEL 2.0 JSON → '.$out.' ('.number_format(filesize($out) / 1024, 1).' KB)');

        return self::SUCCESS;
    }
}
