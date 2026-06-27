// resources/js/Components/system/Section.tsx
//
// THE one section-header pattern (generalized from the Command Center Band).
// Every grouped block on every page uses this header: a small icon + uppercase
// title, an inline summary, and an optional drill link — so sections look
// identical across domains. Put a MetricGrid, a table, a UnitHeatStrip, or any
// content inside.
import { Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import type { ReactNode } from 'react';

export interface SectionProps {
  title: string;
  summary?: string;
  /** iconify name, e.g. "heroicons:building-office-2". */
  icon?: string;
  drillHref?: string;
  drillLabel?: string;
  children: ReactNode;
  className?: string;
  /** Right-aligned controls (filters, toggles) rendered in the header. */
  actions?: ReactNode;
}

export function Section({
  title, summary, icon, drillHref, drillLabel, children, className = '', actions,
}: SectionProps) {
  return (
    <section aria-label={title} className={`flex flex-col gap-2 ${className}`}>
      <header className="flex items-center justify-between gap-3 border-b border-healthcare-border dark:border-healthcare-border-dark pb-1 transition-colors duration-300">
        <div className="flex min-w-0 items-baseline gap-3">
          <span className="flex items-center gap-2">
            {icon && (
              <Icon icon={icon} aria-hidden="true"
                    className="h-4 w-4 shrink-0 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
            )}
            <h2 className="text-sm font-semibold uppercase tracking-wide text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {title}
            </h2>
          </span>
          {summary && (
            <span className="truncate text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {summary}
            </span>
          )}
        </div>
        <div className="flex shrink-0 items-center gap-3">
          {actions}
          {drillHref && (
            <Link href={drillHref}
                  className="whitespace-nowrap text-xs font-medium text-healthcare-primary dark:text-healthcare-primary-dark hover:underline">
              {drillLabel ?? 'View'} {'→'}
            </Link>
          )}
        </div>
      </header>
      {children}
    </section>
  );
}
