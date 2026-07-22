import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import {
  isDomainActive,
  isDomainVisible,
  isLeafVisible,
  type NavDomain,
  type NavigationAccess,
  type NavSection,
} from '@/config/navigationConfig';

interface SectionMenuPanelProps {
  section: NavSection;
  access: NavigationAccess;
  url: string;
  onNavigate: () => void;
}

export function SectionMenuPanel({ section, access, url, onNavigate }: SectionMenuPanelProps) {
  // Callers normally provide a section already filtered by visibleSections(),
  // but keep this projection defensive so a feature-gated domain can never be
  // reached via keyboard navigation when this panel is used on its own.
  const domains = useMemo(
    () => section.domains.filter((domain) => isDomainVisible(domain, access)),
    [access, section.domains],
  );
  const initialDomain = useMemo(
    () => domains.find((domain) => isDomainActive(domain, url)) ?? domains[0],
    [domains, url],
  );
  const [selectedKey, setSelectedKey] = useState(initialDomain?.key);
  const tabsRef = useRef<(HTMLButtonElement | null)[]>([]);

  useEffect(() => {
    setSelectedKey(initialDomain?.key);
  }, [initialDomain?.key]);

  const selected = domains.find((domain) => domain.key === selectedKey) ?? initialDomain;
  if (!selected) return null;

  const selectDomain = (index: number) => {
    const domain = domains[index];
    if (!domain) return;
    setSelectedKey(domain.key);
    tabsRef.current[index]?.focus();
  };

  const handleTabKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    const currentIndex = domains.findIndex((domain) => domain.key === selected.key);
    if (currentIndex < 0) return;

    if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
      event.preventDefault();
      selectDomain((currentIndex + 1) % domains.length);
    } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
      event.preventDefault();
      selectDomain((currentIndex - 1 + domains.length) % domains.length);
    } else if (event.key === 'Home') {
      event.preventDefault();
      selectDomain(0);
    } else if (event.key === 'End') {
      event.preventDefault();
      selectDomain(domains.length - 1);
    }
  };

  const visibleGroups = selected.groups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => isLeafVisible(item, access)),
    }))
    .filter((group) => group.items.length > 0);
  // A domain landing page can also be its first task leaf. Render that
  // destination once with the descriptive leaf label on every projection.
  const dashboardDuplicatesLeaf = visibleGroups.some((group) =>
    group.items.some((item) => item.href === selected.dashboardHref),
  );

  return (
    <div className="flex max-h-[min(70vh,42rem)] w-[min(58rem,calc(100vw-2rem))] overflow-hidden rounded-md border border-healthcare-border bg-healthcare-surface shadow-xl dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div
        role="tablist"
        aria-label={`${section.title} navigation`}
        onKeyDown={handleTabKeyDown}
        className="w-52 flex-shrink-0 border-r border-healthcare-border bg-healthcare-background/60 p-2 dark:border-healthcare-border-dark dark:bg-healthcare-background-dark/60"
      >
        {domains.map((domain, index) => {
          const Icon = domain.icon;
          const selectedDomain = domain.key === selected.key;
          return (
            <button
              key={domain.key}
              id={`${section.key}-${domain.key}-tab`}
              type="button"
              ref={(element) => { tabsRef.current[index] = element; }}
              role="tab"
              aria-selected={selectedDomain}
              aria-controls={`${section.key}-${domain.key}-panel`}
              tabIndex={selectedDomain ? 0 : -1}
              onClick={() => setSelectedKey(domain.key)}
              className={`flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium transition-colors ${
                selectedDomain
                  ? 'bg-healthcare-surface text-healthcare-primary shadow-sm dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark'
                  : 'text-healthcare-text-secondary hover:bg-healthcare-hover hover:text-healthcare-text-primary dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark dark:hover:text-healthcare-text-primary-dark'
              }`}
            >
              <Icon className="h-4 w-4 flex-shrink-0" aria-hidden="true" />
              <span>{domain.label}</span>
            </button>
          );
        })}
      </div>

      <div
        id={`${section.key}-${selected.key}-panel`}
        role="tabpanel"
        aria-labelledby={`${section.key}-${selected.key}-tab`}
        className="min-w-0 flex-1 overflow-y-auto p-4"
      >
        <div className="mb-3 flex items-center justify-between gap-4 border-b border-healthcare-border pb-3 dark:border-healthcare-border-dark">
          <h2 className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {selected.label}
          </h2>
          {selected.dashboardHref && !dashboardDuplicatesLeaf && (
            <Link
              href={selected.dashboardHref}
              onClick={onNavigate}
              className="flex items-center gap-1 rounded-md px-2 py-1 text-sm font-medium text-healthcare-primary hover:bg-healthcare-hover dark:text-healthcare-primary-dark dark:hover:bg-healthcare-hover-dark"
            >
              {selected.dashboardLabel ?? selected.label}
              <ArrowUpRight className="h-4 w-4" aria-hidden="true" />
            </Link>
          )}
        </div>

        {visibleGroups.length > 0 && (
          <div className="grid grid-cols-2 gap-x-5 gap-y-4 xl:grid-cols-3">
            {visibleGroups.map((group) => (
              <div key={group.title || selected.key}>
                {group.title && (
                  <h3 className="mb-1 px-2 text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {group.title}
                  </h3>
                )}
                <ul className="space-y-0.5">
                  {group.items.map((item) => {
                    const Icon = item.icon;
                    return (
                      <li key={item.href}>
                        <Link
                          href={item.href}
                          onClick={onNavigate}
                          className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-healthcare-text-secondary transition-colors hover:bg-healthcare-hover hover:text-healthcare-text-primary dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark dark:hover:text-healthcare-text-primary-dark"
                        >
                          <Icon className="h-4 w-4 flex-shrink-0" aria-hidden="true" />
                          <span>{item.label}</span>
                        </Link>
                      </li>
                    );
                  })}
                </ul>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
