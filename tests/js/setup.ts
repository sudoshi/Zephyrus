import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, vi } from 'vitest';

// Iconify's browser loader schedules remote icon resolution after render. In
// jsdom that work can outlive a test environment and dispatch React state
// after `window` has been torn down. Tests exercise our labels and controls,
// not Iconify's network/cache implementation, so keep the visual seam inert
// and deterministic while preserving icon metadata in the DOM.
vi.mock('@iconify/react', async () => {
  const { createElement } = await import('react');

  return {
    Icon: ({ icon, ...props }: { icon: unknown; [key: string]: unknown }) => createElement('span', {
      ...props,
      'data-icon': typeof icon === 'string' ? icon : 'inline-icon',
    }),
  };
});

// Cleanup after each test
afterEach(() => {
  cleanup();
});

// Mock localStorage
const localStorageMock = (() => {
  let store: Record<string, string> = {};
  return {
    getItem: vi.fn((key: string) => store[key] ?? null),
    setItem: vi.fn((key: string, value: string) => {
      store[key] = value;
    }),
    removeItem: vi.fn((key: string) => {
      delete store[key];
    }),
    clear: vi.fn(() => {
      store = {};
    }),
    get length() {
      return Object.keys(store).length;
    },
    key: vi.fn((index: number) => Object.keys(store)[index] ?? null),
  };
})();

Object.defineProperty(window, 'localStorage', { value: localStorageMock });

// Mock matchMedia
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
});

// Mock Inertia router
vi.mock('@inertiajs/react', () => ({
  router: {
    visit: vi.fn(),
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
  },
  usePage: vi.fn(() => ({
    props: {
      auth: { user: null },
      flash: {},
    },
  })),
  Link: ({ children, ...props }: any) => {
    const { createElement } = require('react');
    return createElement('a', props, children);
  },
  Head: ({ children }: any) => null,
}));

// Mock ResizeObserver — jsdom doesn't implement it, but Headless UI's floating
// (`anchor`) Popover panels reference it at mount. Real browsers have it.
global.ResizeObserver = class ResizeObserver {
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
};
