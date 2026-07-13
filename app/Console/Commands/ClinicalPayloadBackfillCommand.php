<?php

namespace App\Console\Commands;

use App\Security\ClinicalPayloads\ClinicalPayloadBackfillService;
use App\Security\ClinicalPayloads\ClinicalPayloadException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class ClinicalPayloadBackfillCommand extends Command
{
    protected $signature = 'clinical-payloads:backfill
        {--mode=inventory : inventory or backfill}
        {--source= : Optional exact integration source ID}
        {--limit=100 : Maximum legacy columns to inspect}
        {--from= : Optional inclusive ISO-8601 lower timestamp}
        {--to= : Optional inclusive ISO-8601 upper timestamp}';

    protected $description = 'Inventory or protect legacy clinical JSONB payloads with resumable append-only evidence';

    public function handle(ClinicalPayloadBackfillService $backfill): int
    {
        try {
            $result = $backfill->run(
                (string) $this->option('mode'),
                $this->optionalInt('source'),
                (int) $this->option('limit'),
                $this->optionalTime('from'),
                $this->optionalTime('to'),
            );
        } catch (Throwable $exception) {
            $code = $exception instanceof ClinicalPayloadException
                ? $exception->errorCode
                : 'clinical_payload_backfill_failed';
            $this->error("Clinical payload backfill failed ({$code}).");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Run %d %s: %d scanned, %d protected, %d skipped, %d failed, %d mismatched.',
            $result['runId'],
            $result['status'],
            $result['scanned'],
            $result['protected'],
            $result['skipped'],
            $result['failed'],
            $result['mismatch'],
        ));

        return $result['status'] === 'completed' ? self::SUCCESS : self::FAILURE;
    }

    private function optionalInt(string $option): ?int
    {
        $value = trim((string) $this->option($option));

        return $value === '' ? null : (int) $value;
    }

    private function optionalTime(string $option): ?CarbonImmutable
    {
        $value = trim((string) $this->option($option));

        return $value === '' ? null : CarbonImmutable::parse($value);
    }
}
