<?php

namespace App\Domain\Arena;

use App\Domain\Ocel\OcelJsonExporter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Arena orchestrator (Part X §X.4.2). Mirrors the cockpit's /snapshot
 * discipline: the Study UI reads a cache (arena.maps), never a live mining run.
 * On a cache miss it posts the de-identified OCEL log to the OCPM sidecar and
 * stashes the discovered map, keyed by (scope, object types, min-freq, source
 * signature). The signature is a cheap fingerprint of the OCEL log, so a
 * re-projection naturally invalidates stale maps. If the sidecar is down, it
 * serves the last-good map flagged stale rather than failing.
 */
class ArenaService
{
    public function __construct(
        private readonly ArenaSidecarClient $client,
        private readonly OcelJsonExporter $exporter,
    ) {}

    /** Sidecar liveness passthrough for the admin surface. */
    public function health(): array
    {
        return $this->client->health() ?? ['status' => 'down', 'service' => 'zephyrus-arena'];
    }

    /**
     * A discovered object-centric map for the given scope, cached in arena.maps.
     *
     * @param  array<int, string>|null  $objectTypes
     * @return array<string, mixed>
     */
    public function map(?array $objectTypes = null, ?int $minFreq = null, string $scope = 'house', bool $force = false): array
    {
        $signature = $this->sourceSignature();
        $normTypes = $this->normaliseTypes($objectTypes);
        $cacheKey = sha1($scope.'|'.json_encode($normTypes).'|'.(int) $minFreq.'|'.$signature);
        $ttl = (int) config('services.arena.cache_ttl', 900);

        $cached = DB::table('arena.maps')->where('cache_key', $cacheKey)->first();
        if (! $force && $cached !== null && Carbon::parse($cached->mined_at)->gt(now()->subSeconds($ttl))) {
            return $this->wrapCached($cached, stale: false);
        }

        $doc = $this->exporter->export();
        $result = $this->client->discover($doc, $normTypes, $minFreq);

        if ($result === null) {
            $fallback = $cached ?? DB::table('arena.maps')->where('scope', $scope)->orderByDesc('mined_at')->first();
            if ($fallback !== null) {
                return $this->wrapCached($fallback, stale: true);
            }

            return ['available' => false, 'reason' => 'sidecar_unavailable', 'scope' => $scope];
        }

        $now = now();
        DB::table('arena.maps')->upsert([[
            'cache_key' => $cacheKey,
            'scope' => $scope,
            'object_types' => $normTypes !== null ? json_encode($normTypes) : null,
            'min_freq' => (int) ($minFreq ?? 1),
            'source_signature' => $signature,
            'payload' => json_encode($result),
            'node_count' => count($result['nodes'] ?? []),
            'edge_count' => count($result['edges'] ?? []),
            'mined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['cache_key'], ['scope', 'object_types', 'min_freq', 'source_signature', 'payload', 'node_count', 'edge_count', 'mined_at', 'updated_at']);

        return [
            'available' => true,
            'cached' => false,
            'stale' => false,
            'scope' => $scope,
            'source_signature' => $signature,
            'mined_at' => $now->toIso8601String(),
            'map' => $result,
        ];
    }

    /**
     * Object/event/activity counts for the current OCEL log (uncached — cheap).
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $doc = $this->exporter->export();
        $summary = $this->client->summary($doc);

        return $summary ?? ['available' => false, 'reason' => 'sidecar_unavailable'];
    }

    /** A cheap fingerprint of the OCEL log; changes when the projection changes. */
    private function sourceSignature(): string
    {
        $row = DB::table('ocel.events')->selectRaw('count(*) as c, max(event_time) as m')->first();

        return sha1((string) ((int) ($row->c ?? 0)).'|'.(string) ($row->m ?? ''));
    }

    /**
     * @param  array<int, string>|null  $objectTypes
     * @return array<int, string>|null
     */
    private function normaliseTypes(?array $objectTypes): ?array
    {
        if ($objectTypes === null) {
            return null;
        }
        $types = array_values(array_unique(array_filter(array_map('trim', $objectTypes))));
        if ($types === []) {
            return null;
        }
        sort($types);

        return $types;
    }

    /** @return array<string, mixed> */
    private function wrapCached(object $row, bool $stale): array
    {
        return [
            'available' => true,
            'cached' => true,
            'stale' => $stale,
            'scope' => $row->scope,
            'source_signature' => $row->source_signature,
            'mined_at' => Carbon::parse($row->mined_at)->toIso8601String(),
            'map' => json_decode($row->payload, true),
        ];
    }
}
