<?php

namespace App\Console\Commands;

use App\Services\Patient\Messaging\DatabasePatientMessageHandoffConsumer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class HummingbirdConsumePatientMessageHandoffCommand extends Command
{
    protected $signature = 'hummingbird:consume-patient-message-handoff
        {--once : Process one bounded batch and exit}
        {--limit= : Maximum outbox records in this batch}
        {--worker= : Opaque worker label; never include hostnames or credentials}';

    protected $description = 'Consume content-free Hummingbird Patient message handoffs into the accountable staff inbox';

    public function handle(DatabasePatientMessageHandoffConsumer $consumer): int
    {
        if (! $this->option('once')) {
            $this->components->error('Only bounded --once execution is supported; run it under the approved scheduler/supervisor.');

            return self::INVALID;
        }

        $worker = trim((string) ($this->option('worker') ?: 'scheduled-worker'));
        if ($worker === '' || mb_strlen($worker) > 120 || ! Str::isAscii($worker)) {
            $this->components->error('The worker label must be a non-empty ASCII value of at most 120 characters.');

            return self::INVALID;
        }

        $limit = $this->option('limit');
        if ($limit !== null && (! ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 500)) {
            $this->components->error('The batch limit must be an integer from 1 through 500.');

            return self::INVALID;
        }

        try {
            $result = $consumer->consumeBatch($worker, $limit === null ? null : (int) $limit);
        } catch (Throwable $exception) {
            Log::error('hummingbird.patient_message_handoff_failed', [
                'exception' => $exception::class,
            ]);
            $this->components->error('patient_message_handoff_failed');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Patient-message handoff batch complete: selected=%d delivered=%d failed=%d.',
            $result['selected'],
            $result['delivered'],
            $result['failed'],
        ));

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
