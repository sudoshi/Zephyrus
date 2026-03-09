import { create } from 'zustand';

interface UIState {
  sidebarOpen: boolean;
  commandPaletteOpen: boolean;
  mobileDrawerOpen: boolean;
  toggleSidebar: () => void;
  setCommandPaletteOpen: (open: boolean) => void;
  setMobileDrawerOpen: (open: boolean) => void;
}

export const useUIStore = create<UIState>((set) => ({
  sidebarOpen: true,
  commandPaletteOpen: false,
  mobileDrawerOpen: false,
  toggleSidebar: () => set((s) => ({ sidebarOpen: !s.sidebarOpen })),
  setCommandPaletteOpen: (open) => set({ commandPaletteOpen: open }),
  setMobileDrawerOpen: (open) => set({ mobileDrawerOpen: open }),
}));
