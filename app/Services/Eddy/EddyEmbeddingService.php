<?php

namespace App\Services\Eddy;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Turns text into a vector via the stateless Eddy service (/eddy/embed → local
 * embedding model). Laravel owns the vector store; this is only the encoder.
 *
 * Fail-OPEN by design: any transport/format failure returns null so callers fall
 * back to the deterministic keyword path rather than block on or persist a bad
 * vector. Embedding is gated by config AND the presence of the pgvector column.
 */
class EddyEmbeddingService
{
    /** Embeddings are usable only when enabled AND the schema actually has the column. */
    public function enabled(): bool
    {
        return (bool) config('eddy.embeddings.enabled') && $this->columnPresent();
    }

    public function columnPresent(): bool
    {
        return Schema::hasColumn('eddy.eddy_knowledge', 'embedding');
    }

    /**
     * @return array<int, float>|null the embedding, or null on any failure (fail-open)
     */
    public function embed(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('services.eddy.timeout', 30))
                ->acceptJson()
                ->post(rtrim((string) config('services.eddy.url'), '/').'/eddy/embed', [
                    'text' => $text,
                    'model' => config('eddy.embeddings.model'),
                ]);
        } catch (\Throwable $e) {
            Log::warning('eddy.embed.transport_failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('eddy.embed.http_error', ['status' => $response->status()]);

            return null;
        }

        $vector = $response->json('embedding');

        if (! is_array($vector) || $vector === []) {
            return null;
        }

        return array_map(static fn ($v): float => (float) $v, $vector);
    }

    /**
     * Format a vector as a pgvector literal: [0.1,0.2,…]. Bind it as a string and
     * cast with ::vector in SQL.
     *
     * @param  array<int, float>  $vector
     */
    public function toVectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(static fn ($v): string => rtrim(rtrim(sprintf('%.8F', (float) $v), '0'), '.'), $vector)).']';
    }
}
