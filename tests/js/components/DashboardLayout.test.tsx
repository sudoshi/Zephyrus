// P4b: the unified shell renders ChangePasswordModal app-wide. These tests
// pin the shell-level gate — the modal mounts on ANY page under
// DashboardLayout while must_change_password is set, and never otherwise.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';

vi.mock('@/Components/Navigation/TopNavbar', () => ({
  TopNavbar: () => <nav data-testid="topnavbar" />,
}));

vi.mock('@/Components/ChangePasswordModal', () => ({
  default: () => <div data-testid="change-password-modal" />,
}));

function stubPage(user: Record<string, unknown> | null): void {
  vi.mocked(usePage).mockReturnValue({
    props: { auth: { user }, flash: {} },
  } as never);
}

describe('DashboardLayout (unified shell)', () => {
  beforeEach(() => {
    stubPage(null);
  });

  it('renders children inside the main landmark', () => {
    render(
      <DashboardLayout>
        <span>page content</span>
      </DashboardLayout>
    );

    const main = screen.getByRole('main');
    expect(main).toHaveAttribute('id', 'main-content');
    expect(main).toHaveTextContent('page content');
  });

  it('renders the skip-to-content accessibility link', () => {
    render(
      <DashboardLayout>
        <span>content</span>
      </DashboardLayout>
    );

    const skip = screen.getByText('Skip to content');
    expect(skip).toHaveAttribute('href', '#main-content');
  });

  it('replaces desk navigation with non-focusable wall identity chrome', () => {
    render(
      <DashboardLayout wall>
        <span>wall content</span>
      </DashboardLayout>
    );

    expect(screen.queryByTestId('topnavbar')).not.toBeInTheDocument();
    expect(screen.queryByText('Skip to content')).not.toBeInTheDocument();
    expect(screen.getByText('Zephyrus · Operations Cockpit')).toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('renders ChangePasswordModal when must_change_password is set', () => {
    stubPage({ id: 1, name: 'Temp User', must_change_password: true });

    render(
      <DashboardLayout>
        <span>content</span>
      </DashboardLayout>
    );

    expect(screen.getByTestId('change-password-modal')).toBeInTheDocument();
  });

  it('does not render the modal for a user with a settled password', () => {
    stubPage({ id: 1, name: 'Settled User', must_change_password: false });

    render(
      <DashboardLayout>
        <span>content</span>
      </DashboardLayout>
    );

    expect(screen.queryByTestId('change-password-modal')).not.toBeInTheDocument();
  });

  it('does not render the modal when unauthenticated', () => {
    render(
      <DashboardLayout>
        <span>content</span>
      </DashboardLayout>
    );

    expect(screen.queryByTestId('change-password-modal')).not.toBeInTheDocument();
  });
});
