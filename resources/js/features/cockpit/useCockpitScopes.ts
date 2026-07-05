// resources/js/features/cockpit/useCockpitScopes.ts
//
// P8 WS-5 — the mount catalog stream for the SCOPE PICKER. Keyed by the active
// mount token so switching mounts re-serves the catalog with the correct
// `active` selection from cache. The catalog is near-static per session, so a
// 60s staleTime avoids refetching on every picker mount. Data stays `unknown`
// end to end; parsing happens in the picker via safeParseCockpitScopes.
import { useQuery } from '@tanstack/react-query';
import { fetchCockpitScopes } from './scopeApi';

export function useCockpitScopes(activeScope: string | null) {
  return useQuery<unknown>({
    queryKey: ['cockpit', 'scopes', activeScope],
    queryFn: () => fetchCockpitScopes(activeScope ?? undefined),
    staleTime: 60_000,
  });
}
