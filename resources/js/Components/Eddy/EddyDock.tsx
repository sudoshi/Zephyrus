import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import { useEddyStore } from '@/stores/eddyStore';
import { EddyLauncher } from './EddyLauncher';
import { EddySlideOver } from './EddySlideOver';

function isWallDisplay(): boolean {
  if (typeof window === 'undefined') return false;
  return new URLSearchParams(window.location.search).get('display') === 'wall';
}

/**
 * The single, global Eddy mount — rendered once in Providers, a sibling overlay
 * next to ToastProvider. Suppressed on guest/auth surfaces and while a forced
 * password change owns the screen (the ChangePasswordModal owns those).
 * Ctrl+Shift+E toggles the dock anywhere.
 */
export function EddyDock() {
  const { props } = usePage<PageProps>();
  const user = props.auth?.user ?? null;
  const enabled = props.eddy?.enabled ?? false;
  const wall = isWallDisplay();
  const isOpen = useEddyStore((state) => state.isOpen);
  const toggle = useEddyStore((state) => state.toggle);

  useEffect(() => {
    if (!enabled || wall) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.ctrlKey && event.shiftKey && (event.key === 'E' || event.key === 'e')) {
        event.preventDefault();
        toggle();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [toggle, enabled, wall]);

  // Eddy ships disabled: no dock until EDDY_ENABLED=true. It is also absent
  // from the static wall presentation, guest/auth surfaces, and the forced
  // password-change gate.
  if (!enabled || wall || !user || user.must_change_password) return null;

  return isOpen ? <EddySlideOver /> : <EddyLauncher />;
}
