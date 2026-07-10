// resources/js/features/arena/hooks.ts
import { useQuery } from '@tanstack/react-query';
import {
  fetchArenaConformance,
  fetchArenaMap,
  fetchArenaNarrative,
  fetchArenaPerformance,
  fetchArenaProcessModel,
  fetchArenaProcessModels,
  fetchArenaSummary,
  type ArenaMapParams,
} from './api';

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

export function useArenaProcessModels() {
  return useQuery<unknown>({
    queryKey: ['arena', 'process-models'],
    queryFn: fetchArenaProcessModels,
    staleTime: 5 * 60_000,
  });
}

export function useArenaProcessModel(processId: string | null) {
  return useQuery<unknown>({
    queryKey: ['arena', 'process-model', processId],
    queryFn: () => fetchArenaProcessModel(processId as string),
    enabled: processId !== null && processId !== '',
    staleTime: 5 * 60_000,
  });
}

export function useArenaConformance() {
  return useQuery<unknown>({
    queryKey: ['arena', 'conformance'],
    queryFn: () => fetchArenaConformance(),
    staleTime: 60_000,
  });
}

export function useArenaPerformance(types?: string[]) {
  const typesKey = [...(types ?? [])].sort().join(',');
  return useQuery<unknown>({
    queryKey: ['arena', 'performance', typesKey],
    queryFn: () => fetchArenaPerformance(types),
    staleTime: 60_000,
  });
}

// X4 copilot narrative — only fetched when the copilot is enabled (the pane passes
// the ai_enabled shared prop through, so a disabled copilot never hits the 404 route).
export function useArenaNarrative(enabled: boolean) {
  return useQuery<unknown>({
    queryKey: ['arena', 'copilot', 'narrative'],
    queryFn: fetchArenaNarrative,
    enabled,
    staleTime: 60_000,
  });
}
