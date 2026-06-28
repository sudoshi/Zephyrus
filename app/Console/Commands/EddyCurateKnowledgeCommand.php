<?php

namespace App\Console\Commands;

use App\Services\Eddy\EddyKnowledgeCurator;
use Illuminate\Console\Command;

/**
 * Auto-curate institutional doctrine into eddy_knowledge from operational signal
 * (Phase 6). Proposed rows land status='proposed' for super-admin review — they do
 * NOT surface in RAG until approved. Safe to run on a schedule.
 */
class EddyCurateKnowledgeCommand extends Command
{
    protected $signature = 'eddy:curate-knowledge {--min-occurrences=3 : minimum pattern repeats before proposing}';

    protected $description = 'Propose institutional-knowledge entries from recurring operational patterns (Phase 6).';

    public function handle(EddyKnowledgeCurator $curator): int
    {
        $min = max(1, (int) $this->option('min-occurrences'));

        $proposed = $curator->curateFromResolvedBarriers($min);

        if ($proposed === []) {
            $this->info('No new knowledge to propose (nothing met the threshold, or all patterns already proposed).');

            return self::SUCCESS;
        }

        $this->info('Proposed '.count($proposed).' knowledge entries for review:');
        foreach ($proposed as $row) {
            $this->line("  • [{$row->status}] {$row->title}");
        }
        $this->newLine();
        $this->comment('Review them as a super-admin (POST /api/eddy/admin/knowledge/{uuid}/review) before they surface in RAG.');

        return self::SUCCESS;
    }
}
