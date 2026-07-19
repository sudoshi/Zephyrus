// F-1 ruling (2026-07-19): the RoleSwitcher and the server flow lens must be
// ONE canonical persona state on the navigator page. The switcher's two live
// roles map onto flow personas (command → the viewer's default/house lens,
// executive → the aggregate executive lens); a switch performs a full server
// transition through EnforceFlowLens via ?persona=, never a client-only
// relabel that leaves the scene on another persona's redaction.
import type { CommandRole } from '@/stores/commandCenterStore';

/** The switcher role implied by the server-resolved lens. */
export function commandRoleForLens(lensRole: string | null | undefined): CommandRole {
  return lensRole === 'executive' ? 'executive' : 'command';
}

/** The navigator URL that performs the server persona transition. */
export function navigatorUrlForRole(href: string, role: CommandRole): string {
  const url = new URL(href);
  if (role === 'executive') url.searchParams.set('persona', 'executive');
  else url.searchParams.delete('persona');
  // The client-only ?role= reflection is superseded by the server transition.
  url.searchParams.delete('role');
  return `${url.pathname}${url.search}`;
}
