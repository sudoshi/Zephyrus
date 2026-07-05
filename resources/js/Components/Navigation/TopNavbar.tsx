import { Fragment, type Dispatch, type SetStateAction } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Gauge, Search } from 'lucide-react';
import DarkModeToggle from '@/Components/Common/DarkModeToggle';
import { CommandPalette } from '@/components/ui/CommandPalette';
import { RoleSwitcher } from '@/Components/CommandCenter/RoleSwitcher';
import { useUIStore } from '@/stores/uiStore';
import { isDomainActive, visibleSections } from '@/config/navigationConfig';
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
  const path = url.split('?')[0].split('#')[0];
  const isAdmin = Boolean(page.props.auth?.is_admin);
  const role = page.props.auth?.user?.role ?? null;
  const sections = visibleSections(isAdmin, role);
  const setCommandPaletteOpen = useUIStore((s) => s.setCommandPaletteOpen);
  const cockpitActive = path === '/dashboard' || path === '/';

  return (
    <>
      <nav className="sticky top-0 z-[65] border-b border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="mx-auto flex h-[var(--topbar-height)] max-w-full items-center gap-3 px-4">
          {/* Brand */}
          <Link
            href="/dashboard"
            className="flex flex-shrink-0 items-center gap-2 rounded-md px-2 py-1 transition-all duration-300 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark"
          >
            <span className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Zephyrus
            </span>
          </Link>

          {/* The four altitude sections (P4a) — always on the top bar at every
              width. Scrolls horizontally when space is tight so it never pushes
              the right-side controls off-screen; the dropdown panels are
              anchored/portaled in NavMegaMenu so they are not clipped by this
              scroll container. Section micro-labels appear at xl+. */}
          <div
            className="flex min-w-0 flex-1 items-center overflow-x-auto [&::-webkit-scrollbar]:hidden"
            style={{ scrollbarWidth: 'none' }}
          >
            {/* mx-auto centers the nav group when it fits; collapses to 0 (left-aligned,
                scrollable) when the items overflow on narrow widths. */}
            <div className="mx-auto flex items-center gap-1">
              {sections.map((section, index) => (
                <Fragment key={section.key}>
                  {index > 0 && (
                    <div
                      aria-hidden="true"
                      className="mx-1.5 h-4 w-px flex-shrink-0 bg-healthcare-border dark:bg-healthcare-border-dark"
                    />
                  )}
                  <div role="group" aria-label={section.title} className="flex items-center gap-1">
                    {section.homeHref ? (
                      <Link
                        href={section.homeHref}
                        aria-current={cockpitActive ? 'page' : undefined}
                        className={`flex items-center gap-1.5 whitespace-nowrap rounded-md border border-transparent px-3 py-1.5 text-base font-medium transition-all duration-300 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark ${
                          cockpitActive
                            ? 'bg-healthcare-hover text-healthcare-primary dark:bg-healthcare-hover-dark dark:text-healthcare-primary-dark'
                            : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                        }`}
                      >
                        <Gauge className="h-4 w-4" />
                        <span>{section.title}</span>
                      </Link>
                    ) : (
                      <>
                        <span className="hidden select-none whitespace-nowrap pl-1 pr-0.5 text-xs font-medium uppercase tracking-wider text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark xl:block">
                          {section.title}
                        </span>
                        {section.domains.map((domain) => (
                          <NavMegaMenu
                            key={domain.key}
                            domain={domain}
                            isAdmin={isAdmin}
                            role={role}
                            active={isDomainActive(domain, url)}
                          />
                        ))}
                      </>
                    )}
                  </div>
                </Fragment>
              ))}
            </div>
          </div>

          {/* Right: persona, search, dark mode, user. The RoleSwitcher is
              persistent app chrome (P4a) — persona changes never change the
              route; the store mirrors ?role= so shared links reproduce the
              view. Hidden on narrow widths (mobile personas live in
              Hummingbird). */}
          <div className="flex flex-shrink-0 items-center gap-2">
            <div className="hidden md:block">
              <RoleSwitcher />
            </div>
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
