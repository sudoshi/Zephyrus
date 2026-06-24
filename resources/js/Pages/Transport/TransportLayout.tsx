import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

const tabs = [
  { label: 'Command', href: '/dashboard/transport' },
  { label: 'Requests', href: '/transport/requests' },
  { label: 'Dispatch', href: '/transport/dispatch' },
  { label: 'Inpatient', href: '/transport/inpatient' },
  { label: 'Transfers', href: '/transport/transfers' },
  { label: 'Discharge', href: '/transport/discharge' },
  { label: 'EMS', href: '/transport/ems' },
  { label: 'Transitions', href: '/transport/care-transitions' },
  { label: 'Resources', href: '/transport/resources' },
  { label: 'Analytics', href: '/transport/analytics' },
  { label: 'Integrations', href: '/transport/settings/integrations' },
];

interface TransportLayoutProps {
  title: string;
  subtitle: string;
  current: string;
  children: ReactNode;
}

export default function TransportLayout({ title, subtitle, current, children }: TransportLayoutProps) {
  return (
    <DashboardLayout>
      <Head title={`${title} - Transport`} />
      <PageContentLayout title={title} subtitle={subtitle}>
        <div className="mb-4 flex min-w-0 gap-1 overflow-x-auto border-b border-healthcare-border pb-2 dark:border-healthcare-border-dark">
          {tabs.map((tab) => {
            const active = tab.href === current;
            return (
              <Link
                key={tab.href}
                href={tab.href}
                className={`whitespace-nowrap rounded-md px-3 py-1.5 text-[13px]/[18px] font-medium transition ${
                  active
                    ? 'bg-healthcare-primary text-white'
                    : 'text-healthcare-text-secondary hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark'
                }`}
              >
                {tab.label}
              </Link>
            );
          })}
        </div>
        <div className="space-y-4">{children}</div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
