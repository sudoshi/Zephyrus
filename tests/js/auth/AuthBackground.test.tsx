import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, act } from '@testing-library/react';
import { AuthBackground } from '@/Components/Auth/AuthBackground';

function setReducedMotion(matches: boolean) {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches, media: query, onchange: null,
    addListener: vi.fn(), removeListener: vi.fn(),
    addEventListener: vi.fn(), removeEventListener: vi.fn(), dispatchEvent: vi.fn(),
  })) as unknown as typeof window.matchMedia;
}

describe('AuthBackground', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('advances the active slide on the interval', () => {
    setReducedMotion(false);
    const { container } = render(<AuthBackground />);
    const root = container.firstChild as HTMLElement;
    expect(root.getAttribute('data-active-index')).toBe('0');
    act(() => { vi.advanceTimersByTime(8000); });
    expect(root.getAttribute('data-active-index')).toBe('1');
  });

  it('does not advance when reduced motion is preferred', () => {
    setReducedMotion(true);
    const { container } = render(<AuthBackground />);
    const root = container.firstChild as HTMLElement;
    act(() => { vi.advanceTimersByTime(24000); });
    expect(root.getAttribute('data-active-index')).toBe('0');
  });
});
