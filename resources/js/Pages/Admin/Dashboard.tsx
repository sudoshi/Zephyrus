import { Head, Link } from '@inertiajs/react';
import {
  ArrowRight,
  Building2,
  Cable,
  LayoutDashboard,
  ScrollText,
  Settings,
  UserCog,
  Users,
  type LucideIcon,
} from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import {
  AdminMetricStrip,
  AdminSectionHeading,
  OutcomeBadge,
  type AdminMetric,
} from '@/Pages/Admin/components/AdminPrimitives';
import {
  actorDisplayName,
  formatAuditTime,
  type AuditEvent,
} from '@/Pages/Admin/auditTypes';

interface AdminMetrics {
  totalUsers: number;
  activeUsers: number;
  privilegedUsers: number;
  mustChangePassword: number;
  loginsToday: number;
  failedLoginsToday: number;
  activeUsers7d: number;
}

interface AdminSection {
  key: string;
  label: string;
  description: string;
  href: string;
  available: boolean;
}

interface AdminDashboardProps {
  metrics: AdminMetrics;
  recentEvents: AuditEvent[];
  sections: AdminSection[];
}

const sectionIcons: Record<string, LucideIcon> = {
  users: Users,
  user_management: Users,
  user_audit: ScrollText,
  cockpit_thresholds: Settings,
  enterprise_setup: Building2,
  staffing_administration: UserCog,
  integrations: Cable,
  overview: LayoutDashboard,
};

function iconForSection(section: AdminSection): LucideIcon {
  if (sectionIcons[section.key]) return sectionIcons[section.key];
  if (section.href.includes('user-audit')) return ScrollText;
  if (section.href === '/users') return Users;
  if (section.href.includes('staffing')) return UserCog;
  if (section.href.includes('integrations')) return Cable;
  if (section.href.includes('enterprise')) return Building2;
  return Settings;
}

export default function AdminDashboard({ metrics, recentEvents, sections }: AdminDashboardProps) {
  const metricStrip: AdminMetric[] = [
    { label: 'Total users', value: metrics.totalUsers },
    { label: 'Active users', value: metrics.activeUsers },
    { label: 'Privileged users', value: metrics.privilegedUsers },
    {
      label: 'Password change due',
      value: metrics.mustChangePassword,
      tone: metrics.mustChangePassword > 0 ? 'warning' : 'default',
    },
    { label: 'Logins today', value: metrics.loginsToday },
    {
      label: 'Failed logins today',
      value: metrics.failedLoginsToday,
      tone: metrics.failedLoginsToday > 0 ? 'critical' : 'default',
    },
    { label: 'Active users, 7 days', value: metrics.activeUsers7d },
  ];

  return (
    <DashboardLayout>
      <Head title="Zephyrus Administration" />
      <PageContentLayout
        title="Zephyrus Administration"
        subtitle="Operations Administration for access, accountability, and platform configuration"
        headerContent={null}
      >
        <div className="space-y-5">
          <AdminMetricStrip metrics={metricStrip} />

          <section>
            <AdminSectionHeading
              title="Administration"
              description="Operational controls and enterprise configuration"
            />
            <div className="divide-y divide-healthcare-border overflow-hidden rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:divide-healthcare-border-dark dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              {sections.map((section) => {
                const Icon = iconForSection(section);
                const content = (
                  <>
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-healthcare-surface-secondary text-healthcare-info dark:bg-healthcare-surface-hover-dark dark:text-healthcare-info-dark">
                      <Icon className="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {section.label}
                      </span>
                      <span className="block text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {section.description}
                      </span>
                    </span>
                    {section.available ? (
                      <ArrowRight className="h-4 w-4 shrink-0 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true" />
                    ) : (
                      <span className="shrink-0 text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Unavailable
                      </span>
                    )}
                  </>
                );

                return section.available ? (
                  <Link
                    key={section.key}
                    href={section.href}
                    className="flex items-center gap-3 px-3 py-2.5 transition-colors hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark"
                  >
                    {content}
                  </Link>
                ) : (
                  <div key={section.key} className="flex items-center gap-3 px-3 py-2.5 opacity-70" aria-disabled="true">
                    {content}
                  </div>
                );
              })}
            </div>
          </section>

          <section>
            <AdminSectionHeading
              title="Recent accountability activity"
              description="Authentication, page access, and state-changing activity"
              action={
                <Link
                  href="/admin/user-audit"
                  className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm font-medium text-healthcare-info hover:bg-healthcare-hover dark:text-healthcare-info-dark dark:hover:bg-healthcare-hover-dark"
                >
                  View user audit
                  <ArrowRight className="h-4 w-4" aria-hidden="true" />
                </Link>
              }
            />
            {recentEvents.length === 0 ? (
              <div className="rounded-md border border-dashed border-healthcare-border px-4 py-8 text-center dark:border-healthcare-border-dark">
                <ScrollText className="mx-auto h-5 w-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true" />
                <p className="mt-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  No recent accountability activity
                </p>
              </div>
            ) : (
              <div className="overflow-x-auto rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                <table className="min-w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark">
                  <thead className="bg-healthcare-surface-secondary dark:bg-healthcare-surface-hover-dark">
                    <tr>
                      {['Time', 'Actor', 'Event', 'Outcome', 'Source / IP'].map((label) => (
                        <th key={label} scope="col" className="whitespace-nowrap px-3 py-2 text-left text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          {label}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                    {recentEvents.map((event) => (
                      <tr key={event.eventUuid}>
                        <td className="whitespace-nowrap px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          <time dateTime={event.occurredAt}>{formatAuditTime(event.occurredAt)}</time>
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                          {actorDisplayName(event)}
                        </td>
                        <td className="min-w-56 px-3 py-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                          <span className="font-medium">{event.action.replace(/[._-]+/g, ' ')}</span>
                          <span className="ml-2 text-xs capitalize text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {event.category.replace(/[._-]+/g, ' ')}
                          </span>
                        </td>
                        <td className="whitespace-nowrap px-3 py-2"><OutcomeBadge outcome={event.outcome} /></td>
                        <td className="whitespace-nowrap px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          {event.sourceSurface || 'Unknown'}{event.clientIp ? ` / ${event.clientIp}` : ''}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
