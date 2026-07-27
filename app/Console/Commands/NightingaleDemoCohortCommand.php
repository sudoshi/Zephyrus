<?php

namespace App\Console\Commands;

use App\Nightingale\Demo\NightingaleDemoCohortProvisioner;
use Illuminate\Console\Command;
use Throwable;

class NightingaleDemoCohortCommand extends Command
{
    protected $signature = 'nightingale:demo-cohort
        {action=preview : preview, apply, verify, or suspend}
        {--unit-id= : Existing non-deleted prod.units unit_id; required for preview/apply}
        {--confirm= : Exact confirmation phrase for apply/suspend}
        {--json : Emit a machine-readable, credential-free result}';

    protected $description = 'Preview, provision, verify, or suspend the five synthetic Nightingale investor-demo accounts';

    public function handle(NightingaleDemoCohortProvisioner $provisioner): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['preview', 'apply', 'verify', 'suspend'], true)) {
            $this->error('Action must be preview, apply, verify, or suspend.');

            return self::INVALID;
        }

        $unitId = null;
        if (in_array($action, ['preview', 'apply'], true)) {
            $unitId = filter_var(
                $this->option('unit-id'),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            if ($unitId === false) {
                $this->error('A positive --unit-id is required.');

                return self::INVALID;
            }
        }

        if ($action === 'apply'
            && ! hash_equals('apply-five-synthetic-nightingale-demo-accounts', (string) $this->option('confirm'))) {
            $this->error('Apply requires the exact cohort confirmation phrase.');

            return self::INVALID;
        }
        if ($action === 'suspend'
            && ! hash_equals('suspend-five-synthetic-nightingale-demo-accounts', (string) $this->option('confirm'))) {
            $this->error('Suspend requires the exact cohort confirmation phrase.');

            return self::INVALID;
        }

        try {
            $result = match ($action) {
                'preview' => $provisioner->preview($unitId),
                'apply' => $provisioner->apply(
                    $unitId,
                    (string) $this->secret('Nightingale demo password (input is hidden)'),
                ),
                'verify' => $provisioner->verify(),
                'suspend' => $provisioner->suspend(),
            };
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            $this->error(str_starts_with($message, 'nightingale_demo_')
                ? $message
                : 'nightingale_demo_cohort_operation_failed');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        }

        $this->table([
            'Field',
            'Value',
        ], collect($result)->map(function (mixed $value, string $key): array {
            if (is_array($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            return [$key, $value ?? 'null'];
        })->values()->all());

        return self::SUCCESS;
    }
}
