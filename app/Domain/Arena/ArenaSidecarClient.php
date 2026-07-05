<?php

namespace App\Domain\Arena;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side client for the Part X OCPM sidecar. The browser never reaches the
 * sidecar; Laravel proxies here. The sidecar is stateless and PHI-free — Laravel
 * posts a de-identified OCEL 2.0 doc inline and gets back canon-shaped discovery
 * JSON. Every call degrades to null on failure (never throws into the request),
 * so the Arena falls back to a last-good cached map rather than white-screening.
 */
class ArenaSidecarClient
{
    private string $baseUrl;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.arena.url'), '/');
        $this->timeout = (int) config('services.arena.timeout', 60);
    }

    /** Liveness + engine availability, or null if the sidecar is unreachable. */
    public function health(): ?array
    {
        try {
            $resp = Http::timeout(5)->get("{$this->baseUrl}/health");

            return $resp->successful() ? $resp->json() : null;
        } catch (\Throwable $e) {
            Log::warning('arena.sidecar.health_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Object/event/activity counts for a de-identified OCEL doc.
     *
     * @param  array<string, mixed>  $ocel
     * @return array<string, mixed>|null
     */
    public function summary(array $ocel): ?array
    {
        return $this->post('/ocel/summary', ['ocel' => $ocel]);
    }

    /**
     * Discover the object-centric DFG for a de-identified OCEL doc.
     *
     * @param  array<string, mixed>  $ocel
     * @param  array<int, string>|null  $objectTypes
     * @return array<string, mixed>|null
     */
    public function discover(array $ocel, ?array $objectTypes = null, ?int $minFreq = null): ?array
    {
        $body = ['ocel' => $ocel];
        if ($objectTypes !== null) {
            $body['object_types'] = array_values($objectTypes);
        }
        if ($minFreq !== null) {
            $body['activity_min_freq'] = $minFreq;
        }

        return $this->post('/discover', $body);
    }

    /**
     * Object-centric performance (Part X §X.6): slowest lifecycle hand-offs +
     * synchronization waits at object intersections.
     *
     * @param  array<string, mixed>  $ocel
     * @param  array<int, string>|null  $objectTypes
     * @return array<string, mixed>|null
     */
    public function performance(array $ocel, ?array $objectTypes = null, int $top = 25): ?array
    {
        $body = ['ocel' => $ocel, 'top' => $top];
        if ($objectTypes !== null) {
            $body['object_types'] = array_values($objectTypes);
        }

        return $this->post('/performance', $body);
    }

    /**
     * Conformance of the de-identified OCEL log against the reference care
     * pathways (Part X §X.7). Returns a list of per-pathway results, or null.
     *
     * @param  array<string, mixed>  $ocel
     * @return array<int, array<string, mixed>>|null
     */
    public function conformance(array $ocel, ?string $pathway = null): ?array
    {
        $body = ['ocel' => $ocel];
        if ($pathway !== null) {
            $body['pathway'] = $pathway;
        }
        $result = $this->post('/conformance', $body);

        // /conformance returns a JSON array; post() decodes it as a list.
        return is_array($result) ? $result : null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<mixed>|null
     */
    private function post(string $path, array $body): ?array
    {
        try {
            $resp = Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post("{$this->baseUrl}{$path}", $body);

            if (! $resp->successful()) {
                Log::warning('arena.sidecar.error', ['path' => $path, 'status' => $resp->status()]);

                return null;
            }

            return $resp->json();
        } catch (\Throwable $e) {
            Log::warning('arena.sidecar.request_failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
