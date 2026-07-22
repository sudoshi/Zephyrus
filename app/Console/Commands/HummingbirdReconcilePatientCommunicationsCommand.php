<?php

namespace App\Console\Commands;

use App\Services\Patient\Messaging\PatientCommunicationLifecycleReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class HummingbirdReconcilePatientCommunicationsCommand extends Command
{
    protected $signature = 'hummingbird:reconcile-patient-communications
        {--once : Process one bounded batch and exit}
        {--limit=100 : Maximum open work items in this batch}';

    protected $description = 'Reconcile open patient communications with canonical encounter lifecycle changes';

    public function handle(PatientCommunicationLifecycleReconciliationService $reconciler): int
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
            $result = $reconciler->reconcileOpen((int) $limit);
        } catch (Throwable $exception) {
            Log::error('hummingbird.patient_communication_reconciliation_failed', [
                'exception' => $exception::class,
            ]);
            $this->components->error('patient_communication_reconciliation_failed');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Patient-communication reconciliation complete: selected=%d rerouted=%d released=%d closed=%d skipped=%d unresolved=%d failed=%d.',
            $result['selected'],
            $result['rerouted'],
            $result['released'],
            $result['closed'],
            $result['skipped'],
            $result['unresolved'],
            $result['failed'],
        ));

        return $result['failed'] === 0 && $result['unresolved'] === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
