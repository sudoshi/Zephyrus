import { useState } from 'react';
import { Link } from '@inertiajs/react';
import {
  Dialog,
  DialogBackdrop,
  DialogPanel,
  DialogTitle,
  Disclosure,
  DisclosureButton,
  DisclosurePanel,
} from '@headlessui/react';
import { ChevronDown, Menu, X } from 'lucide-react';
import { RoleSwitcher } from '@/Components/CommandCenter/RoleSwitcher';
import {
  isDomainActive,
  isLeafVisible,
  isSectionActive,
  type NavigationAccess,
  type NavSection,
} from '@/config/navigationConfig';

interface MobileNavDrawerProps {
  sections: readonly NavSection[];
  access: NavigationAccess;
  url: string;
}

export function MobileNavDrawer({ sections, access, url }: MobileNavDrawerProps) {
  const [open, setOpen] = useState(false);
  const close = () => setOpen(false);

  return (
    <>
      <button
        type="button"
        aria-label="Open main navigation"
        onClick={() => setOpen(true)}
        className="rounded-md border border-transparent p-2 text-healthcare-text-secondary transition-colors hover:border-healthcare-border hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:border-healthcare-border-dark dark:hover:bg-healthcare-hover-dark lg:hidden"
      >
        <Menu className="h-5 w-5" aria-hidden="true" />
      </button>

      <Dialog open={open} onClose={setOpen} className="fixed inset-0 z-[80] lg:hidden">
        <DialogBackdrop
          transition
          className="fixed inset-0 bg-black/45 transition-opacity duration-200 data-[closed]:opacity-0"
        />
        <div className="fixed inset-0 overflow-hidden">
          <DialogPanel
            transition
            className="fixed inset-y-0 left-0 flex w-[min(22rem,calc(100vw-2rem))] flex-col border-r border-healthcare-border bg-healthcare-surface shadow-2xl transition duration-200 ease-out data-[closed]:-translate-x-full dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
          >
            <div className="flex h-[var(--topbar-height)] flex-shrink-0 items-center justify-between border-b border-healthcare-border px-4 dark:border-healthcare-border-dark">
              <DialogTitle className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Navigation
              </DialogTitle>
              <button
                type="button"
                aria-label="Close navigation"
                onClick={close}
                className="rounded-md p-2 text-healthcare-text-secondary hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark"
              >
                <X className="h-5 w-5" aria-hidden="true" />
              </button>
            </div>

            <nav aria-label="Mobile primary" className="min-h-0 flex-1 overflow-y-auto px-3 py-3">
              <ul className="space-y-1">
                {sections.map((section) => {
                  const SectionIcon = section.icon;
                  const sectionActive = isSectionActive(section, url);
                  const direct = section.homeHref && (section.domains.length <= 1);

                  if (direct) {
                    return (
                      <li key={section.key}>
                        <Link
                          href={section.homeHref as string}
                          onClick={close}
                          aria-current={sectionActive ? 'page' : undefined}
                          className={`flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-medium ${
                            sectionActive
                              ? 'bg-healthcare-hover text-healthcare-primary dark:bg-healthcare-hover-dark dark:text-healthcare-text-primary-dark'
                              : 'text-healthcare-text-primary hover:bg-healthcare-hover dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark'
                          }`}
                        >
                          <SectionIcon className="h-5 w-5" aria-hidden="true" />
                          {section.title}
                        </Link>
                      </li>
                    );
                  }

                  return (
                    <li key={section.key}>
                      <Disclosure defaultOpen>
                        {({ open: sectionOpen }) => (
                          <>
                            <DisclosureButton
                              className={`flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left text-sm font-semibold ${
                                sectionActive
                                  ? 'text-healthcare-primary dark:text-healthcare-primary-dark'
                                  : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                              } hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark`}
                            >
                              <SectionIcon className="h-5 w-5" aria-hidden="true" />
                              <span className="flex-1">{section.title}</span>
                              <ChevronDown
                                className={`h-4 w-4 transition-transform ${sectionOpen ? 'rotate-180' : ''}`}
                                aria-hidden="true"
                              />
                            </DisclosureButton>
                            <DisclosurePanel>
                              <ul className="ml-3 border-l border-healthcare-border pl-2 dark:border-healthcare-border-dark">
                                {section.domains.map((domain) => {
                                  const DomainIcon = domain.icon;
                                  const domainActive = isDomainActive(domain, url);
                                  const groups = domain.groups
                                    .map((group) => ({
                                      ...group,
                                      items: group.items.filter((item) => isLeafVisible(item, access)),
                                    }))
                                    .filter((group) => group.items.length > 0);
                                  const dashboardDuplicatesLeaf = groups.some((group) =>
                                    group.items.some((item) => item.href === domain.dashboardHref),
                                  );

                                  return (
                                    <li key={domain.key}>
                                      <Disclosure defaultOpen={domainActive}>
                                        {({ open: domainOpen }) => (
                                          <>
                                            <DisclosureButton
                                              className={`flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm ${
                                                domainActive
                                                  ? 'bg-healthcare-hover text-healthcare-primary dark:bg-healthcare-hover-dark dark:text-healthcare-text-primary-dark'
                                                  : 'text-healthcare-text-secondary hover:bg-healthcare-hover hover:text-healthcare-text-primary dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark dark:hover:text-healthcare-text-primary-dark'
                                              }`}
                                            >
                                              <DomainIcon className="h-4 w-4" aria-hidden="true" />
                                              <span className="flex-1">{domain.label}</span>
                                              <ChevronDown
                                                className={`h-4 w-4 transition-transform ${domainOpen ? 'rotate-180' : ''}`}
                                                aria-hidden="true"
                                              />
                                            </DisclosureButton>
                                            <DisclosurePanel className="pb-2 pl-5">
                                              {domain.dashboardHref && !dashboardDuplicatesLeaf && (
                                                <Link
                                                  href={domain.dashboardHref}
                                                  onClick={close}
                                                  className="block rounded-md px-3 py-2 text-sm font-medium text-healthcare-text-primary hover:bg-healthcare-hover dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
                                                >
                                                  {domain.dashboardLabel ?? domain.label}
                                                </Link>
                                              )}
                                              {groups.map((group) => (
                                                <div key={group.title || domain.key} className="mt-1">
                                                  {group.title && (
                                                    <div className="px-3 pb-1 pt-2 text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                      {group.title}
                                                    </div>
                                                  )}
                                                  {group.items.map((item) => {
                                                    const ItemIcon = item.icon;
                                                    const current = url.split(/[?#]/)[0] === item.href;
                                                    return (
                                                      <Link
                                                        key={item.href}
                                                        href={item.href}
                                                        onClick={close}
                                                        aria-current={current ? 'page' : undefined}
                                                        className="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-healthcare-text-secondary hover:bg-healthcare-hover hover:text-healthcare-text-primary dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark dark:hover:text-healthcare-text-primary-dark"
                                                      >
                                                        <ItemIcon className="h-4 w-4" aria-hidden="true" />
                                                        {item.label}
                                                      </Link>
                                                    );
                                                  })}
                                                </div>
                                              ))}
                                            </DisclosurePanel>
                                          </>
                                        )}
                                      </Disclosure>
                                    </li>
                                  );
                                })}
                              </ul>
                            </DisclosurePanel>
                          </>
                        )}
                      </Disclosure>
                    </li>
                  );
                })}
              </ul>
            </nav>

            <div className="flex-shrink-0 border-t border-healthcare-border p-4 dark:border-healthcare-border-dark">
              <RoleSwitcher />
            </div>
          </DialogPanel>
        </div>
      </Dialog>
    </>
  );
}
