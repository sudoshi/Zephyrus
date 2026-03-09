import { create } from 'zustand';

interface ThemeState {
  darkMode: boolean;
  toggleDarkMode: () => void;
  setDarkMode: (dark: boolean) => void;
}

export const useThemeStore = create<ThemeState>((set) => ({
  darkMode: localStorage.getItem('darkMode') !== 'false',
  toggleDarkMode: () => set((s) => {
    const next = !s.darkMode;
    localStorage.setItem('darkMode', String(next));
    document.documentElement.classList.toggle('dark', next);
    return { darkMode: next };
  }),
  setDarkMode: (dark) => {
    localStorage.setItem('darkMode', String(dark));
    document.documentElement.classList.toggle('dark', dark);
    set({ darkMode: dark });
  },
}));
