import { type Dispatch, type SetStateAction } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import DarkModeToggle from '@/Components/Common/DarkModeToggle';
import { CommandPalette } from '@/components/ui/CommandPalette';
import { useUIStore } from '@/stores/uiStore';
import { isSectionActive, visibleSections, type NavigationAccess } from '@/config/navigationConfig';
import type { PageProps } from '@/types';
import { CockpitMenu } from './CockpitMenu';
import { MobileNavDrawer } from './MobileNavDrawer';
import { NavSectionMenu } from './NavSectionMenu';
import { UserMenu } from './UserMenu';

interface TopNavbarProps {
  isDarkMode: boolean;
  setIsDarkMode: Dispatch<SetStateAction<boolean>>;
}

export function TopNavbar({ isDarkMode, setIsDarkMode }: TopNavbarProps) {
  const page = usePage<PageProps>();
  const url = page.url ?? '';
  const isAdmin = Boolean(page.props.auth?.is_admin);
  const access: NavigationAccess = { isAdmin, can: page.props.auth?.can, features: page.props.features };
  const sections = visibleSections(access);
  const centeredSections = sections.filter((section) => ['cockpit', 'workspaces', 'study'].includes(section.key));
  const utilitySections = sections.filter((section) => !['cockpit', 'workspaces', 'study'].includes(section.key));
  const scopeToken = new URLSearchParams(url.split('?')[1] ?? '').get('scope');
  const setCommandPaletteOpen = useUIStore((s) => s.setCommandPaletteOpen);

  return (
    <>
      <nav
        aria-label="Primary"
        className="sticky top-0 z-[65] border-b border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
      >
        <div className="mx-auto flex h-[var(--topbar-height)] max-w-full items-center gap-3 px-4">
          <MobileNavDrawer sections={sections} access={access} url={url} />

          <Link
            href="/dashboard"
            className="flex flex-shrink-0 items-center gap-2 rounded-md px-2 py-1 transition-all duration-300 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark"
          >
            <img
              src="/images/zephyrus-icon.png"
              alt=""
              aria-hidden="true"
              className="h-7 w-7 object-contain"
            />
            <span className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Zephyrus
            </span>
          </Link>

          <div className="absolute left-1/2 hidden -translate-x-1/2 items-center gap-1 lg:flex">
            {centeredSections.map((section) => {
              const active = isSectionActive(section, url);
              const Icon = section.icon;
              const direct = section.homeHref && section.domains.length <= 1;

              if (section.key === 'cockpit') {
                return <CockpitMenu key={section.key} active={active} scopeToken={scopeToken} />;
              }

              if (direct) {
                return (
                  <Link
                    key={section.key}
                    href={section.homeHref as string}
                    aria-current={active ? 'page' : undefined}
                    className={`flex items-center gap-1.5 whitespace-nowrap rounded-md border border-transparent px-3 py-1.5 text-sm font-medium transition-colors hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark ${
                      active
                        ? 'bg-healthcare-hover text-healthcare-primary dark:bg-healthcare-hover-dark dark:text-healthcare-text-primary-dark'
                        : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                    }`}
                  >
                    <Icon className="h-4 w-4" aria-hidden="true" />
                    <span>{section.title}</span>
                  </Link>
                );
              }

              return (
                <NavSectionMenu
                  key={section.key}
                  section={section}
                  access={access}
                  url={url}
                  active={active}
                />
              );
            })}
          </div>

          <div className="ml-auto flex flex-shrink-0 items-center gap-1 sm:gap-2">
            {utilitySections.map((section) => {
              const active = isSectionActive(section, url);
              const Icon = section.icon;
              return section.homeHref ? (
                <Link
                  key={section.key}
                  href={section.homeHref}
                  aria-current={active ? 'page' : undefined}
                  className={`hidden items-center gap-1.5 rounded-md border border-transparent px-3 py-1.5 text-sm font-medium transition-colors hover:bg-healthcare-hover xl:flex dark:hover:bg-healthcare-hover-dark ${
                    active
                      ? 'bg-healthcare-hover text-healthcare-primary dark:bg-healthcare-hover-dark dark:text-healthcare-text-primary-dark'
                      : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                  }`}
                >
                  <Icon className="h-4 w-4" aria-hidden="true" />
                  <span>{section.title}</span>
                </Link>
              ) : null;
            })}
            <button
              type="button"
              aria-label="Search (Cmd+K)"
              onClick={() => setCommandPaletteOpen(true)}
              className="rounded-md border border-transparent p-2 text-healthcare-text-secondary transition-all duration-300 hover:border-healthcare-border hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark"
            >
              <Search className="h-5 w-5" />
            </button>
            <DarkModeToggle isDarkMode={isDarkMode} onToggle={() => setIsDarkMode(!isDarkMode)} />
            <UserMenu access={access} />
          </div>
        </div>
      </nav>
      <CommandPalette />
    </>
  );
}
