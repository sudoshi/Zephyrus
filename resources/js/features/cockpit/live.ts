// resources/js/features/cockpit/live.ts
//
// P6 WS-7 — the Reverb reload ping, in its OWN module: importing '@/lib/echo'
// constructs the socket at import time, so it must never ride along with the
// pure poll hooks (hooks.ts) into components/tests that only want queries.
import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { echo } from '@/lib/echo';

/**
 * `hospital.cockpit` carries a PHI-free {facility_key, generated_at} ping
 * when RefreshCockpitSnapshot lands a new snapshot; we invalidate the cockpit
 * queries and let TanStack refetch over the authenticated session (mirrors
 * useLiveCensus, including snapshot-on-reconnect — Reverb never replays
 * missed messages). The 45s poll stays as the fallback when the socket is
 * down.
 */
export function useLiveCockpit() {
  const qc = useQueryClient();

  useEffect(() => {
    // Refresh only the LIVE surfaces on a snapshot ping — snapshot / face / drill
    // / patient. The near-static catalogs (['cockpit','scopes'], ['cockpit',
    // 'kpi-definitions']) are deliberately excluded so a per-minute ping doesn't
    // defeat their 60s staleTime with a refetch storm.
    const refetch = () =>
      qc.invalidateQueries({
        predicate: (q) =>
          q.queryKey[0] === 'cockpit' &&
          q.queryKey[1] !== 'scopes' &&
          q.queryKey[1] !== 'kpi-definitions',
      });

    echo.channel('hospital.cockpit').listen('.cockpit.updated', refetch);
    echo.connector.pusher.connection.bind('connected', refetch);

    return () => {
      echo.leaveChannel('hospital.cockpit');
      echo.connector.pusher.connection.unbind('connected', refetch);
    };
  }, [qc]);
}
