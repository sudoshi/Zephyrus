<?php

namespace App\Console\Commands;

use App\Services\PatientFlow\SyntheticFlowImporter;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

class PatientFlowImportSyntheticCommand extends Command
{
    protected $signature = 'patient-flow:import-synthetic
        {path : Path to hl7_messages.ndjson or normalized_events.ndjson}
        {--source-key=synthetic-flow-ehr : Integration source key}
        {--facility-code=ZEPHYRUS-500 : Facility code used for facility-space lookup}
        {--from-normalized : Import normalized event NDJSON instead of raw HL7 message NDJSON}
        {--dry-run : Parse and report without writing}';

    protected $description = 'Import synthetic Patient Flow 4D Navigator events into Zephyrus.';

    public function handle(SyntheticFlowImporter $importer): int
    {
        try {
            $summary = $importer->import((string) $this->argument('path'), [
                'source_key' => (string) $this->option('source-key'),
                'facility_code' => (string) $this->option('facility-code'),
                'from_normalized' => (bool) $this->option('from-normalized'),
                'dry_run' => (bool) $this->option('dry-run'),
            ]);
        } catch (JsonException $exception) {
            $this->error('Synthetic flow NDJSON is invalid: '.$exception->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Patient flow synthetic import complete.');
        foreach ($summary as $key => $value) {
            $display = is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? '');
            $this->line(str_replace('_', ' ', ucfirst($key)).': '.$display);
        }

        return self::SUCCESS;
    }
}
