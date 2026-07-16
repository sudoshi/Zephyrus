<?php

namespace App\Console\Commands;

use App\Security\ClinicalPayloads\ClinicalPayloadLifecycleService;
use Illuminate\Console\Command;

final class ClinicalPayloadVerifyCommand extends Command
{
    protected $signature = 'clinical-payloads:verify
        {--source= : Optional exact integration source ID}
        {--limit= : Maximum objects to decrypt and verify}';

    protected $description = 'Run a bounded cryptographic restore sample without emitting clinical content';

    public function handle(ClinicalPayloadLifecycleService $lifecycle): int
    {
        $source = trim((string) $this->option('source'));
        $limit = trim((string) $this->option('limit'));
        $result = $lifecycle->sampleIntegrity(
            $source === '' ? null : (int) $source,
            $limit === '' ? null : (int) $limit,
        );

        $this->info(sprintf(
            'Integrity sample: %d sampled, %d verified, %d failed.',
            $result['sampled'],
            $result['verified'],
            $result['failed'],
        ));

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
