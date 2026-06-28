<?php

namespace App\Services\Eddy;

use App\Models\Eddy\EddyKnowledge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Auto-curates institutional doctrine into eddy_knowledge from operational signal,
 * behind a human-review gate. Curated rows land status='proposed' and only surface
 * in RAG once a super-admin approves them ({@see EddyKnowledgeService} filters to
 * status='approved').
 *
 * PHI discipline: knowledge is derived from AGGREGATE patterns (barrier
 * category/reason_code counts), never from individual encounters or free-text that
 * could carry patient detail. Every curated row is is_phi_free=true by construction.
 */
class EddyKnowledgeCurator
{
    /**
     * Propose playbook entries from recurring RESOLVED RTDC barriers. Only patterns
     * seen >= $minOccurrences times are curated (the "extract on 3+ repeats" rule);
     * duplicates (same origin+category+reason_code) are skipped.
     *
     * @return array<int, EddyKnowledge> the rows proposed this run
     */
    public function curateFromResolvedBarriers(int $minOccurrences = 3): array
    {
        if (! Schema::hasTable('prod.barriers') || ! Schema::hasColumn('eddy.eddy_knowledge', 'status')) {
            return [];
        }

        $patterns = DB::table('prod.barriers')
            ->where('is_deleted', false)
            ->whereNotNull('resolved_at')
            ->selectRaw('category, reason_code, count(*) as n, '
                .'avg(EXTRACT(EPOCH FROM (resolved_at - opened_at)) / 3600.0) as avg_hours')
            ->groupBy('category', 'reason_code')
            ->havingRaw('count(*) >= ?', [$minOccurrences])
            ->get();

        $created = [];

        foreach ($patterns as $pattern) {
            $category = trim((string) $pattern->category);
            $reason = trim((string) $pattern->reason_code);
            if ($category === '' && $reason === '') {
                continue;   // nothing to key the doctrine on
            }

            if ($this->alreadyProposed($category, $reason)) {
                continue;
            }

            $created[] = $this->propose($category, $reason, (int) $pattern->n, $pattern->avg_hours !== null ? (float) $pattern->avg_hours : null);
        }

        return $created;
    }

    private function alreadyProposed(string $category, string $reason): bool
    {
        return EddyKnowledge::query()
            ->where('curated_from->origin', 'resolved_barriers')
            ->where('curated_from->category', $category)
            ->where('curated_from->reason_code', $reason)
            ->exists();
    }

    private function propose(string $category, string $reason, int $count, ?float $avgHours): EddyKnowledge
    {
        $label = trim($category.($reason !== '' ? " / {$reason}" : ''), ' /');
        $timing = $avgHours !== null ? sprintf(' with a typical resolution time of ~%.1fh', $avgHours) : '';

        return EddyKnowledge::create([
            'eddy_knowledge_uuid' => (string) Str::uuid(),
            'surface' => 'rtdc',
            'category' => 'playbook',
            'title' => "Barrier playbook: {$label}",
            'body' => "This barrier pattern has been resolved {$count} times historically{$timing}. "
                .'Treat it as a recurring, addressable barrier; prioritize the established resolution path and escalate early when it recurs.',
            'tags' => array_values(array_filter([$category, $reason, 'auto-curated'])),
            'source' => 'auto:resolved-barriers',
            'is_phi_free' => true,
            'is_active' => true,
            'status' => 'proposed',        // human-review gate — not RAG-eligible until approved
            'curated_from' => [
                'origin' => 'resolved_barriers',
                'category' => $category,
                'reason_code' => $reason,
                'sample_size' => $count,
            ],
        ]);
    }
}
