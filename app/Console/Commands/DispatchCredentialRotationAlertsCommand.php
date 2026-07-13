<?php

namespace App\Console\Commands;

use App\Integrations\Healthcare\Services\CredentialRotationAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * INT-SECRET — page on credential-rotation threshold crossings through the
 * shared operational-alert lifecycle. Runs on a slow cadence; the per-band
 * dedupe ledger keeps it to one page per crossing regardless of frequency.
 */
final class DispatchCredentialRotationAlertsCommand extends Command
{
    protected $signature = 'integrations:dispatch-credential-rotation-alerts
        {--limit=500 : Maximum active credentials to evaluate in this batch}';

    protected $description = 'Fire PHI-free credential-rotation threshold alerts through the shared on-call dispatcher';

    public function handle(CredentialRotationAlertService $alerts): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 2000) {
            $this->error('The credential-rotation alert limit is invalid.');

            return self::INVALID;
        }

        try {
            $result = $alerts->sweep(null, (string) Str::uuid(), $limit);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Credential-rotation alert dispatch failed; inspect PHI-safe structured diagnostics.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Evaluated %d credential(s): %d dispatched, %d inert, %d deduped.',
            $result['evaluated'],
            $result['dispatched'],
            $result['inert'],
            $result['deduped'],
        ));

        return self::SUCCESS;
    }
}
