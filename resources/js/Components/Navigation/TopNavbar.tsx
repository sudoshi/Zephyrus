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
import { MobileNavMenu } from './MobileNavMenu';

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
        <div className="mx-auto flex h-16 max-w-full items-center justify-between px-4">
          {/* Left: mobile hamburger + logo */}
          <div className="flex items-center gap-2">
            <MobileNavMenu isAdmin={isAdmin} url={url} />
            <Link href="/dashboard" className="flex items-center gap-2">
              <img src="/images/IconOnly_Transparent.png" alt="Zephyrus" className="h-9 w-auto" />
              <span className="text-xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Zephyrus
              </span>
            </Link>
          </div>

          {/* Center: desktop domain navigation */}
          <div className="hidden items-center gap-1 lg:flex">
            <Link
              href={TOP_LEVEL_DASHBOARD.href}
              aria-current={dashboardActive ? 'page' : undefined}
              className={`rounded-md px-3 py-2 text-sm font-medium transition-all duration-300 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark ${
                dashboardActive
                  ? 'bg-healthcare-hover text-healthcare-primary dark:bg-healthcare-hover-dark dark:text-healthcare-primary-dark'
                  : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
              }`}
            >
              {TOP_LEVEL_DASHBOARD.label}
            </Link>
            {domains.map((domain) => (
              <NavMegaMenu
                key={domain.key}
                domain={domain}
                isAdmin={isAdmin}
                active={isDomainActive(domain, url)}
              />
            ))}
          </div>

          {/* Right: search, dark mode, user */}
          <div className="flex items-center gap-2">
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
