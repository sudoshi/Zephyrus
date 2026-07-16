<?php

namespace App\Console\Commands;

use App\Security\ClinicalPayloads\ClinicalPayloadLifecycleService;
use Illuminate\Console\Command;

final class ClinicalPayloadLifecycleCommand extends Command
{
    protected $signature = 'clinical-payloads:lifecycle
        {--source= : Optional exact integration source ID}
        {--limit=100 : Maximum expired objects to inspect}
        {--execute : Apply retention transitions and dependency-safe deletion}';

    protected $description = 'Preview or enforce legal-hold- and replay-aware clinical payload retention';

    public function handle(ClinicalPayloadLifecycleService $lifecycle): int
    {
        $source = trim((string) $this->option('source'));
        $result = $lifecycle->enforce(
            $source === '' ? null : (int) $source,
            (int) $this->option('limit'),
            (bool) $this->option('execute'),
        );

        $this->info(sprintf(
            '%s retention: %d scanned, %d eligible, %d dependency-blocked, %d deleted, %d failed.',
            $this->option('execute') ? 'Executed' : 'Previewed',
            $result['scanned'],
            $result['eligible'],
            $result['blocked'],
            $result['deleted'],
            $result['failed'],
        ));

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
