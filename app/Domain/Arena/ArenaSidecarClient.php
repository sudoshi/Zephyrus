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
     * @param  array<int, array<string, mixed>>|null  $filters
     * @return array<string, mixed>|null
     */
    public function discover(array $ocel, ?array $objectTypes = null, ?int $minFreq = null, ?array $filters = null): ?array
    {
        $body = ['ocel' => $ocel];
        if ($objectTypes !== null) {
            $body['object_types'] = array_values($objectTypes);
        }
        if ($minFreq !== null) {
            $body['activity_min_freq'] = $minFreq;
        }
        if (! empty($filters)) {
            $body['filters'] = array_values($filters);
        }

        return $this->post('/discover', $body);
    }

    /**
     * Object-centric performance (Part X §X.6): slowest lifecycle hand-offs +
     * synchronization waits at object intersections.
     *
     * @param  array<string, mixed>  $ocel
     * @param  array<int, string>|null  $objectTypes
     * @param  array<int, array<string, mixed>>|null  $filters
     * @return array<string, mixed>|null
     */
    public function performance(array $ocel, ?array $objectTypes = null, int $top = 25, ?array $filters = null): ?array
    {
        $body = ['ocel' => $ocel, 'top' => $top];
        if ($objectTypes !== null) {
            $body['object_types'] = array_values($objectTypes);
        }
        if (! empty($filters)) {
            $body['filters'] = array_values($filters);
        }

        return $this->post('/performance', $body);
    }

    /**
     * Conformance of the de-identified OCEL log against the reference care
     * pathways (Part X §X.7). Returns a list of per-pathway results, or null.
     *
     * @param  array<string, mixed>  $ocel
     * @param  array<int, array<string, mixed>>|null  $filters
     * @return array<int, array<string, mixed>>|null
     */
    public function conformance(array $ocel, ?string $pathway = null, ?array $filters = null): ?array
    {
        $body = ['ocel' => $ocel];
        if ($pathway !== null) {
            $body['pathway'] = $pathway;
        }
        if (! empty($filters)) {
            $body['filters'] = array_values($filters);
        }
        $result = $this->post('/conformance', $body);

        // /conformance returns a JSON array; post() decodes it as a list.
        return is_array($result) ? $result : null;
    }

    /**
     * Discover the object-centric Petri net for a de-identified OCEL doc (XO.2).
     *
     * @param  array<string, mixed>  $ocel
     * @param  array<int, array<string, mixed>>|null  $filters
     * @return array<string, mixed>|null
     */
    public function petrinet(array $ocel, ?array $filters = null): ?array
    {
        $body = ['ocel' => $ocel];
        if (! empty($filters)) {
            $body['filters'] = array_values($filters);
        }

        return $this->post('/discover/petrinet', $body);
    }

    /**
     * Per-unit occupancy series from a QEL payload (XO.3).
     *
     * @param  array{initial: array, operations: array}  $quantities
     * @return array<string, mixed>|null
     */
    public function capacity(array $quantities, ?string $itemType = null, ?int $threshold = null): ?array
    {
        $body = ['quantities' => $quantities];
        if ($itemType !== null) {
            $body['item_type'] = $itemType;
        }
        if ($threshold !== null) {
            $body['threshold'] = $threshold;
        }

        return $this->post('/capacity', $body);
    }

    /**
     * Part X (X4) — conformance-fitness of a copilot-proposed object-centric model
     * (a list of {object_type, source, target} arcs) against the OCEL log. Returns
     * the fitness/precision verdict the orchestrator uses to withhold a bad map, or
     * null if the copilot sidecar endpoint is off/unreachable.
     *
     * @param  array<string, mixed>  $ocel
     * @param  array<int, array{object_type:string, source:string, target:string}>  $proposedEdges
     * @return array<string, mixed>|null
     */
    public function modelFitness(array $ocel, array $proposedEdges, ?float $fitnessFloor = null): ?array
    {
        $body = ['ocel' => $ocel, 'proposed_edges' => array_values($proposedEdges)];
        if ($fitnessFloor !== null) {
            $body['fitness_floor'] = $fitnessFloor;
        }

        return $this->post('/copilot/model-fitness', $body);
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
