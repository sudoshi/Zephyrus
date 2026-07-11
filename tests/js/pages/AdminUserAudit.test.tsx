import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserAudit from '@/Pages/Admin/UserAudit';

vi.mock('@/Components/Dashboard/DashboardLayout', () => ({
  default: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

const event = {
  eventUuid: 'evt-22',
  occurredAt: '2026-07-10T15:45:00Z',
  actor: { id: 7, name: 'Morgan Lee', username: 'mlee', email: 'mlee@example.test', role: 'admin' },
  actorRole: 'admin',
  action: 'user_updated',
  category: 'user_management',
  outcome: 'success',
  reasonCode: 'admin_update',
  authMethod: 'oidc',
  sourceSurface: 'web',
  targetType: 'user',
  targetId: 18,
  routeName: 'users.update',
  routeUri: '/users/{user}',
  httpMethod: 'PUT',
  responseStatus: 200,
  clientIp: '10.0.0.8',
  userAgent: 'Test Browser 1.0',
  changes: { role: { old: 'user', new: 'admin' }, password: 'must-not-render' },
  metadata: { request_id: 'req-9', access_token: 'must-not-render' },
};

const props = {
  events: {
    data: [event],
    current_page: 1,
    last_page: 2,
    per_page: 25,
    total: 26,
    from: 1,
    to: 25,
    prev_page_url: null,
    next_page_url: '/admin/user-audit?page=2',
  },
  filters: { search: '', action: '', category: '', outcome: '', auth_method: '', date_from: '', date_to: '', per_page: 25 },
  options: {
    actions: ['auth.login', 'administration.user.updated'],
    categories: ['authentication', 'user_management'],
    outcomes: ['success', 'failure'],
    authMethods: ['password', 'oidc'],
  },
  stats: { totalEvents: 26, loginsToday: 12, failedLoginsToday: 2, activeUsers7d: 19 },
};

describe('Admin User Audit', () => {
  beforeEach(() => vi.clearAllMocks());

  it('submits URL-backed filters with Inertia state preservation', () => {
    render(<UserAudit {...props} />);

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search' }), { target: { value: 'Morgan' } });
    fireEvent.change(screen.getByLabelText('Action'), { target: { value: 'administration.user.updated' } });
    fireEvent.change(screen.getByLabelText('Category'), { target: { value: 'user_management' } });
    fireEvent.change(screen.getByLabelText('Outcome'), { target: { value: 'success' } });
    fireEvent.change(screen.getByLabelText('Auth method'), { target: { value: 'oidc' } });
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-07-01' } });
    fireEvent.change(screen.getByLabelText('To'), { target: { value: '2026-07-10' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));

    expect(router.get).toHaveBeenCalledWith(
      '/admin/user-audit',
      {
        search: 'Morgan',
        action: 'administration.user.updated',
        category: 'user_management',
        outcome: 'success',
        auth_method: 'oidc',
        date_from: '2026-07-01',
        date_to: '2026-07-10',
        per_page: 25,
      },
      { preserveState: true, preserveScroll: true, replace: true },
    );
  });

  it('renders the accountability table and expands safe event details', () => {
    render(<UserAudit {...props} />);

    expect(screen.getByRole('columnheader', { name: 'Time' })).toBeInTheDocument();
    expect(screen.getByText('Morgan Lee')).toBeInTheDocument();
    expect(screen.getByText('user updated')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Show details for user updated' }));

    expect(screen.getByText('users.update')).toBeInTheDocument();
    expect(screen.getByText('Test Browser 1.0')).toBeInTheDocument();
    expect(screen.getByText(/"password": "\[redacted\]"/)).toBeInTheDocument();
    expect(screen.getByText(/"access_token": "\[redacted\]"/)).toBeInTheDocument();
    expect(screen.queryByText('must-not-render')).not.toBeInTheDocument();
  });

  it('supports reset, pagination, and an informative empty state', () => {
    const { rerender } = render(<UserAudit {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(router.get).toHaveBeenLastCalledWith(
      '/admin/user-audit',
      { per_page: 25, page: 2 },
      { preserveState: true, preserveScroll: true, replace: true },
    );

    fireEvent.click(screen.getByRole('button', { name: 'Reset' }));
    expect(router.get).toHaveBeenLastCalledWith(
      '/admin/user-audit',
      { per_page: 25 },
      { preserveState: true, preserveScroll: true, replace: true },
    );

    rerender(
      <UserAudit
        {...props}
        events={{ ...props.events, data: [], total: 0, from: null, to: null, last_page: 1, next_page_url: null }}
        stats={{ ...props.stats, totalEvents: 0 }}
      />,
    );
    expect(screen.getByText('No accountability activity found')).toBeInTheDocument();
    expect(screen.getByText(/Adjust or reset the filters/i)).toBeInTheDocument();
  });
});
