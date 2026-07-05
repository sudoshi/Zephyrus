// resources/js/features/cockpit/useIdleReset.ts
//
// Zephyrus 2.0 P8 — auto-timeout-to-glance. On a wall/kiosk mount, any drill or
// interaction should fall back to the A0 house-scope glance after a period of
// inactivity, so an unattended display never sits on a drilled-in (possibly
// PHI-bearing) view. Attaches passive activity listeners while `enabled`; after
// `timeoutMs` of silence it fires `onIdle` (the caller navigates to A0).
import { useEffect, useRef } from 'react';

export interface UseIdleResetOptions {
  enabled: boolean;
  timeoutMs: number;
  onIdle: () => void;
}

const ACTIVITY_EVENTS = [
  'pointerdown',
  'pointermove',
  'keydown',
  'touchstart',
  'wheel',
] as const;

export function useIdleReset({ enabled, timeoutMs, onIdle }: UseIdleResetOptions): void {
  // Keep the latest onIdle in a ref so the subscribe/unsubscribe effect does not
  // re-run (and re-attach listeners) on every render, while never firing a stale
  // closure.
  const onIdleRef = useRef(onIdle);
  onIdleRef.current = onIdle;

  useEffect(() => {
    if (!enabled || typeof window === 'undefined') {
      return;
    }

    let timerId: number | undefined;

    const reset = () => {
      if (timerId !== undefined) {
        window.clearTimeout(timerId);
      }
      timerId = window.setTimeout(() => onIdleRef.current(), timeoutMs);
    };

    for (const eventName of ACTIVITY_EVENTS) {
      window.addEventListener(eventName, reset, { passive: true });
    }

    // Arm the timer immediately so an unattended mount still resets even with no
    // interaction.
    reset();

    return () => {
      if (timerId !== undefined) {
        window.clearTimeout(timerId);
      }
      for (const eventName of ACTIVITY_EVENTS) {
        window.removeEventListener(eventName, reset);
      }
    };
  }, [enabled, timeoutMs]);
}
