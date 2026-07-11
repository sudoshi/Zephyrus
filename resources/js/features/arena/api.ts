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

// The draft endpoints accept an optional target_ref (the review-barrier id this
// draft answers) + barrier_id (a real prod.barriers row), so the 48h Review can
// fold the resulting pending draft back onto the barrier it was raised from.
export interface ArenaDraftTarget {
  target_ref?: string;
  barrier_id?: number;
}

export async function postArenaDraftPdsa(focus: string, target: ArenaDraftTarget = {}): Promise<unknown> {
  const res = await axios.post('/api/arena/copilot/draft-pdsa', {
    focus,
    target_ref: target.target_ref,
    barrier_id: target.barrier_id,
  });
  return res.data;
}

export async function postArenaDraftCorrection(pathway: string, target: ArenaDraftTarget = {}): Promise<unknown> {
  const res = await axios.post('/api/arena/copilot/draft-correction', {
    pathway,
    target_ref: target.target_ref,
    barrier_id: target.barrier_id,
  });
  return res.data;
}

// Approve (or reject) a pending governed action from the Review. The web ops route
// binds the approval by its id; on approval the P3 executor materializes the PDSA.
export async function postArenaApproveAction(
  approvalId: number,
  decision: 'approved' | 'rejected' = 'approved',
  reason?: string,
): Promise<unknown> {
  const res = await axios.post(`/api/ops/approvals/${approvalId}/decision`, { decision, reason });
  return res.data;
}

// --- 48-Hour Flow Review (persisted artifact; the /review endpoint lands in the
// backend loop phase — until then the FE renders a fixture, see reviewFixture.ts) ---

export async function fetchArenaReview(windowRef?: string): Promise<unknown> {
  const res = await axios.get('/api/arena/review', {
    params: { window: windowRef || undefined },
  });
  return res.data;
}

export async function runArenaReview(): Promise<unknown> {
  const res = await axios.post('/api/arena/review/run');
  return res.data;
}
