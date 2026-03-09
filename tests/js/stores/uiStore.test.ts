import { describe, it, expect, beforeEach } from 'vitest';
import { useUIStore } from '@/stores/uiStore';

describe('uiStore', () => {
  beforeEach(() => {
    // Reset the store between tests
    useUIStore.setState({
      sidebarOpen: true,
      commandPaletteOpen: false,
      mobileDrawerOpen: false,
    });
  });

  describe('initial state', () => {
    it('starts with sidebar open', () => {
      expect(useUIStore.getState().sidebarOpen).toBe(true);
    });

    it('starts with command palette closed', () => {
      expect(useUIStore.getState().commandPaletteOpen).toBe(false);
    });

    it('starts with mobile drawer closed', () => {
      expect(useUIStore.getState().mobileDrawerOpen).toBe(false);
    });
  });

  describe('toggleSidebar', () => {
    it('closes sidebar when open', () => {
      useUIStore.getState().toggleSidebar();

      expect(useUIStore.getState().sidebarOpen).toBe(false);
    });

    it('opens sidebar when closed', () => {
      useUIStore.getState().toggleSidebar(); // close
      useUIStore.getState().toggleSidebar(); // open

      expect(useUIStore.getState().sidebarOpen).toBe(true);
    });

    it('toggles multiple times correctly', () => {
      const initial = useUIStore.getState().sidebarOpen;

      useUIStore.getState().toggleSidebar();
      expect(useUIStore.getState().sidebarOpen).toBe(!initial);

      useUIStore.getState().toggleSidebar();
      expect(useUIStore.getState().sidebarOpen).toBe(initial);

      useUIStore.getState().toggleSidebar();
      expect(useUIStore.getState().sidebarOpen).toBe(!initial);
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

    it('does not affect other state', () => {
      useUIStore.getState().setCommandPaletteOpen(true);

      expect(useUIStore.getState().sidebarOpen).toBe(true);
      expect(useUIStore.getState().mobileDrawerOpen).toBe(false);
    });
  });

  describe('setMobileDrawerOpen', () => {
    it('opens mobile drawer', () => {
      useUIStore.getState().setMobileDrawerOpen(true);

      expect(useUIStore.getState().mobileDrawerOpen).toBe(true);
    });

    it('closes mobile drawer', () => {
      useUIStore.getState().setMobileDrawerOpen(true);
      useUIStore.getState().setMobileDrawerOpen(false);

      expect(useUIStore.getState().mobileDrawerOpen).toBe(false);
    });

    it('does not affect other state', () => {
      useUIStore.getState().setMobileDrawerOpen(true);

      expect(useUIStore.getState().sidebarOpen).toBe(true);
      expect(useUIStore.getState().commandPaletteOpen).toBe(false);
    });
  });
});
