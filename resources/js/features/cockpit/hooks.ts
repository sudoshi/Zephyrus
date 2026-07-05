// resources/js/features/cockpit/hooks.ts
import { useQuery } from '@tanstack/react-query';
import { isScopedMount, type CockpitDrillDomain } from '@/types/cockpit';
import { isPatientContextRef } from '@/types/patientLens';
import { fetchCockpitDrill, fetchCockpitFace, fetchCockpitPatient, fetchCockpitSnapshot } from './api';

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

/**
 * P8 WS-2 — the scoped mount face. Keyed by scope token so switching mounts
 * re-serves from cache; enabled only for a real non-house mount (the house
 * overview renders from the snapshot with no extra fetch). Polls on the same
 * cadence as the snapshot so a wall-mounted unit/dept face stays live.
 */
export function useCockpitFace(scopeToken: string | null) {
  return useQuery<unknown>({
    queryKey: ['cockpit', 'face', scopeToken],
    queryFn: () => fetchCockpitFace(scopeToken as string),
    enabled: isScopedMount(scopeToken),
    refetchInterval: COCKPIT_REFRESH_MS,
    refetchIntervalInBackground: true,
    staleTime: 30_000,
  });
}

/**
 * P8 WS-3 — the A2P patient lens for a drill context ref. Keyed by ptok so
 * re-opening the same patient re-serves from cache; enabled only for a real
 * context ref (a closed lens fetches nothing). Polls on the snapshot cadence so
 * a lens left open on a wall stays live. A 403 (persona/authorization denial)
 * rejects and PatientLens renders the access-limited state.
 */
export function useCockpitPatient(contextRef: string | null) {
  return useQuery<unknown>({
    queryKey: ['cockpit', 'patient', contextRef],
    queryFn: () => fetchCockpitPatient(contextRef as string),
    enabled: isPatientContextRef(contextRef),
    refetchInterval: COCKPIT_REFRESH_MS,
    refetchIntervalInBackground: true,
    staleTime: 30_000,
  });
}
