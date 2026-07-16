<?php

namespace App\Integrations\Healthcare\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INT-OBS 6 — the single PHI-free source-health digest that every downstream
 * signal contract (Cockpit snapshot, Hummingbird BFF, Eddy context) embeds so
 * a stale or degraded upstream source visibly degrades downstream.
 *
 * "No silent fallback" (invariant 7): each contributing source declares its
 * mode (synthetic vs live), current observability status, data freshness, and
 * a staleness flag. This service only READS the already-authoritative
 * source/observability projections — it never calls a partner or advances a
 * cursor, and it emits stable codes/labels only (no clinical content).
 */
final class SourceHealthDigestService
{
    private const CONTRIBUTING_STATES = ['active', 'testing', 'enabled'];

    /**
     * A bounded per-source digest plus a rolled-up worst-case summary.
     *
     * @return array{
     *   generatedAtIso:string,
     *   overallStatus:string,
     *   anyStale:bool,
     *   anyDegraded:bool,
     *   liveSourceCount:int,
     *   syntheticSourceCount:int,
     *   sources:list<array<string,mixed>>
     * }
     */
    public function digest(?CarbonImmutable $now = null, int $limit = 50): array
    {
        $now ??= CarbonImmutable::now();
        $limit = max(1, min(200, $limit));

        if (! Schema::hasTable('integration.sources')) {
            return $this->empty($now);
        }

        $currentBySource = Schema::hasTable('integration.source_health_current')
            ? DB::table('integration.source_health_current')->get()->keyBy('source_id')
            : collect();

        $rows = DB::table('integration.sources')
            ->where('lifecycle_state', '<>', 'retired')
            ->whereIn('active_status', self::CONTRIBUTING_STATES)
            ->orderBy('source_id')
            ->limit($limit)
            ->get();

        $sources = [];
        foreach ($rows as $row) {
            $current = $currentBySource->get($row->source_id);
            $sources[] = $this->sourceDigest($row, $current, $now);
        }

        $statuses = array_column($sources, 'status');
        $overall = $this->worstStatus($statuses);
        $anyStale = in_array(true, array_column($sources, 'stale'), true);
        $anyDegraded = (bool) array_filter($sources, static fn (array $s): bool => in_array($s['status'], ['degraded', 'failed', 'stale'], true));
        $liveCount = count(array_filter($sources, static fn (array $s): bool => $s['mode'] === 'live'));

        return [
            'generatedAtIso' => $now->toIso8601String(),
            'overallStatus' => $overall,
            'anyStale' => $anyStale,
            'anyDegraded' => (bool) $anyDegraded,
            'liveSourceCount' => $liveCount,
            'syntheticSourceCount' => count($sources) - $liveCount,
            'sources' => $sources,
        ];
    }

    /** @return array<string, mixed> */
    private function sourceDigest(object $row, ?object $current, CarbonImmutable $now): array
    {
        $mode = $this->mode($row);
        $observedAt = $current?->observed_at !== null ? CarbonImmutable::parse($current->observed_at) : null;
        $freshExpiresAt = $current?->freshness_expires_at !== null ? CarbonImmutable::parse($current->freshness_expires_at) : null;
        $stale = $freshExpiresAt === null || $freshExpiresAt->lessThanOrEqualTo($now);

        $recordedStatus = $current?->observation_status !== null ? (string) $current->observation_status : null;
        $status = match (true) {
            $recordedStatus === null => 'unobserved',
            $stale => 'stale',
            default => $recordedStatus,
        };

        return [
            'sourceKey' => (string) $row->source_key,
            'systemClass' => (string) ($row->system_class ?? 'unknown'),
            'environment' => (string) ($row->environment ?? 'unknown'),
            'mode' => $mode,
            'status' => $status,
            'recordedStatus' => $recordedStatus,
            'stale' => $stale,
            'lastObservedAtIso' => $observedAt?->toIso8601String(),
            'freshUntilIso' => $freshExpiresAt?->toIso8601String(),
        ];
    }

    private function mode(object $row): string
    {
        $environment = strtolower((string) ($row->environment ?? ''));
        $vendor = strtolower((string) ($row->vendor ?? ''));
        $key = strtolower((string) ($row->source_key ?? ''));
        // A synthetic/demo/sandbox source is never presented as a live feed.
        if ($environment === 'sandbox'
            || str_contains($vendor, 'synthetic')
            || str_contains($key, 'synthetic')
            || str_contains($key, 'demo')) {
            return 'synthetic';
        }
        if (! (bool) ($row->phi_allowed ?? false)) {
            return 'synthetic';
        }

        return 'live';
    }

    /** @param list<string> $statuses */
    private function worstStatus(array $statuses): string
    {
        if ($statuses === []) {
            return 'not_configured';
        }
        foreach (['failed', 'stale', 'degraded', 'unknown', 'unobserved', 'maintenance', 'disabled', 'healthy'] as $rank) {
            if (in_array($rank, $statuses, true)) {
                return $rank;
            }
        }

        return 'unknown';
    }

    /** @return array{generatedAtIso:string, overallStatus:string, anyStale:bool, anyDegraded:bool, liveSourceCount:int, syntheticSourceCount:int, sources:list<array<string,mixed>>} */
    private function empty(CarbonImmutable $now): array
    {
        return [
            'generatedAtIso' => $now->toIso8601String(),
            'overallStatus' => 'not_configured',
            'anyStale' => false,
            'anyDegraded' => false,
            'liveSourceCount' => 0,
            'syntheticSourceCount' => 0,
            'sources' => [],
        ];
    }
}
