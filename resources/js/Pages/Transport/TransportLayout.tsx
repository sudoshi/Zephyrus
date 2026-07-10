import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { Menu } from '@headlessui/react';
import { ChevronDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { domainLocalNavigation, type NavigationAccess } from '@/config/navigationConfig';
import type { PageProps } from '@/types';

interface LocalTab {
  readonly label: string;
  readonly href: string;
}

function projectTabs(tabs: readonly LocalTab[], current: string) {
  const active = tabs.find((tab) => tab.href === current);
  const visible = tabs.slice(0, 2);
  if (active && !visible.some((tab) => tab.href === active.href)) {
    visible[1] = active;
  }
  const visibleHrefs = new Set(visible.map((tab) => tab.href));
  return { visible, more: tabs.filter((tab) => !visibleHrefs.has(tab.href)) };
}

interface TransportLayoutProps {
  title: string;
  subtitle: string;
  current: string;
  children: ReactNode;
}

export default function TransportLayout({ title, subtitle, current, children }: TransportLayoutProps) {
  const page = usePage<PageProps>();
  const access: NavigationAccess = {
    isAdmin: Boolean(page.props.auth?.is_admin),
    can: page.props.auth?.can,
  };
  const tabs: readonly LocalTab[] = [
    { label: 'Command', href: '/dashboard?drill=flow' },
    ...domainLocalNavigation('transport', access).map(({ label, href }) => ({ label, href })),
    { label: 'Analytics', href: '/transport/analytics' },
  ];
  const projected = projectTabs(tabs, current);

  return (
    <DashboardLayout>
      <Head title={`${title} - Transport`} />
      <PageContentLayout title={title} subtitle={subtitle} headerContent={null}>
        <nav
          aria-label="Transport"
          className="mb-4 flex min-w-0 items-center gap-1 border-b border-healthcare-border pb-2 dark:border-healthcare-border-dark"
        >
          {projected.visible.map((tab) => {
            const active = tab.href === current;
            return (
              <Link
                key={tab.href}
                href={tab.href}
                className={`whitespace-nowrap rounded-md px-3 py-1.5 text-sm/[18px] font-medium transition ${
                  active
                    ? 'bg-healthcare-primary text-white'
                    : 'text-healthcare-text-secondary hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark'
                }`}
              >
                {tab.label}
              </Link>
            );
          })}
          {projected.more.length > 0 && (
            <Menu as="div" className="relative">
              <Menu.Button className="flex items-center gap-1 whitespace-nowrap rounded-md px-3 py-1.5 text-sm/[18px] font-medium text-healthcare-text-secondary transition hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark">
                More
                <ChevronDown className="h-4 w-4" aria-hidden="true" />
              </Menu.Button>
              <Menu.Items className="absolute right-0 z-50 mt-1 w-52 rounded-md border border-healthcare-border bg-healthcare-surface p-1 shadow-lg dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                {projected.more.map((tab) => (
                  <Menu.Item key={tab.href}>
                    {({ focus }) => (
                      <Link
                        href={tab.href}
                        className={`block rounded-md px-3 py-2 text-sm ${
                          focus
                            ? 'bg-healthcare-hover text-healthcare-text-primary dark:bg-healthcare-hover-dark dark:text-healthcare-text-primary-dark'
                            : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                        }`}
                      >
                        {tab.label}
                      </Link>
                    )}
                  </Menu.Item>
                ))}
              </Menu.Items>
            </Menu>
          )}
        </nav>
        <div className="space-y-4">{children}</div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
