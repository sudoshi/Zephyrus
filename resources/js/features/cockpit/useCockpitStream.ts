// resources/js/features/cockpit/useCockpitStream.ts
//
// P8 WS-6b — the SSE safety floor: the prod-safe live path that keeps the
// cockpit fresh even when Reverb is down (BROADCAST_CONNECTION=null, the
// WebSocket server crashed, or the wstunnel is misconfigured). It rides the
// authenticated web session over GET /api/cockpit/stream and needs no socket
// server. Deliberately echo-free — importing '@/lib/echo' constructs a socket
// at import time, so (like live.ts) this must never ride along into components
// or tests that only want the poll.
//
// This COMPLEMENTS, it does not replace: the Reverb ping (useLiveCockpit) is
// the fastest path when the socket is up, and the 45s TanStack poll
// (useCockpitSnapshot) is the always-on fallback. All three converge on the
// same lever — invalidate ['cockpit'] and let TanStack refetch over the
// session — so keep-last-good is automatic (a failed/absent refetch just keeps
// the last cached snapshot on screen).
import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';

/** Backoff ceiling — a wedged stream reconnects at most every ~30s. */
const MAX_BACKOFF_MS = 30_000;
/** First reconnect delay; doubles each consecutive failure up to the cap. */
const BASE_BACKOFF_MS = 2_000;

/**
 * Open an EventSource to /api/cockpit/stream and invalidate the cockpit queries
 * whenever a `cockpit-snapshot` event lands, mirroring how useLiveCockpit reacts
 * to the Reverb ping. EventSource auto-reconnects on transient drops; on a hard
 * error we close and re-open with capped exponential backoff (2s → 4s → 8s …
 * ≤30s), resetting once a connection opens or a message arrives. A no-op when
 * `enabled` is false or EventSource is unavailable (SSR / unsupported browser) —
 * the 45s poll remains the fallback, so this never throws.
 */
export function useCockpitStream(enabled = true): void {
  const qc = useQueryClient();

  useEffect(() => {
    if (!enabled) return;
    if (typeof window === 'undefined' || !('EventSource' in window)) return;

    let cancelled = false;
    let source: EventSource | null = null;
    let reconnectTimer: number | null = null;
    let backoff = BASE_BACKOFF_MS;

    const clearReconnect = () => {
      if (reconnectTimer !== null) {
        window.clearTimeout(reconnectTimer);
        reconnectTimer = null;
      }
    };

    const teardown = () => {
      clearReconnect();
      if (source !== null) {
        source.onopen = null;
        source.onerror = null;
        source.removeEventListener('cockpit-snapshot', onSnapshot);
        source.close();
        source = null;
      }
    };

    // A snapshot landed (or the stream just opened): the connection is healthy,
    // so reset backoff and let TanStack refetch over the session.
    const resetBackoff = () => {
      backoff = BASE_BACKOFF_MS;
    };

    const onSnapshot = () => {
      resetBackoff();
      // Refresh only the LIVE surfaces (snapshot / face / drill / patient), never
      // the near-static ['cockpit','scopes'] / ['cockpit','kpi-definitions'] catalogs
      // whose 60s staleTime a per-reconnect ping would otherwise defeat.
      qc.invalidateQueries({
        predicate: (q) =>
          q.queryKey[0] === 'cockpit' &&
          q.queryKey[1] !== 'scopes' &&
          q.queryKey[1] !== 'kpi-definitions',
      });
    };

    const scheduleReconnect = () => {
      // Only re-open on a genuinely dead stream; EventSource handles transient
      // drops itself (readyState CONNECTING). Debounce so a burst of errors
      // schedules exactly one reconnect.
      if (cancelled || reconnectTimer !== null) return;
      const delay = backoff;
      backoff = Math.min(backoff * 2, MAX_BACKOFF_MS);
      reconnectTimer = window.setTimeout(() => {
        reconnectTimer = null;
        if (cancelled) return;
        connect();
      }, delay);
    };

    const connect = () => {
      if (cancelled) return;
      teardown();
      source = new EventSource('/api/cockpit/stream');
      source.onopen = resetBackoff;
      source.addEventListener('cockpit-snapshot', onSnapshot);
      source.onerror = () => {
        // CLOSED means the browser gave up auto-reconnecting; back off and
        // re-open ourselves so a long outage doesn't hammer the endpoint.
        if (source !== null && source.readyState === EventSource.CLOSED) {
          scheduleReconnect();
        }
      };
    };

    connect();

    return () => {
      cancelled = true;
      teardown();
    };
  }, [enabled, qc]);
}
