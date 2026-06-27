<?php

namespace App\Http\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * Produces the uniform Hummingbird BFF envelope:
 *   { data, meta: { as_of, stale, version }, links: { web } }
 *
 * Every mobile endpoint returns this shape so the native clients can rely on a
 * single contract (as-of timestamp, staleness, optimistic-concurrency version,
 * and a deep link back to the equivalent web surface).
 */
trait RendersMobileEnvelope
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $links
     */
    protected function envelope(mixed $data, array $meta = [], array $links = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => array_merge([
                'as_of' => now()->toISOString(),
                'stale' => false,
                'version' => null,
            ], $meta),
            'links' => $links,
        ], $status);
    }
}
