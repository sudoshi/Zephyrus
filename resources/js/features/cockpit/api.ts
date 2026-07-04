// resources/js/features/cockpit/api.ts
//
// Zephyrus 2.0 P2 — the cockpit snapshot refresh path. Returns the raw payload
// as unknown: the page parses it defensively TWICE (legacy contract + cockpit
// sections) and degrades per-parse, so a partial contract break can never
// white-screen the wall. ETag/304 is handled by the browser HTTP cache — the
// endpoint sends ETag + Cache-Control: no-cache, so XHR revalidates and a 304
// surfaces here as the cached 200 body.
import axios from 'axios';

export async function fetchCockpitSnapshot(): Promise<unknown> {
  const res = await axios.get('/api/cockpit/snapshot');
  return res.data;
}

// P3 — the per-domain A2 drill payload (spec §3.3). Same unknown-out
// discipline: DrillModal parses with drillPayloadSchema and degrades to an
// in-modal error card, never a crash over the cockpit.
export async function fetchCockpitDrill(domain: string): Promise<unknown> {
  const res = await axios.get(`/api/cockpit/drill/${encodeURIComponent(domain)}`);
  return res.data;
}
