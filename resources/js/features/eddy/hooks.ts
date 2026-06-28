import { useMutation } from '@tanstack/react-query';
import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import type { PageProps } from '@/types';
import { sendEddyChat } from './api';

export interface EddyContext {
  surface: string;      // one of the backend SURFACES; normalized server-side
  pageContext: string;  // the Inertia component name (machine id)
  route: string;
  roles: string[];
}

/** Map the current route to a backend surface. Unknown → 'chat' (server re-normalizes). */
function surfaceFromUrl(url: string): string {
  const path = url.split('?')[0];
  if (path.startsWith('/rtdc')) return 'rtdc';
  if (path.startsWith('/perioperative') || path.startsWith('/periop')) return 'periop';
  if (path.startsWith('/transport')) return 'transport';
  if (path.startsWith('/evs')) return 'evs';
  if (path.startsWith('/staffing')) return 'staffing';
  if (path.startsWith('/improvement')) return 'improvement';
  if (path.startsWith('/ed')) return 'ed';
  if (path.startsWith('/dashboard') || path === '/') return 'command_center';
  return 'chat';
}

/** Capture what the operator is currently viewing, Inertia-native. */
export function useEddyContext(): EddyContext {
  const page = usePage<PageProps>();
  return useMemo(
    () => ({
      surface: surfaceFromUrl(page.url),
      pageContext: page.component,
      route: page.url,
      roles: page.props.auth?.roles ?? page.props.auth?.user?.roles ?? [],
    }),
    [page.url, page.component, page.props.auth],
  );
}

export function useEddyChat() {
  return useMutation({ mutationFn: sendEddyChat });
}
