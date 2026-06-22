// resources/js/stores/commandCenterStore.ts
import { create } from 'zustand';

export type CommandRole = 'command' | 'executive' | 'service-line';

interface CommandCenterState {
  role: CommandRole;
  serviceLine: string | null;
  setRole: (role: CommandRole) => void;
  setServiceLine: (line: string | null) => void;
}

export const useCommandCenterStore = create<CommandCenterState>((set) => ({
  role: 'command',
  serviceLine: null,
  setRole: (role) => set({ role }),
  setServiceLine: (serviceLine) => set({ serviceLine }),
}));
