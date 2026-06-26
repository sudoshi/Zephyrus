// resources/js/stores/commandCenterStore.ts
import { create } from 'zustand';

export type CommandRole = 'command' | 'executive' | 'service-line';

// Roles that actually re-level the surface today. 'service-line' is scoped but
// not yet functional, so it is never restored from a URL or persisted.
export const SELECTABLE_ROLES: CommandRole[] = ['command', 'executive'];

interface CommandCenterState {
  role: CommandRole;
  serviceLine: string | null;
  setRole: (role: CommandRole) => void;
  setServiceLine: (line: string | null) => void;
}

export function roleFromUrl(): CommandRole {
  if (typeof window === 'undefined') return 'command';
  const param = new URLSearchParams(window.location.search).get('role');
  return SELECTABLE_ROLES.includes(param as CommandRole) ? (param as CommandRole) : 'command';
}

// Reflect role into the query string so wall displays and shared deep links
// hold their view (?role=executive) instead of resetting to command on load.
// replaceState keeps it client-only — no server round-trip, no history spam.
function syncRoleToUrl(role: CommandRole): void {
  if (typeof window === 'undefined') return;
  const url = new URL(window.location.href);
  if (role === 'command') url.searchParams.delete('role');
  else url.searchParams.set('role', role);
  window.history.replaceState(window.history.state, '', url);
}

export const useCommandCenterStore = create<CommandCenterState>((set) => ({
  role: roleFromUrl(),
  serviceLine: null,
  setRole: (role) => {
    syncRoleToUrl(role);
    set({ role });
  },
  setServiceLine: (serviceLine) => set({ serviceLine }),
}));
