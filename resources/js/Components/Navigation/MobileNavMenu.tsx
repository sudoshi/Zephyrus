import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Menu as MenuIcon, X, ChevronDown } from 'lucide-react';
import { TOP_LEVEL_DASHBOARD, visibleDomains, isDomainActive } from '@/config/navigationConfig';
import type { NavDomain } from '@/config/navigationConfig';

interface MobileNavMenuProps {
  isAdmin: boolean;
  url: string;
}

export function MobileNavMenu({ isAdmin, url }: MobileNavMenuProps) {
  const [open, setOpen] = useState(false);
  const [expanded, setExpanded] = useState<string | null>(null);
  const domains = visibleDomains(isAdmin);

  const close = () => {
    setOpen(false);
    setExpanded(null);
  };

  return (
    <div className="lg:hidden">
      <button
        type="button"
        aria-label="Open menu"
        aria-expanded={open}
        onClick={() => setOpen(true)}
        className="rounded-md p-2 text-healthcare-text-primary hover:bg-healthcare-hover dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
      >
        <MenuIcon className="h-6 w-6" />
      </button>

      {open && (
        <div className="fixed inset-0 z-[90]">
          <div className="fixed inset-0 bg-black/50" onClick={close} aria-hidden="true" />
          <div className="fixed inset-y-0 left-0 flex w-80 max-w-[85%] flex-col overflow-y-auto border-r border-healthcare-border bg-healthcare-surface shadow-xl dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <div className="flex items-center justify-between border-b border-healthcare-border px-4 py-3 dark:border-healthcare-border-dark">
              <span className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Zephyrus
              </span>
              <button
                type="button"
                aria-label="Close menu"
                onClick={close}
                className="rounded-md p-1.5 text-healthcare-text-secondary hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark"
              >
                <X className="h-5 w-5" />
              </button>
            </div>

            <nav className="flex-1 px-2 py-3">
              <Link
                href={TOP_LEVEL_DASHBOARD.href}
                onClick={close}
                className="block rounded-md px-3 py-2 text-sm font-medium text-healthcare-text-primary hover:bg-healthcare-hover dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
              >
                {TOP_LEVEL_DASHBOARD.label}
              </Link>

              {domains.map((domain: NavDomain) => {
                const isOpen = expanded === domain.key;
                const active = isDomainActive(domain, url);
                const Icon = domain.icon;
                return (
                  <div key={domain.key} className="mt-0.5">
                    <button
                      type="button"
                      onClick={() => setExpanded(isOpen ? null : domain.key)}
                      aria-expanded={isOpen}
                      className={`flex w-full items-center justify-between rounded-md px-3 py-2 text-sm font-medium hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark ${
                        active
                          ? 'text-healthcare-primary dark:text-healthcare-primary-dark'
                          : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                      }`}
                    >
                      <span className="flex items-center gap-2">
                        <Icon className="h-4 w-4" />
                        {domain.label}
                      </span>
                      <ChevronDown className={`h-4 w-4 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
                    </button>
                    {isOpen && (
                      <div className="ml-4 border-l border-healthcare-border pl-2 dark:border-healthcare-border-dark">
                        {domain.dashboardHref && (
                          <Link
                            href={domain.dashboardHref}
                            onClick={close}
                            className="block rounded-md px-3 py-1.5 text-sm font-medium text-healthcare-text-secondary hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark"
                          >
                            {domain.dashboardLabel}
                          </Link>
                        )}
                        {domain.groups.map((group) => {
                          const items = group.items.filter((item) => !item.adminOnly || isAdmin);
                          if (items.length === 0) return null;
                          return (
                            <div key={group.title || domain.key} className="mt-1">
                              {group.title && (
                                <div className="px-3 pt-1 text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                  {group.title}
                                </div>
                              )}
                              {items.map((item) => (
                                <Link
                                  key={item.href}
                                  href={item.href}
                                  onClick={close}
                                  className="block rounded-md px-3 py-1.5 text-sm text-healthcare-text-secondary hover:bg-healthcare-hover hover:text-healthcare-text-primary dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark dark:hover:text-healthcare-text-primary-dark"
                                >
                                  {item.label}
                                </Link>
                              ))}
                            </div>
                          );
                        })}
                      </div>
                    )}
                  </div>
                );
              })}
            </nav>
          </div>
        </div>
      )}
    </div>
  );
}
