<?php

namespace App\Services\Cockpit;

use App\Models\Cockpit\CockpitSnapshot;
use App\Services\CommandCenterDataService;
use App\Services\Ops\Agents\AgentToolRegistry;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Assembles the ONE server-computed cockpit snapshot (Zephyrus 2.0 P1).
 *
 * v1 wraps CommandCenterDataService::build() — the working proto-builder with
 * the frozen Zod contract — and embeds the Eddy capacity.snapshot document so
 * the cockpit, the drills, AND Eddy read the same numbers (the single-snapshot
 * discipline; EddyContextService's tool path reads the same cache key). The
 * per-domain Domain/Cockpit/Metrics decomposition lands incrementally on top
 * of this seam; the payload contract does not change when it does.
 *
 * Single-facility per decision D1: one replaced row keyed by the manifest's
 * facility code ('HOSP1'); no facilities table.
 */
class SnapshotBuilder
{
    public const CACHE_KEY = 'cockpit.snapshot';

    /** Slightly over the 60s refresh cadence so a slow job never leaves a gap. */
    public const CACHE_TTL_SECONDS = 90;

    public function __construct(
        private readonly CommandCenterDataService $commandCenter,
        private readonly AgentToolRegistry $tools,
        private readonly HospitalManifest $manifest,
    ) {}

    /** @return array<string, mixed> */
    public function build(?string $facilityKey = null): array
    {
        $facilityKey ??= $this->manifest->facilityCode();

        $payload = $this->commandCenter->build();
        $payload['facilityKey'] = $facilityKey;
        $payload['facilityName'] = $this->manifest->facilityName();
        $payload['capacity'] = $this->safeCapacity();

        return $payload;
    }

    /**
     * Build, persist the single replaced row, and prime the shared cache.
     *
     * @return array<string, mixed> the persisted payload
     */
    public function refresh(?string $facilityKey = null): array
    {
        $facilityKey ??= $this->manifest->facilityCode();

        $payload = $this->build($facilityKey);

        CockpitSnapshot::query()->updateOrCreate(
            ['facility_key' => $facilityKey],
            ['payload' => $payload, 'generated_at' => now()],
        );

        Cache::put(self::CACHE_KEY, $payload, self::CACHE_TTL_SECONDS);

        return $payload;
    }

    /**
     * The capacity.snapshot tool document, embedded so Eddy's worldview and
     * the cockpit are the same snapshot. Fail-open: capacity trouble must
     * never blank the whole snapshot (PG 25P02 discipline — the build is
     * plain reads, but one bad domain must not poison the rest).
     *
     * @return array<string, mixed>|null
     */
    private function safeCapacity(): ?array
    {
        try {
            return $this->tools->capacitySnapshot();
        } catch (\Throwable $e) {
            Log::warning('cockpit.snapshot.capacity_unavailable', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
