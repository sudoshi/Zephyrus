import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AdminDashboard from '@/Pages/Admin/Dashboard';

vi.mock('@/Components/Dashboard/DashboardLayout', () => ({
  default: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

const event = {
  eventUuid: 'evt-1',
  occurredAt: '2026-07-10T14:30:00Z',
  actor: { id: 7, name: 'Morgan Lee', username: 'mlee', email: 'mlee@example.test', role: 'admin' },
  actorRole: 'admin',
  action: 'user_login',
  category: 'authentication',
  outcome: 'success',
  reasonCode: null,
  authMethod: 'password',
  sourceSurface: 'web',
  targetType: null,
  targetId: null,
  routeName: 'login',
  routeUri: '/login',
  httpMethod: 'POST',
  responseStatus: 302,
  clientIp: '10.0.0.8',
  userAgent: 'Test Browser',
  changes: null,
  metadata: null,
};

const readiness = {
  health: { visible: true, overallStatus: 'degraded', counts: { requiredAttention: 2 }, lastScheduledAt: null },
  readinessMetrics: [
    { label: 'Identity', value: '41 / 48', detail: 'active / total users', tone: 'default' as const },
    { label: 'Credential action', value: 2, detail: 'password changes due', tone: 'warning' as const },
    { label: 'System health', value: 'Degraded', detail: '2 required items need attention', tone: 'warning' as const },
    { label: 'Access certification', value: 1, detail: 'open review items', tone: 'warning' as const },
    { label: 'Integration exceptions', value: 'Restricted', detail: 'separately governed', tone: 'default' as const },
    { label: 'Mapping review', value: 'Restricted', detail: 'separately governed', tone: 'default' as const },
    { label: 'Governed approvals', value: 0, detail: 'no approval capability', tone: 'default' as const },
  ],
  scopeIndicators: [
    { key: 'principal', label: 'Principal boundary', value: 'Scoped', detail: 'Requires effective grants.', status: 'ready' as const },
    { key: 'organizations', label: 'Organization scope', value: '1', detail: 'effective grants', status: 'implemented' as const },
    { key: 'facilities', label: 'Facility scope', value: '2', detail: 'effective grants', status: 'implemented' as const },
    { key: 'sources', label: 'Source / tenant scope', value: 'Restricted', detail: 'viewIntegrations required', status: 'restricted' as const },
  ],
  actionQueue: [
    { key: 'health', severity: 'critical' as const, title: 'Resolve platform readiness evidence', count: 2, detail: 'Required health components need evidence.', href: '/admin/system-health?status=attention', owner: 'Platform Operations' },
  ],
};

describe('Admin Dashboard', () => {
  it('renders the Zephyrus operations administration signal, metrics, and section links', () => {
    render(
      <AdminDashboard
        metrics={{
          totalUsers: 48,
          activeUsers: 41,
          privilegedUsers: 6,
          mustChangePassword: 2,
          loginsToday: 19,
          failedLoginsToday: 3,
          activeUsers7d: 37,
        }}
        readiness={readiness}
        recentEvents={[event]}
        canViewAuditActivity
        sections={[
          { key: 'users', label: 'User Management', description: 'Manage user access', href: '/users', state: 'ready', requiredCapability: 'viewIdentity', detail: null, remediation: null },
          { key: 'user_audit', label: 'User Audit', description: 'Review accountability', href: '/admin/user-audit', state: 'implemented', requiredCapability: 'viewAudit', detail: null, remediation: null },
          { key: 'integrations', label: 'Integrations', description: 'Connection control', href: '/integrations', state: 'restricted', requiredCapability: 'viewIntegrations', detail: null, remediation: 'Request governed access.' },
        ]}
      />,
    );

    expect(screen.getByRole('heading', { level: 1, name: 'Zephyrus Administration' })).toBeInTheDocument();
    expect(screen.getByText(/Operations Administration for access/i)).toBeInTheDocument();
    expect(screen.getByText('41 / 48')).toBeInTheDocument();
    expect(screen.getByText('Action queue')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Resolve platform readiness evidence/i })).toHaveAttribute('href', '/admin/system-health?status=attention');
    expect(screen.getByRole('link', { name: /User Management/i })).toHaveAttribute('href', '/users');
    expect(screen.getAllByRole('link', { name: /User Audit/i })[0]).toHaveAttribute('href', '/admin/user-audit');
    expect(screen.queryByRole('link', { name: /Integrations/i })).not.toBeInTheDocument();
    expect(screen.getByText('restricted')).toBeInTheDocument();
  });

  it('shows recent accountability activity with accurate scope language', () => {
    render(
      <AdminDashboard
        metrics={{ totalUsers: 1, activeUsers: 1, privilegedUsers: 1, mustChangePassword: 0, loginsToday: 1, failedLoginsToday: 0, activeUsers7d: 1 }}
        readiness={{ ...readiness, actionQueue: [] }}
        recentEvents={[event]}
        canViewAuditActivity
        sections={[]}
      />,
    );

    expect(screen.getByText('Authentication, page access, and state-changing activity')).toBeInTheDocument();
    expect(screen.getByText('Morgan Lee')).toBeInTheDocument();
    expect(screen.getByText('user login')).toBeInTheDocument();
    expect(screen.getByText('web / 10.0.0.8')).toBeInTheDocument();
  });

  it('does not render audit summaries or links when the domain is restricted', () => {
    render(
      <AdminDashboard
        metrics={{ totalUsers: null, activeUsers: null, privilegedUsers: null, mustChangePassword: null, loginsToday: null, failedLoginsToday: null, activeUsers7d: null }}
        readiness={{
          ...readiness,
          health: { visible: false, overallStatus: 'restricted', counts: {}, lastScheduledAt: null },
          readinessMetrics: readiness.readinessMetrics.map((metric) => ({ ...metric, value: 'Restricted', tone: 'default' as const })),
          actionQueue: [],
        }}
        recentEvents={[]}
        canViewAuditActivity={false}
        sections={[]}
      />,
    );

    expect(screen.getByText('Audit activity is restricted')).toBeInTheDocument();
    expect(screen.getByText(/no event summary has been included/i)).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /View user audit/i })).not.toBeInTheDocument();
    expect(screen.queryByText('Morgan Lee')).not.toBeInTheDocument();
  });
});
