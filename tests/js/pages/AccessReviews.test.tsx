import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AccessReviews from '@/Pages/Admin/AccessReviews';

vi.mock('@inertiajs/react', () => ({
  Head: ({ title }: { title: string }) => <title>{title}</title>,
  router: { visit: vi.fn(), reload: vi.fn() },
}));

vi.mock('@/Components/Dashboard/DashboardLayout', () => ({
  default: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

vi.mock('@/Components/Common/PageContentLayout', () => ({
  default: ({ title, subtitle, children }: { title: string; subtitle: string; children: React.ReactNode }) => (
    <div><h1>{title}</h1><p>{subtitle}</p>{children}</div>
  ),
}));

const reviewer = { id: 1, name: 'Primary Reviewer', username: 'primary' };
const alternate = { id: 2, name: 'Alternate Reviewer', username: 'alternate' };
const summary = {
  campaignUuid: '019f-campaign',
  title: '2026 Q3 privileged access review',
  status: 'open' as const,
  dueAt: '2026-10-15T16:00:00Z',
  openedAt: '2026-09-30T12:00:00Z',
  completedAt: null,
  itemCount: 1,
  decidedCount: 0,
  revokeCount: 0,
  primaryReviewer: reviewer,
  alternateReviewer: alternate,
  snapshotSha256: 'a'.repeat(64),
  evidenceSha256: null,
};
const item = {
  itemUuid: '019f-item',
  subject: { id: 3, name: 'Integration Owner', username: 'owner', is_active: true, is_protected: false },
  reviewer,
  snapshot: {
    subject: {
      user_id: 3,
      username: 'owner',
      display_name: 'Integration Owner',
      is_active: true,
      is_protected: false,
      must_change_password: false,
    },
    scalar_role: 'integration_admin',
    spatie_roles: [],
    direct_permissions: [],
    effective_roles: ['integration_admin'],
    effective_capabilities: ['manageIntegrationConfiguration', 'viewIntegrations'],
    explicit_scopes: [],
    workforce_assignments: [],
    external_identity_providers: ['oidc'],
    active_api_token_count: 0,
    last_successful_authentication_at: '2026-09-29T12:00:00Z',
  },
  snapshotSha256: 'b'.repeat(64),
  riskFlags: ['global_scope'],
  decision: null,
  canDecide: true,
};

describe('Quarterly access reviews', () => {
  it('renders frozen access evidence and an immutable reviewer decision form', () => {
    render(<AccessReviews
      campaigns={[summary]}
      selectedCampaign={{
        ...summary,
        reviewPeriodStart: '2026-07-01',
        reviewPeriodEnd: '2026-09-30',
        snapshotAt: '2026-09-30T12:00:00Z',
        items: [item],
      }}
      reviewers={[reviewer, alternate]}
      canManage
    />);

    expect(screen.getByRole('heading', { level: 1, name: 'Quarterly Access Reviews' })).toBeInTheDocument();
    expect(screen.getByText('Integration Owner')).toBeInTheDocument();
    expect(screen.getByText('global scope')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Record immutable decision' })).toBeDisabled();
    expect(screen.getByText(/self-certification and unremediated revocation are blocked/i)).toBeInTheDocument();
  });

  it('shows sealed evidence downloads only for a completed campaign', () => {
    const completed = {
      ...summary,
      status: 'completed' as const,
      decidedCount: 1,
      completedAt: '2026-10-01T12:00:00Z',
      evidenceSha256: 'c'.repeat(64),
    };
    render(<AccessReviews
      campaigns={[completed]}
      selectedCampaign={{
        ...completed,
        reviewPeriodStart: '2026-07-01',
        reviewPeriodEnd: '2026-09-30',
        snapshotAt: '2026-09-30T12:00:00Z',
        items: [{ ...item, canDecide: false, decision: {
          decisionUuid: 'decision',
          value: 'retain',
          reasonCode: 'business_need_confirmed',
          rationale: 'Current responsibilities remain independently confirmed.',
          decidedAt: '2026-10-01T11:00:00Z',
          decidedBy: reviewer,
          remediated: false,
        } }],
      }}
      reviewers={[reviewer, alternate]}
      canManage={false}
    />);

    expect(screen.getByRole('link', { name: 'JSON evidence' })).toHaveAttribute('href', '/admin/access-reviews/019f-campaign/evidence.json');
    expect(screen.getByRole('link', { name: 'CSV evidence' })).toHaveAttribute('href', '/admin/access-reviews/019f-campaign/evidence.csv');
    expect(screen.getByText(/Canonical evidence SHA-256/)).toHaveTextContent('c'.repeat(64));
  });
});
