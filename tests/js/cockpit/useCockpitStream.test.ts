// tests/js/cockpit/useCockpitStream.test.ts
//
// P8 WS-6b — the SSE safety-floor path. Verifies the EventSource lifecycle:
// connect only when enabled, invalidate the live cockpit queries on a snapshot
// event, reconnect with capped backoff on a CLOSED error, and full teardown.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook } from '@testing-library/react';
import { createElement, type ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useCockpitStream } from '@/features/cockpit/useCockpitStream';

class MockEventSource {
  static readonly CONNECTING = 0;
  static readonly OPEN = 1;
  static readonly CLOSED = 2;
  static instances: MockEventSource[] = [];

  readyState = MockEventSource.CONNECTING;
  onopen: (() => void) | null = null;
  onerror: (() => void) | null = null;
  closed = false;
  private listeners: Record<string, Array<() => void>> = {};

  constructor(public url: string) {
    MockEventSource.instances.push(this);
  }

  addEventListener(type: string, cb: () => void): void {
    (this.listeners[type] ??= []).push(cb);
  }

  removeEventListener(type: string, cb: () => void): void {
    this.listeners[type] = (this.listeners[type] ?? []).filter((f) => f !== cb);
  }

  close(): void {
    this.closed = true;
    this.readyState = MockEventSource.CLOSED;
  }

  emit(type: string): void {
    (this.listeners[type] ?? []).forEach((f) => f());
  }

  fail(): void {
    this.readyState = MockEventSource.CLOSED;
    this.onerror?.();
  }
}

function renderStream(enabled: boolean) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const invalidate = vi.spyOn(client, 'invalidateQueries');
  const wrapper = ({ children }: { children: ReactNode }) =>
    createElement(QueryClientProvider, { client }, children);
  const view = renderHook(() => useCockpitStream(enabled), { wrapper });
  return { ...view, invalidate };
}

describe('useCockpitStream', () => {
  beforeEach(() => {
    MockEventSource.instances = [];
    vi.stubGlobal('EventSource', MockEventSource);
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it('does nothing when disabled', () => {
    renderStream(false);
    expect(MockEventSource.instances).toHaveLength(0);
  });

  it('opens the stream and invalidates cockpit queries on a snapshot event', () => {
    const { invalidate } = renderStream(true);
    expect(MockEventSource.instances).toHaveLength(1);
    expect(MockEventSource.instances[0].url).toBe('/api/cockpit/stream');

    MockEventSource.instances[0].emit('cockpit-snapshot');
    expect(invalidate).toHaveBeenCalledTimes(1);
  });

  it('does not churn the static scopes / kpi-definitions catalogs', () => {
    const { invalidate } = renderStream(true);
    MockEventSource.instances[0].emit('cockpit-snapshot');
    // The invalidation is predicate-based (not a broad ['cockpit'] key).
    const arg = invalidate.mock.calls[0][0] as { predicate?: (q: { queryKey: unknown[] }) => boolean };
    expect(typeof arg.predicate).toBe('function');
    expect(arg.predicate?.({ queryKey: ['cockpit', 'snapshot'] })).toBe(true);
    expect(arg.predicate?.({ queryKey: ['cockpit', 'scopes', null] })).toBe(false);
    expect(arg.predicate?.({ queryKey: ['cockpit', 'kpi-definitions'] })).toBe(false);
  });

  it('reconnects with backoff after a CLOSED error', () => {
    renderStream(true);
    expect(MockEventSource.instances).toHaveLength(1);

    MockEventSource.instances[0].fail(); // CLOSED → schedule reconnect (2s)
    vi.advanceTimersByTime(2000);
    expect(MockEventSource.instances).toHaveLength(2);
  });

  it('closes the source and cancels pending reconnects on unmount', () => {
    const { unmount } = renderStream(true);
    const source = MockEventSource.instances[0];

    source.fail(); // schedule a reconnect
    unmount();
    expect(source.closed).toBe(true);

    // A reconnect that was pending at unmount must NOT open a new stream.
    vi.advanceTimersByTime(10_000);
    expect(MockEventSource.instances).toHaveLength(1);
  });
});
