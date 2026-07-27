<?php

namespace App\Domain\Arena;

use App\Domain\Ocel\EmissionMap;
use App\Domain\Ocel\OcelJsonExporter;
use App\Domain\Ocel\QuantityExporter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        private readonly QuantityExporter $quantityExporter,
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
     * @param  array<int, array<string, mixed>>|null  $filters
     * @return array<string, mixed>
     */
    public function map(?array $objectTypes = null, ?int $minFreq = null, string $scope = 'house', bool $force = false, ?array $filters = null): array
    {
        $signature = $this->sourceSignature();
        $normTypes = $this->normaliseTypes($objectTypes);
        $filterSig = $this->filterSignature($filters);
        $cacheKey = sha1($scope.'|'.json_encode($normTypes).'|'.(int) $minFreq.'|'.$filterSig.'|'.$signature);
        $ttl = (int) config('services.arena.cache_ttl', 900);

        $cached = DB::table('arena.maps')->where('cache_key', $cacheKey)->first();
        if (! $force && $cached !== null && Carbon::parse($cached->mined_at)->gt(now()->subSeconds($ttl))) {
            return $this->wrapCached($cached, stale: false);
        }

        $doc = $this->exporter->export();
        $result = $this->client->discover($doc, $normTypes, $minFreq, $filters);

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

    /**
     * Conformance of the current OCEL log against the reference care pathways
     * (Part X §X.7). Uncached — a Study read, not a polled surface.
     *
     * @param  array<int, array<string, mixed>>|null  $filters
     * @return array<string, mixed>
     */
    public function conformance(?string $pathway = null, ?array $filters = null, bool $perCase = false, ?array $caseIds = null): array
    {
        $doc = $this->exporter->export();
        $results = $this->client->conformance($doc, $pathway, $filters, $perCase, $caseIds);

        if ($results === null) {
            return ['available' => false, 'reason' => 'sidecar_unavailable'];
        }

        return ['available' => true, 'pathways' => $results];
    }

    /**
     * Cached per-case conformance verdicts for a set of OCEL case object ids —
     * the 4D Navigator's per-patient adherence read (FLOW-4D plan §8 A2,
     * finding CF-2). Reads arena.case_conformance ONLY (RefreshArenaConformance
     * writes it on its own cadence); a browser request never triggers a mining
     * run, mirroring the /cockpit/snapshot discipline.
     *
     * @param  list<string>  $caseOids  candidate de-identified case ids
     *                                  (enc-<hash12> / patient-<hash12> / orcase-<id>)
     * @return array<string, mixed>
     */
    public function caseConformance(array $caseOids): array
    {
        $caseOids = array_values(array_unique(array_filter($caseOids, 'is_string')));
        if ($caseOids === []) {
            return ['available' => true, 'verdicts' => [], 'computed_at' => null];
        }

        $rows = DB::table('arena.case_conformance')
            ->whereIn('case_oid', $caseOids)
            ->orderBy('pathway')
            ->get();

        $computedAt = null;
        $verdicts = [];
        foreach ($rows as $row) {
            $rowComputedAt = Carbon::parse((string) $row->computed_at);
            if ($computedAt === null || $rowComputedAt->gt($computedAt)) {
                $computedAt = $rowComputedAt;
            }

            // The de-identified case oid stays server-side: an authorized
            // client needs the verdict, never the hash it was joined on.
            $verdicts[] = [
                'pathway' => (string) $row->pathway,
                'pathway_version' => (int) $row->pathway_version,
                'conformant' => (bool) $row->conformant,
                'deviations' => json_decode((string) $row->deviations, true) ?: [],
                'activity_timeline' => json_decode((string) $row->activity_timeline, true) ?: (object) [],
                'computed_at' => $rowComputedAt->toIso8601String(),
            ];
        }

        return [
            'available' => true,
            'verdicts' => $verdicts,
            'computed_at' => $computedAt?->toIso8601String(),
        ];
    }

    /**
     * Object-centric performance of the current OCEL log (§X.6). Uncached — a
     * Study read.
     *
     * @param  array<int, string>|null  $objectTypes
     * @param  array<int, array<string, mixed>>|null  $filters
     * @return array<string, mixed>
     */
    public function performance(?array $objectTypes = null, int $top = 25, ?array $filters = null): array
    {
        $doc = $this->exporter->export();
        $result = $this->client->performance($doc, $objectTypes, $top, $filters);

        if ($result === null) {
            return ['available' => false, 'reason' => 'sidecar_unavailable'];
        }

        return ['available' => true] + $result;
    }

    /**
     * Object-centric Petri net for the current OCEL log (§XO.2). Uncached — a Study read.
     *
     * @param  array<int, array<string, mixed>>|null  $filters
     * @return array<string, mixed>
     */
    public function petrinet(?array $filters = null): array
    {
        $doc = $this->exporter->export();
        $result = $this->client->petrinet($doc, $filters);

        if ($result === null) {
            return ['available' => false, 'reason' => 'sidecar_unavailable'];
        }

        return ['available' => true] + $result;
    }

    /**
     * Per-unit occupancy / capacity curve for the current QEL projection (§XO.3).
     * Uncached — a Study read.
     *
     * @return array<string, mixed>
     */
    public function capacity(string $itemType = 'occupied_beds', ?int $threshold = null): array
    {
        $payload = $this->quantityExporter->export();
        $result = $this->client->capacity($payload, $itemType, $threshold);

        if ($result === null) {
            return ['available' => false, 'reason' => 'sidecar_unavailable'];
        }

        return ['available' => true] + $result;
    }

    /**
     * Every OCEL case object id a live patient could appear under — the
     * deterministic hash join (FLOW-4D plan §5): EmissionMap::hashRef is the
     * same unsalted stable hash the projector used, so Laravel (which holds
     * identified context legitimately) recomputes the de-identified ids here
     * and the sidecar/cache never learn identity.
     *
     * @return list<string>
     */
    public function caseOidsForPatient(string $patientRef): array
    {
        $oids = [];

        if (($hash = EmissionMap::hashRef($patientRef)) !== null) {
            $oids[] = 'patient-'.$hash;
        }

        if (Schema::hasTable('flow_core.flow_events')) {
            $encounterRefs = DB::table('flow_core.flow_events')
                ->where('patient_ref', $patientRef)
                ->whereNotNull('encounter_ref')
                ->distinct()
                ->pluck('encounter_ref');

            foreach ($encounterRefs as $ref) {
                if (($hash = EmissionMap::hashRef((string) $ref)) !== null) {
                    $oids[] = 'enc-'.$hash;
                }
            }
        }

        if (Schema::hasTable('prod.encounters')) {
            $encounterIds = DB::table('prod.encounters')
                ->where('patient_ref', $patientRef)
                ->where('is_deleted', false)
                ->pluck('encounter_id');

            foreach ($encounterIds as $id) {
                if (($hash = EmissionMap::hashRef((string) $id)) !== null) {
                    $oids[] = 'enc-'.$hash;
                }
            }
        }

        if (Schema::hasTable('prod.or_cases')) {
            $caseIds = DB::table('prod.or_cases')
                ->where('patient_id', $patientRef)
                ->where('is_deleted', false)
                ->pluck('case_id');

            foreach ($caseIds as $caseId) {
                $oids[] = 'orcase-'.$caseId;
            }
        }

        return array_values(array_unique($oids));
    }

    /** A stable fingerprint of the filter pipeline for cache keying (order-sensitive). */
    private function filterSignature(?array $filters): string
    {
        if (empty($filters)) {
            return 'nofilter';
        }

        return sha1(json_encode(array_values($filters)));
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
