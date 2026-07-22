<?php

namespace App\Console\Commands;

use App\Services\Patient\Projection\PatientPathwayHistoryDraftService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class HummingbirdDraftPatientPathwayHistoryCommand extends Command
{
    protected $signature = 'hummingbird:draft-patient-pathway-history
        {--once : Process one bounded batch and exit}
        {--limit=100 : Maximum pathway instances in this batch}
        {--commit : Persist governed drafts; without this option, report a dry-run count only}';

    protected $description = 'Build draft-only patient My Path projections from approved, version-pinned pathway history';

    public function handle(PatientPathwayHistoryDraftService $drafts): int
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
            if (! $this->option('commit')) {
                $preview = $drafts->previewPending((int) $limit);
                $this->components->info(sprintf(
                    'Patient pathway-history draft dry run: selected=%d drafted=0 replayed=0 failed=0.',
                    $preview['selected'],
                ));

                return self::SUCCESS;
            }

            $result = $drafts->draftPending((int) $limit);
        } catch (Throwable $exception) {
            Log::error('hummingbird.patient_pathway_history_draft_failed', [
                'exception' => $exception::class,
            ]);
            $this->components->error('patient_pathway_history_draft_failed');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Patient pathway-history draft batch complete: selected=%d drafted=%d replayed=%d failed=%d.',
            $result['selected'],
            $result['drafted'],
            $result['replayed'],
            $result['failed'],
        ));

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
