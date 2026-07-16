import { Head, Link, usePage } from '@inertiajs/react';
import {
 Activity,
 AlertTriangle,
 ArrowRight,
 Bot,
 Building2,
 Cable,
 Database,
 HeartPulse,
 LayoutDashboard,
 KeyRound,
 ScrollText,
 Shield,
 ShieldCheck,
 Settings,
 UserCog,
 Users,
 type LucideIcon,
} from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import AdminScopeSelector from '@/Components/Admin/AdminScopeSelector';
import { withAdminScope } from '@/lib/adminScope';
import type { PageProps } from '@/types';
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
 totalUsers: number | null;
 activeUsers: number | null;
 privilegedUsers: number | null;
 mustChangePassword: number | null;
 loginsToday: number | null;
 failedLoginsToday: number | null;
 activeUsers7d: number | null;
}

interface AdminSection {
 key: string;
 label: string;
 description: string;
 href: string;
 state: 'implemented' | 'ready' | 'degraded' | 'blocked' | 'restricted';
 requiredCapability: string;
 detail: string | null;
 remediation: string | null;
}

interface ReadinessMetric extends AdminMetric {
 tone: 'default' | 'warning' | 'critical';
}

interface ScopeIndicator {
 key: string;
 label: string;
 value: string;
 detail: string;
 status: 'ready' | 'warning' | 'implemented' | 'restricted';
}

interface ActionQueueItem {
 key: string;
 severity: 'critical' | 'warning' | 'info';
 title: string;
 count: number;
 detail: string;
 href: string;
 owner: string;
}

interface AdminReadiness {
 health: {
 visible: boolean;
 overallStatus: string;
 counts: Record<string, number>;
 lastScheduledAt: string | null;
 };
 readinessMetrics: ReadinessMetric[];
 scopeIndicators: ScopeIndicator[];
 actionQueue: ActionQueueItem[];
}

interface AdminDashboardProps {
 metrics: AdminMetrics;
 readiness: AdminReadiness;
 recentEvents: AuditEvent[];
 canViewAuditActivity: boolean;
 sections: AdminSection[];
}

const sectionIcons: Record<string, LucideIcon> = {
 users: Users,
 user_management: Users,
 auth_providers: KeyRound,
 user_audit: ScrollText,
 access_reviews: ShieldCheck,
 cockpit_thresholds: Settings,
 enterprise_setup: Building2,
 staffing_administration: UserCog,
 integrations: Cable,
 system_health: HeartPulse,
 roles_capabilities: Shield,
 data_governance: Database,
 data_protection: ShieldCheck,
 eddy_governance: Bot,
 audit_compliance: ShieldCheck,
 overview: LayoutDashboard,
};

function iconForSection(section: AdminSection): LucideIcon {
 if (sectionIcons[section.key]) return sectionIcons[section.key];
 if (section.href.includes('user-audit')) return ScrollText;
 if (section.href.includes('auth-providers')) return KeyRound;
 if (section.href === '/users') return Users;
 if (section.href.includes('staffing')) return UserCog;
 if (section.href.includes('integrations')) return Cable;
 if (section.href.includes('enterprise')) return Building2;
 return Settings;
}

const sectionStateClasses: Record<AdminSection['state'], string> = {
 ready: 'bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
 implemented: 'bg-healthcare-info/10 text-healthcare-info dark:text-healthcare-info-dark',
 degraded: 'bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
 blocked: 'bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
 restricted: 'bg-healthcare-surface-secondary text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark dark:bg-healthcare-surface-hover-dark',
};

function SectionStateBadge({ state }: { state: AdminSection['state'] }) {
 return <span className={`inline-flex rounded-md px-2 py-0.5 text-xs font-semibold capitalize ${sectionStateClasses[state]}`}>{state}</span>;
}

export default function AdminDashboard({ readiness, recentEvents, canViewAuditActivity, sections }: AdminDashboardProps) {
 const scopeQuery = usePage<PageProps>().props.adminScope?.query;
 const scopedHref = (href: string) => withAdminScope(href, scopeQuery);

 return (
 <DashboardLayout>
 <Head title="Zephyrus Administration" />
 <PageContentLayout
 title="Zephyrus Administration"
 subtitle="Operations Administration for access, accountability, and platform configuration"
 headerContent={<AdminScopeSelector />}
 >
 <div className="space-y-5">
 <AdminMetricStrip metrics={readiness.readinessMetrics} />

 <section>
 <AdminSectionHeading
 title="Effective operating boundary"
 description="Current principal and separately governed organization, facility, tenant, and source scope"
 />
 <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
 {readiness.scopeIndicators.map((indicator) => (
 <div key={indicator.key} className="rounded-md border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
 <div className="flex items-center justify-between gap-2">
 <p className="text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{indicator.label}</p>
 <span className={`h-2 w-2 rounded-full ${indicator.status === 'warning' ? 'bg-healthcare-warning' : indicator.status === 'restricted' ? 'bg-healthcare-text-secondary' : 'bg-healthcare-success'}`} aria-hidden="true" />
 </div>
 <p className="mt-1 text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{indicator.value}</p>
 <p className="mt-0.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{indicator.detail}</p>
 </div>
 ))}
 </div>
 </section>

 <section>
 <AdminSectionHeading
 title="Action queue"
 description="Capability-aware exceptions and approvals with links back to their authoritative domain"
 />
 {readiness.actionQueue.length === 0 ? (
 <div className="rounded-md border border-dashed border-healthcare-border px-4 py-7 text-center dark:border-healthcare-border-dark">
 <Activity className="mx-auto h-5 w-5 text-healthcare-success dark:text-healthcare-success-dark" aria-hidden="true" />
 <p className="mt-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">No authorized action items</p>
 <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">This means no current exception is visible within your capabilities; it is not a claim that restricted domains are healthy.</p>
 </div>
 ) : (
 <div className="divide-y divide-healthcare-border overflow-hidden rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:divide-healthcare-border-dark dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
 {readiness.actionQueue.map((item) => (
 <Link key={item.key} href={scopedHref(item.href)} className="flex items-start gap-3 px-3 py-2.5 transition-colors hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark">
 <span className={`mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md ${item.severity === 'critical' ? 'bg-healthcare-critical/10 text-healthcare-critical' : 'bg-healthcare-warning/10 text-healthcare-warning'}`}>
 <AlertTriangle className="h-4 w-4" aria-hidden="true" />
 </span>
 <span className="min-w-0 flex-1">
 <span className="flex flex-wrap items-center gap-2">
 <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{item.title}</span>
 <span className="rounded-md bg-healthcare-surface-secondary px-1.5 py-0.5 text-xs font-semibold tabular-nums text-healthcare-text-primary dark:bg-healthcare-surface-hover-dark dark:text-healthcare-text-primary-dark">{item.count}</span>
 </span>
 <span className="mt-0.5 block text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.detail}</span>
 <span className="mt-0.5 block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Owner: {item.owner}</span>
 </span>
 <ArrowRight className="mt-1 h-4 w-4 shrink-0 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true" />
 </Link>
 ))}
 </div>
 )}
 </section>

 <section>
 <AdminSectionHeading
 title="Administration"
 description="Operational controls and enterprise configuration"
 />
 <div className="divide-y divide-healthcare-border overflow-hidden rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:divide-healthcare-border-dark dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
 {sections.map((section) => {
 const Icon = iconForSection(section);
 const isAvailable = ['ready', 'implemented', 'degraded'].includes(section.state);
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
 <SectionStateBadge state={section.state} />
 {isAvailable ? (
 <ArrowRight className="h-4 w-4 shrink-0 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true" />
 ) : null}
 </>
 );

 return isAvailable ? (
 <Link
 key={section.key}
 href={scopedHref(section.href)}
 className="flex items-center gap-3 px-3 py-2.5 transition-colors hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark"
 >
 {content}
 </Link>
 ) : (
 <div key={section.key} className="px-3 py-2.5 opacity-80" aria-disabled="true">
 <div className="flex items-center gap-3">{content}</div>
 {section.remediation ? <p className="ml-11 mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{section.remediation}</p> : null}
 </div>
 );
 })}
 </div>
 </section>

 <section>
 <AdminSectionHeading
 title="Recent accountability activity"
 description="Authentication, page access, and state-changing activity"
 action={canViewAuditActivity ? (
 <Link
 href={scopedHref('/admin/user-audit')}
 className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm font-medium text-healthcare-info hover:bg-healthcare-hover dark:text-healthcare-info-dark dark:hover:bg-healthcare-hover-dark"
 >
 View user audit
 <ArrowRight className="h-4 w-4" aria-hidden="true" />
 </Link>
 ) : null}
 />
 {!canViewAuditActivity ? (
 <div className="rounded-md border border-dashed border-healthcare-border px-4 py-8 text-center dark:border-healthcare-border-dark">
 <Shield className="mx-auto h-5 w-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" aria-hidden="true" />
 <p className="mt-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Audit activity is restricted</p>
 <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">The viewAudit capability is required; no event summary has been included in this page payload.</p>
 </div>
 ) : recentEvents.length === 0 ? (
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
