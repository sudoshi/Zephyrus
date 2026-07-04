// resources/js/Contexts/DarkModeContext.tsx
//
// P4b: the shell-owned dark-mode context. Extracted from AuthenticatedLayout
// so components rendered under the unified DashboardLayout shell read real
// theme state — before this, any consumer mounted outside AuthenticatedLayout
// (Flowbite/Nivo theme providers, Process components) silently fell back to a
// provider-less default and rendered light-mode styles on a dark app.
// AuthenticatedLayout keeps its own legacy provider untouched (protected file,
// .claude/rules/auth-system.md); DashboardLayout provides this one.
import { createContext, useContext } from 'react';
import type { Dispatch, SetStateAction } from 'react';

interface DarkModeContextValue {
  isDarkMode: boolean;
  setIsDarkMode: Dispatch<SetStateAction<boolean>>;
}

// Provider-less fallback assumes dark: the app is dark-default (the
// useDarkMode hook initializes to dark even when the OS prefers light).
export const DarkModeContext = createContext<DarkModeContextValue>({
  isDarkMode: true,
  setIsDarkMode: () => {},
});

export const useDarkMode = (): DarkModeContextValue => useContext(DarkModeContext);
