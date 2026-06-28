<?php

namespace App\Services\Eddy;

use App\Models\Eddy\EddyKnowledge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * RAG over eddy_knowledge: surface-scoped (+ 'global') operational doctrine,
 * limited to active, PHI-free, APPROVED rows.
 *
 * Phase 6 makes retrieval HYBRID. When pgvector is present, embeddings are enabled,
 * and the query embeds, it blends cosine similarity with keyword overlap; otherwise
 * (or when no embedded rows match) it degrades to the deterministic Phase 2 keyword
 * path. The public contract is unchanged — same shaped rows out either way.
 */
class EddyKnowledgeService
{
    public function __construct(private readonly EddyEmbeddingService $embeddings) {}

    /**
     * @return array<int, array{title:string, category:string, body:string, source:?string}>
     */
    public function forSurface(string $surface, string $message, int $limit = 3): array
    {
        if (! Schema::hasTable('eddy.eddy_knowledge')) {
            return [];
        }

        $terms = $this->significantTerms($message);

        $scored = $this->hybridCandidates($surface, $message, $terms)
            ?? $this->keywordCandidates($surface, $terms);

        return collect($scored)
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $row): array => [
                'title' => $row['title'],
                'category' => $row['category'],
                'body' => Str::limit((string) $row['body'], 600),
                'source' => $row['source'],
            ])
            ->values()
            ->all();
    }

    /**
     * Vector + keyword blend. Returns null (→ keyword fallback) when embeddings are
     * disabled, the query won't embed, or no embedded rows match.
     *
     * @param  array<int, string>  $terms
     * @return array<int, array{title:string, category:string, body:?string, source:?string, score:float}>|null
     */
    private function hybridCandidates(string $surface, string $message, array $terms): ?array
    {
        if (! $this->embeddings->enabled()) {
            return null;
        }

        $vector = $this->embeddings->embed($message);
        if ($vector === null) {
            return null;
        }

        $literal = $this->embeddings->toVectorLiteral($vector);

        // Cosine distance via <=>; sim = 1 - distance (0..1). HNSW orders the scan.
        $rows = DB::select(
            'SELECT title, category, body, source, tags, '
            .'1 - (embedding <=> ?::vector) AS sim '
            .'FROM eddy.eddy_knowledge '
            .'WHERE is_active = true AND is_phi_free = true AND status = ? '
            .'AND surface IN (?, ?) AND embedding IS NOT NULL '
            .'ORDER BY embedding <=> ?::vector LIMIT 12',
            [$literal, 'approved', $surface, 'global', $literal],
        );

        if ($rows === []) {
            return null;   // embeddings on but nothing backfilled yet → keyword path
        }

        $weight = (float) config('eddy.embeddings.vector_weight', 0.7);
        $termCount = max(1, count($terms));

        return array_map(function (object $row) use ($terms, $termCount, $weight): array {
            $keyword = $this->keywordOverlap($row->title.' '.$row->body.' '.$this->tagsToString($row->tags), $terms) / $termCount;
            $sim = (float) $row->sim;

            return [
                'title' => $row->title,
                'category' => $row->category,
                'body' => $row->body,
                'source' => $row->source,
                'score' => $weight * $sim + (1.0 - $weight) * $keyword,
            ];
        }, $rows);
    }

    /**
     * Deterministic keyword overlap (the Phase 2 path; also the fallback).
     *
     * @param  array<int, string>  $terms
     * @return array<int, array{title:string, category:string, body:?string, source:?string, score:float}>
     */
    private function keywordCandidates(string $surface, array $terms): array
    {
        $candidates = EddyKnowledge::query()
            ->active()
            ->phiFree()
            ->when(
                Schema::hasColumn('eddy.eddy_knowledge', 'status'),
                fn ($q) => $q->where('status', 'approved'),
            )
            ->where(fn ($q) => $q->where('surface', $surface)->orWhere('surface', 'global'))
            ->orderByDesc('updated_at')
            ->limit(12)
            ->get(['title', 'category', 'body', 'source', 'tags']);

        return $candidates
            ->map(fn (EddyKnowledge $k): array => [
                'title' => $k->title,
                'category' => $k->category,
                'body' => $k->body,
                'source' => $k->source,
                'score' => (float) $this->keywordOverlap($k->title.' '.$k->body.' '.implode(' ', $k->tags ?? []), $terms),
            ])
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
    private function keywordOverlap(string $haystack, array $terms): int
    {
        if ($terms === []) {
            return 0;
        }

        $haystack = strtolower($haystack);

        return collect($terms)->filter(fn (string $t): bool => str_contains($haystack, $t))->count();
    }

    /** Normalize a tags value (jsonb string from raw SQL, or array) to a string. */
    private function tagsToString(mixed $tags): string
    {
        if (is_string($tags)) {
            $decoded = json_decode($tags, true);
            $tags = is_array($decoded) ? $decoded : [];
        }

        return is_array($tags) ? implode(' ', $tags) : '';
    }
}
