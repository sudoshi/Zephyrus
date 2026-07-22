<?php

namespace App\Console\Commands;

use App\Services\Patient\Messaging\PatientCommunicationEscalationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class HummingbirdEscalatePatientCommunicationsCommand extends Command
{
    protected $signature = 'hummingbird:escalate-patient-communications
        {--once : Process one bounded batch and exit}
        {--limit=100 : Maximum work items in this batch}';

    protected $description = 'Escalate unanswered patient communications after their governed response target';

    public function handle(PatientCommunicationEscalationService $escalations): int
    {
        if (! $this->option('once')) {
            $this->components->error('Only bounded --once execution is supported; run it under the approved scheduler.');

            return self::INVALID;
        }

        $limit = $this->option('limit');
        if (! ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 500) {
            $this->components->error('The batch limit must be an integer from 1 through 500.');

            return self::INVALID;
        }

        try {
            $result = $escalations->escalateDue((int) $limit);
        } catch (Throwable $exception) {
            Log::error('hummingbird.patient_communication_escalation_failed', [
                'exception' => $exception::class,
            ]);
            $this->components->error('patient_communication_escalation_failed');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Patient-communication escalation complete: selected=%d escalated=%d skipped=%d failed=%d.',
            $result['selected'],
            $result['escalated'],
            $result['skipped'],
            $result['failed'],
        ));

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
