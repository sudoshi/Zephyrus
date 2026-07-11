// resources/js/features/arena/hooks.ts
import { useQuery } from '@tanstack/react-query';
import {
  fetchArenaCapacity,
  fetchArenaConformance,
  fetchArenaMap,
  fetchArenaNarrative,
  fetchArenaPerformance,
  fetchArenaPetriNet,
  fetchArenaProcessModel,
  fetchArenaProcessModels,
  fetchArenaReview,
  fetchArenaSummary,
  type ArenaMapParams,
} from './api';
import type { ArenaFilter } from './schema';

// Discovered maps are server-cached in arena.maps; a 60s client staleTime keeps
// the Study responsive to filter changes without re-hitting the sidecar on every
// interaction (the orchestrator's own TTL bounds real re-mining).
export function useArenaMap(params: ArenaMapParams) {
  const typesKey = [...(params.types ?? [])].sort().join(',');
  return useQuery<unknown>({
    queryKey: ['arena', 'map', params.scope ?? 'house', typesKey, params.minFreq ?? null, JSON.stringify(params.filters ?? [])],
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

export function useArenaConformance(filters?: ArenaFilter[]) {
  return useQuery<unknown>({
    queryKey: ['arena', 'conformance', JSON.stringify(filters ?? [])],
    queryFn: () => fetchArenaConformance(undefined, filters),
    staleTime: 60_000,
  });
}

export function useArenaPerformance(types?: string[], filters?: ArenaFilter[]) {
  const typesKey = [...(types ?? [])].sort().join(',');
  return useQuery<unknown>({
    queryKey: ['arena', 'performance', typesKey, JSON.stringify(filters ?? [])],
    queryFn: () => fetchArenaPerformance(types, undefined, filters),
    staleTime: 60_000,
  });
}

export function useArenaPetriNet(filters?: ArenaFilter[]) {
  return useQuery<unknown>({
    queryKey: ['arena', 'petrinet', JSON.stringify(filters ?? [])],
    queryFn: () => fetchArenaPetriNet(filters),
    staleTime: 60_000,
  });
}

export function useArenaCapacity() {
  return useQuery<unknown>({
    queryKey: ['arena', 'capacity'],
    queryFn: fetchArenaCapacity,
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

// The 48-Hour Flow Review artifact. `retry: false` because the /review endpoint
// may 404 until the backend loop ships — the movement falls back to the fixture.
export function useArenaReview(windowRef?: string) {
  return useQuery<unknown>({
    queryKey: ['arena', 'review', windowRef ?? 'latest'],
    queryFn: () => fetchArenaReview(windowRef),
    staleTime: 60_000,
    retry: false,
  });
}
