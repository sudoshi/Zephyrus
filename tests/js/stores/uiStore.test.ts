import { describe, it, expect, beforeEach } from 'vitest';
import { useUIStore } from '@/stores/uiStore';

describe('uiStore', () => {
  beforeEach(() => {
    // Reset the store between tests
    useUIStore.setState({
      commandPaletteOpen: false,
    });
  });

  describe('initial state', () => {
    it('starts with command palette closed', () => {
      expect(useUIStore.getState().commandPaletteOpen).toBe(false);
    });
  });

  describe('setCommandPaletteOpen', () => {
    it('opens command palette', () => {
      useUIStore.getState().setCommandPaletteOpen(true);

      expect(useUIStore.getState().commandPaletteOpen).toBe(true);
    });

    it('closes command palette', () => {
      useUIStore.getState().setCommandPaletteOpen(true);
      useUIStore.getState().setCommandPaletteOpen(false);

      expect(useUIStore.getState().commandPaletteOpen).toBe(false);
    });
  });
});
