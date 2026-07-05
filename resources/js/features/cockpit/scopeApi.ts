// resources/js/features/cockpit/scopeApi.ts
//
// P8 WS-5 — the mount catalog behind the SCOPE PICKER. Returns the raw payload
// as unknown; the picker parses it defensively with safeParseCockpitScopes and
// fails quiet on any miss (the catalog is chrome, not safety-critical). The
// optional `scope` param lets the server resolve `active` against the caller's
// current mount so the picker's selection matches the surface on screen.
import axios from 'axios';

export async function fetchCockpitScopes(scope?: string): Promise<unknown> {
  const res = await axios.get('/api/cockpit/scopes', scope ? { params: { scope } } : undefined);
  return res.data;
}
