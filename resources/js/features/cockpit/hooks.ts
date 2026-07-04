// resources/js/features/cockpit/hooks.ts
import { useQuery } from '@tanstack/react-query';
import type { CockpitDrillDomain } from '@/types/cockpit';
import { fetchCockpitDrill, fetchCockpitSnapshot } from './api';

// 45s polling matches the pre-2.0 Inertia reload cadence the stale/aging
// thresholds were tuned against (STALE = 2.5×, AGING = 1.4× in the page).
export const COCKPIT_REFRESH_MS = 45_000;

/**
 * The ONE page-level data stream for /dashboard (P2). Inertia's server render
 * seeds initialData; afterwards TanStack polls /api/cockpit/snapshot — same
 * cached payload, no Inertia prop round-trip. Data stays `unknown` end to end;
 * parsing happens at the page boundary.
 */
export function useCockpitSnapshot(initialData: unknown, initialDataUpdatedAt?: number) {
  return useQuery<unknown>({
    queryKey: ['cockpit', 'snapshot'],
    queryFn: fetchCockpitSnapshot,
    initialData,
    initialDataUpdatedAt,
    refetchInterval: COCKPIT_REFRESH_MS,
    // Poll even when the tab is a wall display that never gets focus events.
    refetchIntervalInBackground: true,
    staleTime: 30_000,
  });
}

/**
 * P3 — one drill payload per open modal. Keyed by domain so walking drills
 * via the browser Back button re-serves each from cache; KPI numbers inside
 * come from the same cached snapshot the wall shows (DrillBuilder), so the
 * modal can never disagree with the tile that opened it.
 */
export function useCockpitDrill(domain: CockpitDrillDomain | null) {
  return useQuery<unknown>({
    queryKey: ['cockpit', 'drill', domain],
    queryFn: () => fetchCockpitDrill(domain as CockpitDrillDomain),
    enabled: domain !== null,
    staleTime: 30_000,
  });
}
