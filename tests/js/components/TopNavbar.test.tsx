import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import React from 'react';
import { usePage } from '@inertiajs/react';
import { TopNavbar } from '@/Components/Navigation/TopNavbar';

// CommandPalette pulls in cmdk; stub it to keep this test focused on the bar.
vi.mock('@/components/ui/CommandPalette', () => ({
  CommandPalette: () => null,
}));

vi.mock('@/Components/cockpit/ScopePicker', () => ({
  ScopePicker: () => null,
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

  it('renders only the section-level desktop controls', () => {
    mockPage({ is_admin: false });
    render(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    expect(screen.getByRole('link', { name: /^Zephyrus$/ })).toHaveAttribute('href', '/dashboard');
    expect(screen.getByRole('button', { name: /^Cockpit$/ })).toHaveAttribute('aria-current', 'page');
    for (const label of ['Workspaces', 'Study']) {
      expect(screen.getByRole('button', { name: new RegExp(`^${label}$`, 'i') })).toBeInTheDocument();
    }
    for (const oldTopLevelDomain of ['RTDC', 'Emergency', 'Transport', 'Patient Flow', 'Analytics']) {
      expect(screen.queryByRole('button', { name: new RegExp(`^${oldTopLevelDomain}$`, 'i') }))
        .not.toBeInTheDocument();
    }
  });

  it('renders the RoleSwitcher inside the Cockpit menu', () => {
    mockPage({ is_admin: false });
    render(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    fireEvent.click(screen.getByRole('button', { name: /^Cockpit$/ }));
    expect(screen.getByRole('tablist', { name: /view/i })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /Executive/ })).toBeInTheDocument();
  });

  it('keeps Admin out of the primary bar for every user', () => {
    mockPage({ is_admin: false });
    const { rerender } = render(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    expect(screen.queryByRole('button', { name: /^Admin$/ })).not.toBeInTheDocument();

    mockPage({ is_admin: true });
    rerender(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    expect(screen.queryByRole('button', { name: /^Admin$/ })).not.toBeInTheDocument();
  });

  it('shows Integrations only from the server capability', () => {
    mockPage({ is_admin: true, can: { view_integrations: false } });
    const { rerender } = render(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    expect(screen.queryByRole('link', { name: /^Integrations$/ })).not.toBeInTheDocument();

    mockPage({ is_admin: false, can: { view_integrations: true } });
    rerender(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    expect(screen.getByRole('link', { name: /^Integrations$/ })).toHaveAttribute('href', '/integrations');
  });

  it('exposes a search button that opens the command palette', () => {
    mockPage({ is_admin: false });
    render(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    expect(screen.getByRole('button', { name: /search/i })).toBeInTheDocument();
  });

  it('has no horizontally scrolling primary navigation container', () => {
    mockPage({ is_admin: false });
    render(<TopNavbar isDarkMode={false} setIsDarkMode={() => {}} />);
    expect(screen.getByRole('navigation', { name: 'Primary' }).innerHTML).not.toContain('overflow-x-auto');
  });
});
