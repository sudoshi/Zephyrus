import { Link } from '@inertiajs/react';
import type { NavDomain } from '@/config/navigationConfig';

interface MegaMenuPanelProps {
  domain: NavDomain;
  isAdmin: boolean;
  onNavigate?: () => void;
}

export function MegaMenuPanel({ domain, isAdmin, onNavigate }: MegaMenuPanelProps) {
  return (
    <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-3 shadow-xl dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      {domain.dashboardHref && (
        <Link
          href={domain.dashboardHref}
          onClick={onNavigate}
          className="mb-3 block border-b border-healthcare-border pb-2 text-base/[18px] font-semibold text-healthcare-text-primary hover:text-healthcare-primary dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:text-healthcare-primary-dark"
        >
          {domain.dashboardLabel}
        </Link>
      )}
      <div className="flex gap-4">
        {domain.groups.map((group) => {
          const items = group.items.filter((item) => !item.adminOnly || isAdmin);
          if (items.length === 0) return null;
          return (
            <div key={group.title || domain.key} className="min-w-[160px]">
              {group.title && (
                <div className="mb-1 px-2 text-xs/[16px] font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {group.title}
                </div>
              )}
              <ul className="space-y-0.5">
                {items.map((item) => {
                  const Icon = item.icon;
                  return (
                    <li key={item.href}>
                      <Link
                        href={item.href}
                        onClick={onNavigate}
                        className="flex items-center gap-2 rounded-md px-2 py-1 text-base/[18px] text-healthcare-text-secondary transition-colors duration-200 hover:bg-healthcare-hover hover:text-healthcare-text-primary dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark dark:hover:text-healthcare-text-primary-dark"
                      >
                        <Icon className="h-4 w-4 flex-shrink-0" />
                        <span>{item.label}</span>
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </div>
          );
        })}
      </div>
    </div>
  );
}
