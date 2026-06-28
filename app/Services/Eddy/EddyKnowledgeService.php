<?php

namespace App\Services\Eddy;

use App\Models\Eddy\EddyKnowledge;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase-2 RAG over eddy_knowledge: surface-scoped (+ 'global') operational doctrine,
 * ranked by keyword overlap with the user's message. Deterministic keyword scoring
 * (no vector dependency); Phase 6 swaps in pgvector behind this same interface.
 * Only is_phi_free + active rows are eligible.
 */
class EddyKnowledgeService
{
    /**
     * @return array<int, array{title:string, category:string, body:string, source:?string}>
     */
    public function forSurface(string $surface, string $message, int $limit = 3): array
    {
        if (! Schema::hasTable('eddy.eddy_knowledge')) {
            return [];
        }

        $candidates = EddyKnowledge::query()
            ->active()
            ->phiFree()
            ->where(fn ($q) => $q->where('surface', $surface)->orWhere('surface', 'global'))
            ->orderByDesc('updated_at')
            ->limit(12)
            ->get(['title', 'category', 'body', 'source', 'tags']);

        $terms = $this->significantTerms($message);

        return $candidates
            ->map(fn (EddyKnowledge $k): array => [
                'knowledge' => $k,
                'score' => $this->score($k, $terms),
            ])
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $row): array => [
                'title' => $row['knowledge']->title,
                'category' => $row['knowledge']->category,
                'body' => Str::limit((string) $row['knowledge']->body, 600),
                'source' => $row['knowledge']->source,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function significantTerms(string $message): array
    {
        return collect(preg_split('/\W+/', strtolower($message)) ?: [])
            ->filter(fn (string $t): bool => strlen($t) >= 4)
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $terms
     */
    private function score(EddyKnowledge $k, array $terms): int
    {
        if ($terms === []) {
            return 0;
        }

        $haystack = strtolower($k->title.' '.$k->body.' '.implode(' ', $k->tags ?? []));

        return collect($terms)->filter(fn (string $t): bool => str_contains($haystack, $t))->count();
    }
}
