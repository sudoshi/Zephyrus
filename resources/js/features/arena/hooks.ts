// resources/js/features/arena/hooks.ts
import { useQuery } from '@tanstack/react-query';
import { fetchArenaConformance, fetchArenaMap, fetchArenaSummary, type ArenaMapParams } from './api';

// Discovered maps are server-cached in arena.maps; a 60s client staleTime keeps
// the Study responsive to filter changes without re-hitting the sidecar on every
// interaction (the orchestrator's own TTL bounds real re-mining).
export function useArenaMap(params: ArenaMapParams) {
  const typesKey = [...(params.types ?? [])].sort().join(',');
  return useQuery<unknown>({
    queryKey: ['arena', 'map', params.scope ?? 'house', typesKey, params.minFreq ?? null],
    queryFn: () => fetchArenaMap(params),
    staleTime: 60_000,
  });
}

export function useArenaSummary() {
  return useQuery<unknown>({
    queryKey: ['arena', 'summary'],
    queryFn: fetchArenaSummary,
    staleTime: 60_000,
  });
}

export function useArenaConformance() {
  return useQuery<unknown>({
    queryKey: ['arena', 'conformance'],
    queryFn: () => fetchArenaConformance(),
    staleTime: 60_000,
  });
}
