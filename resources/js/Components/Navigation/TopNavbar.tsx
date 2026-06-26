import type { Dispatch, SetStateAction } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import DarkModeToggle from '@/Components/Common/DarkModeToggle';
import { CommandPalette } from '@/components/ui/CommandPalette';
import { useUIStore } from '@/stores/uiStore';
import {
  TOP_LEVEL_DASHBOARD,
  isDomainActive,
  visibleDomains,
} from '@/config/navigationConfig';
import type { PageProps } from '@/types';
import { NavMegaMenu } from './NavMegaMenu';
import { UserMenu } from './UserMenu';

interface TopNavbarProps {
  isDarkMode: boolean;
  setIsDarkMode: Dispatch<SetStateAction<boolean>>;
}

export function TopNavbar({ isDarkMode, setIsDarkMode }: TopNavbarProps) {
  const page = usePage<PageProps>();
  const url = page.url ?? '';
  const isAdmin = Boolean(page.props.auth?.is_admin);
  const domains = visibleDomains(isAdmin);
  const setCommandPaletteOpen = useUIStore((s) => s.setCommandPaletteOpen);
  const dashboardActive = url === TOP_LEVEL_DASHBOARD.href || url === '/';

  return (
    <>
      <nav className="sticky top-0 z-[65] border-b border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="mx-auto flex h-[var(--topbar-height)] max-w-full items-center gap-3 px-4">
          {/* Logo / dashboard home */}
          <Link
            href={TOP_LEVEL_DASHBOARD.href}
            aria-current={dashboardActive ? 'page' : undefined}
            className={`flex flex-shrink-0 items-center gap-2 rounded-md px-2 py-1 transition-all duration-300 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark ${
              dashboardActive ? 'bg-healthcare-hover dark:bg-healthcare-hover-dark' : ''
            }`}
          >
            <img src="/images/zephyrus-icon.png" alt="" aria-hidden="true" className="h-8 w-8 rounded-lg object-contain" />
            <span className="text-lg/[24px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Zephyrus
            </span>
          </Link>

          {/* Domain navigation — always on the top bar at every width. Scrolls
              horizontally when space is tight so it never pushes the right-side
              controls off-screen; the dropdown panels are anchored/portaled in
              NavMegaMenu so they are not clipped by this scroll container. */}
          <div
            className="flex min-w-0 flex-1 items-center overflow-x-auto [&::-webkit-scrollbar]:hidden"
            style={{ scrollbarWidth: 'none' }}
          >
            {/* mx-auto centers the nav group when it fits; collapses to 0 (left-aligned,
                scrollable) when the items overflow on narrow widths. */}
            <div className="mx-auto flex items-center gap-1">
              {domains.map((domain) => (
                <NavMegaMenu
                  key={domain.key}
                  domain={domain}
                  isAdmin={isAdmin}
                  active={isDomainActive(domain, url)}
                />
              ))}
            </div>
          </div>

          {/* Right: search, dark mode, user */}
          <div className="flex flex-shrink-0 items-center gap-2">
            <button
              type="button"
              aria-label="Search (Cmd+K)"
              onClick={() => setCommandPaletteOpen(true)}
              className="rounded-md border border-transparent p-2 text-healthcare-text-secondary transition-all duration-300 hover:border-healthcare-border hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark"
            >
              <Search className="h-5 w-5" />
            </button>
            <DarkModeToggle isDarkMode={isDarkMode} onToggle={() => setIsDarkMode(!isDarkMode)} />
            <UserMenu isAdmin={isAdmin} />
          </div>
        </div>
      </nav>
      <CommandPalette />
    </>
  );
}
