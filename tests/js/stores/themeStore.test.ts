import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useThemeStore } from '@/stores/themeStore';

describe('themeStore', () => {
  beforeEach(() => {
    // Clear localStorage mock
    vi.mocked(localStorage.getItem).mockReturnValue(null);
    vi.mocked(localStorage.setItem).mockClear();

    // Reset classList mock
    document.documentElement.classList.toggle = vi.fn();

    // Reset the store
    useThemeStore.setState({ darkMode: true });
  });

  describe('initial state', () => {
    it('defaults to dark mode', () => {
      expect(useThemeStore.getState().darkMode).toBe(true);
    });
  });

  describe('toggleDarkMode', () => {
    it('switches from dark to light mode', () => {
      useThemeStore.getState().toggleDarkMode();

      expect(useThemeStore.getState().darkMode).toBe(false);
    });

    it('switches from light to dark mode', () => {
      useThemeStore.setState({ darkMode: false });
      useThemeStore.getState().toggleDarkMode();

      expect(useThemeStore.getState().darkMode).toBe(true);
    });

    it('persists to localStorage', () => {
      useThemeStore.getState().toggleDarkMode();

      expect(localStorage.setItem).toHaveBeenCalledWith('darkMode', 'false');
    });

    it('updates document classList', () => {
      useThemeStore.getState().toggleDarkMode();

      expect(document.documentElement.classList.toggle).toHaveBeenCalledWith(
        'dark',
        false
      );
    });
  });

  describe('setDarkMode', () => {
    it('sets dark mode to true', () => {
      useThemeStore.setState({ darkMode: false });
      useThemeStore.getState().setDarkMode(true);

      expect(useThemeStore.getState().darkMode).toBe(true);
    });

    it('sets dark mode to false', () => {
      useThemeStore.getState().setDarkMode(false);

      expect(useThemeStore.getState().darkMode).toBe(false);
    });

    it('persists to localStorage when enabling', () => {
      useThemeStore.getState().setDarkMode(true);

      expect(localStorage.setItem).toHaveBeenCalledWith('darkMode', 'true');
    });

    it('persists to localStorage when disabling', () => {
      useThemeStore.getState().setDarkMode(false);

      expect(localStorage.setItem).toHaveBeenCalledWith('darkMode', 'false');
    });

    it('updates document classList when enabling', () => {
      useThemeStore.getState().setDarkMode(true);

      expect(document.documentElement.classList.toggle).toHaveBeenCalledWith(
        'dark',
        true
      );
    });

    it('updates document classList when disabling', () => {
      useThemeStore.getState().setDarkMode(false);

      expect(document.documentElement.classList.toggle).toHaveBeenCalledWith(
        'dark',
        false
      );
    });
  });
});
