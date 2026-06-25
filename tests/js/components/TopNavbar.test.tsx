import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { usePage } from '@inertiajs/react';
import { TopNavbar } from '@/Components/Navigation/TopNavbar';

// CommandPalette pulls in cmdk; stub it to keep this test focused on the bar.
vi.mock('@/components/ui/CommandPalette', () => ({
  CommandPalette: () => null,
}));

function mockPage(overrides: Record<string, unknown>) {
  vi.mocked(usePage).mockReturnValue({
    url: '/dashboard',
    props: { auth: { user: { id: 1, name: 'Test', email: 't@x.io' }, ...overrides } },
    component: 'X',
    version: '1',
  } as never);
}

describe('TopNavbar', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders the Zephyrus dashboard brand link and the six non-admin domain triggers', () => {
    mockPage({ is_admin: false });
    render(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    expect(screen.getByRole('link', { name: /^Zephyrus$/ })).toHaveAttribute('href', '/dashboard');
    expect(screen.queryByRole('link', { name: /^Dashboard$/ })).not.toBeInTheDocument();
    for (const label of ['RTDC', 'Transport', 'Perioperative', 'Emergency', 'Improvement', 'Analytics']) {
      expect(screen.getByRole('button', { name: new RegExp(label, 'i') })).toBeInTheDocument();
    }
  });

  it('hides the Admin trigger for non-admins', () => {
    mockPage({ is_admin: false });
    render(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    expect(screen.queryByRole('button', { name: /^Admin$/ })).not.toBeInTheDocument();
  });

  it('shows the Admin trigger for admins', () => {
    mockPage({ is_admin: true });
    render(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    expect(screen.getByRole('button', { name: /Admin/i })).toBeInTheDocument();
  });

  it('exposes a search button that opens the command palette', () => {
    mockPage({ is_admin: false });
    render(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    expect(screen.getByRole('button', { name: /search/i })).toBeInTheDocument();
  });
});
