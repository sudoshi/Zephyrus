import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, ...p }: any) => React.createElement('a', p, children),
  useForm: () => ({ data: {}, setData: vi.fn(), post: vi.fn(), processing: false, errors: {} }),
}));
vi.mock('@iconify/react', () => ({ Icon: () => null }));
vi.mock('framer-motion', () => ({
  motion: new Proxy(
    {},
    { get: () => ({ children, ...p }: any) => React.createElement('div', null, children) }
  ),
  AnimatePresence: ({ children }: any) => children,
}));
vi.mock('@heroui/react', () => ({
  Button: ({ children, ...p }: any) => React.createElement('button', p, children),
  Checkbox: ({ children, ...p }: any) => React.createElement('label', p, children),
}));
vi.mock('@/Layouts/GuestLayout', () => ({
  default: ({ children }: any) => React.createElement('div', null, children),
}));

import Login from '@/Pages/Auth/Login.jsx';

describe('Login Authentik SSO button', () => {
  it('shows the SSO button when oidcEnabled is true', () => {
    render(<Login status={null} canResetPassword={false} oidcEnabled={true} oidcLabel="Sign in with Authentik" />);
    const link = screen.getByRole('link', { name: /authentik/i });
    expect(link).toHaveAttribute('href', '/auth/oidc/redirect');
  });

  it('hides the SSO button when oidcEnabled is false', () => {
    render(<Login status={null} canResetPassword={false} oidcEnabled={false} />);
    expect(screen.queryByRole('link', { name: /authentik/i })).toBeNull();
  });
});
