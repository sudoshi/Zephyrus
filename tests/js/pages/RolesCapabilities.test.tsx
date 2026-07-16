import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import RolesCapabilities from '@/Pages/Admin/RolesCapabilities';

vi.mock('@/Components/Dashboard/DashboardLayout', () => ({
  default: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

const props = {
  generatedAt: '2026-07-13T12:00:00Z',
  sourceOfTruth: 'config/authorization.php + App\\Authorization\\Capability',
  roles: [
    { role: 'admin', label: 'Admin', capabilities: ['viewAdministration', 'viewSystemHealth'], capabilityCount: 2, globalScope: false },
    { role: 'super_admin', label: 'Super Admin', capabilities: ['viewAdministration', 'viewSystemHealth'], capabilityCount: 2, globalScope: true },
  ],
  capabilities: [
    { capability: 'viewSystemHealth', label: 'View System Health', domain: 'Administration', scopeMode: 'global_only' as const, assignedRoles: ['admin', 'super_admin'] },
  ],
  aliases: [{ alias: 'super_user', canonical: 'superuser' }],
  globalScopeRoles: ['super_admin', 'superuser'],
  currentPrincipal: { userId: 9, roles: ['admin'], capabilities: ['viewAdministration', 'viewSystemHealth'], globalScope: false },
  counts: { roles: 2, capabilities: 1, globalScopeRoles: 2, unclassifiedCapabilities: 0 },
};

describe('Roles and Capabilities administration', () => {
  it('renders a read-only canonical policy projection with scope semantics', () => {
    render(<RolesCapabilities {...props} />);

    expect(screen.getByRole('heading', { level: 1, name: 'Roles & Capabilities' })).toBeInTheDocument();
    expect(screen.getByText(/projection, not a grant store/i)).toBeInTheDocument();
    expect(screen.getAllByText('viewSystemHealth').length).toBeGreaterThan(0);
    expect(screen.getByText('Global-only policy')).toBeInTheDocument();
    expect(screen.getByText('super_user')).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });
});
