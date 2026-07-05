// resources/js/features/arena/api.ts
//
// Zephyrus 2.0 Part X (X1). Thin transport to the Laravel Arena orchestrator
// (which proxies to the PHI-free OCPM sidecar and caches in arena.maps).
// Returns `unknown`; the page parses with the Zod schema at the boundary.
import axios from 'axios';

export interface ArenaMapParams {
  types?: string[];
  minFreq?: number;
  scope?: string;
  force?: boolean;
}

export async function fetchArenaMap(params: ArenaMapParams = {}): Promise<unknown> {
  const res = await axios.get('/api/arena/map', {
    params: {
      types: params.types && params.types.length > 0 ? params.types.join(',') : undefined,
      min_freq: params.minFreq,
      scope: params.scope,
      force: params.force ? 1 : undefined,
    },
  });
  return res.data;
}

export async function fetchArenaSummary(): Promise<unknown> {
  const res = await axios.get('/api/arena/summary');
  return res.data;
}

export async function fetchArenaConformance(pathway?: string): Promise<unknown> {
  const res = await axios.get('/api/arena/conformance', {
    params: { pathway: pathway || undefined },
  });
  return res.data;
}

export async function fetchArenaPerformance(types?: string[], top?: number): Promise<unknown> {
  const res = await axios.get('/api/arena/performance', {
    params: {
      types: types && types.length > 0 ? types.join(',') : undefined,
      top,
    },
  });
  return res.data;
}
