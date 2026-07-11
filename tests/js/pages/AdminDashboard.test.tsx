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
        recentEvents={[event]}
        sections={[
          { key: 'users', label: 'User Management', description: 'Manage user access', href: '/users', available: true },
          { key: 'user_audit', label: 'User Audit', description: 'Review accountability', href: '/admin/user-audit', available: true },
          { key: 'integrations', label: 'Integrations', description: 'Connection control', href: '/integrations', available: false },
        ]}
      />,
    );

    expect(screen.getByRole('heading', { level: 1, name: 'Zephyrus Administration' })).toBeInTheDocument();
    expect(screen.getByText(/Operations Administration for access/i)).toBeInTheDocument();
    expect(screen.getByText('48')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /User Management/i })).toHaveAttribute('href', '/users');
    expect(screen.getAllByRole('link', { name: /User Audit/i })[0]).toHaveAttribute('href', '/admin/user-audit');
    expect(screen.queryByRole('link', { name: /Integrations/i })).not.toBeInTheDocument();
    expect(screen.getByText('Unavailable')).toBeInTheDocument();
  });

  it('shows recent accountability activity with accurate scope language', () => {
    render(
      <AdminDashboard
        metrics={{ totalUsers: 1, activeUsers: 1, privilegedUsers: 1, mustChangePassword: 0, loginsToday: 1, failedLoginsToday: 0, activeUsers7d: 1 }}
        recentEvents={[event]}
        sections={[]}
      />,
    );

    expect(screen.getByText('Authentication, page access, and state-changing activity')).toBeInTheDocument();
    expect(screen.getByText('Morgan Lee')).toBeInTheDocument();
    expect(screen.getByText('user login')).toBeInTheDocument();
    expect(screen.getByText('web / 10.0.0.8')).toBeInTheDocument();
  });
});
