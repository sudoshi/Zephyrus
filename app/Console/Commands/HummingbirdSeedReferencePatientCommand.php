<?php

namespace App\Console\Commands;

use App\Services\Mobile\Demo\HummingbirdReferencePatientProvisioner;
use Illuminate\Console\Command;
use Throwable;

class HummingbirdSeedReferencePatientCommand extends Command
{
    protected $signature = 'hummingbird:seed-reference-patient
        {--unit-id= : Existing non-deleted prod.units unit_id}
        {--patient-ref=demo-hummingbird-reference-inpatient : Lowercase demo-/sim- pseudonym}
        {--commit : Persist the reference encounter; omission is a dry run}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Preview or idempotently provision a synthetic inpatient for Hummingbird verification';

    public function handle(HummingbirdReferencePatientProvisioner $provisioner): int
    {
        $unitId = filter_var($this->option('unit-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($unitId === false) {
            $this->error('A positive --unit-id is required.');

            return self::INVALID;
        }

        try {
            $result = $this->option('commit')
                ? $provisioner->provision($unitId, (string) $this->option('patient-ref'))
                : $provisioner->preview($unitId, (string) $this->option('patient-ref'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], collect($result)
            ->map(fn (mixed $value, string $key): array => [$key, is_bool($value) ? ($value ? 'true' : 'false') : ($value ?? 'null')])
            ->values()
            ->all());
        $this->newLine();
        $this->info($result['committed'] ? 'Synthetic reference inpatient committed.' : 'Dry run only; pass --commit to persist.');

        return self::SUCCESS;
    }
}
