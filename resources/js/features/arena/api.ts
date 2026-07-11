// resources/js/features/arena/api.ts
//
// Zephyrus 2.0 Part X (X1). Thin transport to the Laravel Arena orchestrator
// (which proxies to the PHI-free OCPM sidecar and caches in arena.maps).
// Returns `unknown`; the page parses with the Zod schema at the boundary.
import axios from 'axios';
import type { ArenaFilter } from './schema';

export interface ArenaMapParams {
  types?: string[];
  minFreq?: number;
  scope?: string;
  force?: boolean;
  filters?: ArenaFilter[];
}

export async function fetchArenaMap(params: ArenaMapParams = {}): Promise<unknown> {
  const res = await axios.get('/api/arena/map', {
    params: {
      types: params.types && params.types.length > 0 ? params.types.join(',') : undefined,
      min_freq: params.minFreq,
      scope: params.scope,
      force: params.force ? 1 : undefined,
      filters: params.filters && params.filters.length > 0 ? JSON.stringify(params.filters) : undefined,
    },
  });
  return res.data;
}

export async function fetchArenaSummary(): Promise<unknown> {
  const res = await axios.get('/api/arena/summary');
  return res.data;
}

export async function fetchArenaProcessModels(): Promise<unknown> {
  const res = await axios.get('/api/arena/models');
  return res.data;
}

export async function fetchArenaProcessModel(processId: string): Promise<unknown> {
  const res = await axios.get(`/api/arena/models/${encodeURIComponent(processId)}`);
  return res.data;
}

export async function fetchArenaConformance(pathway?: string, filters?: ArenaFilter[]): Promise<unknown> {
  const res = await axios.get('/api/arena/conformance', {
    params: {
      pathway: pathway || undefined,
      filters: filters && filters.length > 0 ? JSON.stringify(filters) : undefined,
    },
  });
  return res.data;
}

export async function fetchArenaPerformance(types?: string[], top?: number, filters?: ArenaFilter[]): Promise<unknown> {
  const res = await axios.get('/api/arena/performance', {
    params: {
      types: types && types.length > 0 ? types.join(',') : undefined,
      top,
      filters: filters && filters.length > 0 ? JSON.stringify(filters) : undefined,
    },
  });
  return res.data;
}

// --- X4 governed AI copilot (routes 404 unless ARENA_AI_ENABLED) ---

export async function fetchArenaNarrative(): Promise<unknown> {
  const res = await axios.get('/api/arena/copilot/narrative');
  return res.data;
}

export async function postArenaQuery(question: string): Promise<unknown> {
  const res = await axios.post('/api/arena/copilot/query', { question });
  return res.data;
}

export async function postArenaAuthorMap(types?: string[]): Promise<unknown> {
  const res = await axios.post('/api/arena/copilot/author-map', {
    types: types && types.length > 0 ? types.join(',') : undefined,
  });
  return res.data;
}

export async function postArenaDraftPdsa(focus: string): Promise<unknown> {
  const res = await axios.post('/api/arena/copilot/draft-pdsa', { focus });
  return res.data;
}

export async function postArenaDraftCorrection(pathway: string): Promise<unknown> {
  const res = await axios.post('/api/arena/copilot/draft-correction', { pathway });
  return res.data;
}
