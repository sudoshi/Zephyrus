// tests/js/cockpit/useIdleReset.test.ts
//
// P8 WS-5 auto-timeout-to-glance: a wall mount fires onIdle after a run of
// inactivity, and any user activity resets the countdown. Disabled → never fires.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useIdleReset } from '@/features/cockpit/useIdleReset';

describe('useIdleReset', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('fires onIdle after the timeout when enabled', () => {
    const onIdle = vi.fn();
    renderHook(() => useIdleReset({ enabled: true, timeoutMs: 1000, onIdle }));

    expect(onIdle).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1000);
    expect(onIdle).toHaveBeenCalledTimes(1);
  });

  it('resets the countdown on user activity', () => {
    const onIdle = vi.fn();
    renderHook(() => useIdleReset({ enabled: true, timeoutMs: 1000, onIdle }));

    vi.advanceTimersByTime(800);
    window.dispatchEvent(new Event('pointermove'));
    vi.advanceTimersByTime(800); // 1600ms total, but only 800ms since the reset
    expect(onIdle).not.toHaveBeenCalled();
    vi.advanceTimersByTime(200); // now 1000ms since the reset
    expect(onIdle).toHaveBeenCalledTimes(1);
  });

  it('never fires when disabled', () => {
    const onIdle = vi.fn();
    renderHook(() => useIdleReset({ enabled: false, timeoutMs: 1000, onIdle }));

    vi.advanceTimersByTime(5000);
    expect(onIdle).not.toHaveBeenCalled();
  });
});
