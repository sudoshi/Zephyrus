import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import Create from '@/Pages/Admin/Users/Create';
import Edit from '@/Pages/Admin/Users/Edit';
import Index from '@/Pages/Admin/Users/Index';

const post = vi.fn();
const put = vi.fn();

vi.mock('@inertiajs/react', () => ({
  Head: ({ title }) => <title>{title}</title>,
  Link: ({ href, method, as, children, ...props }) => {
    const Component = as === 'button' ? 'button' : 'a';

    return (
      <Component href={Component === 'a' ? href : undefined} data-href={href} data-method={method} {...props}>
        {children}
      </Component>
    );
  },
  useForm: (initialData) => ({
    data: initialData,
    setData: vi.fn(),
    post,
    put,
    processing: false,
    errors: {},
    reset: vi.fn(),
  }),
}));

vi.mock('@/Components/Dashboard/DashboardLayout', () => ({
  default: ({ children }) => <main>{children}</main>,
}));

vi.mock('@/Components/Dashboard/Card', () => ({
  default: Object.assign(({ children }) => <article>{children}</article>, {
    Content: ({ children }) => <section>{children}</section>,
  }),
}));

vi.mock('@/Components/InputError', () => ({
  default: ({ message }) => (message ? <p>{message}</p> : null),
}));

describe('Admin user pages', () => {
  it('renders concrete user-management URLs without a global route helper', () => {
    render(
      <Index
        users={[
          {
            id: 42,
            name: 'Demo Admin',
            username: 'admin',
            email: 'admin@example.test',
            role: 'admin',
            is_active: true,
            is_protected: false,
            created_at: '2026-07-10T00:00:00Z',
          },
        ]}
      />,
    );

    expect(screen.getByRole('link', { name: /add user/i })).toHaveAttribute('href', '/users/create');
    expect(document.querySelector('a[href="/users/42/edit"]')).toBeInTheDocument();
    expect(document.querySelector('[data-href="/users/42"][data-method="delete"]')).not.toBeInTheDocument();
  });

  it('submits create and edit forms to concrete user-management URLs', () => {
    render(<Create />);
    fireEvent.submit(screen.getByRole('button', { name: /create user/i }).closest('form'));
    expect(post).toHaveBeenCalledWith('/users', expect.any(Object));
    expect(screen.getByRole('link', { name: /back to users/i })).toHaveAttribute('href', '/users');

    render(
      <Edit
        user={{
          id: 42,
          name: 'Demo Admin',
          email: 'admin@example.test',
          username: 'admin',
          role: 'admin',
          is_active: true,
          is_protected: false,
        }}
      />,
    );
    const editForm = screen.getByRole('button', { name: /update user/i }).closest('form');
    fireEvent.submit(editForm);
    expect(put).toHaveBeenCalledWith('/users/42');
    expect(screen.getAllByRole('combobox').some((element) => element.value === 'routine_profile_update')).toBe(true);
  });

  it('locks routine identity and access fields for protected accounts', () => {
    render(
      <Edit
        user={{
          id: 77,
          name: 'Break Glass',
          email: 'break-glass@example.test',
          username: 'break-glass',
          role: 'admin',
          is_active: true,
          is_protected: true,
        }}
      />,
    );

    expect(screen.getByText(/This is a protected account/i)).toBeInTheDocument();
    expect(screen.getByLabelText('Email')).toBeDisabled();
    expect(screen.getByLabelText('Username')).toBeDisabled();
    expect(screen.getByLabelText('Role')).toBeDisabled();
    expect(screen.getByRole('checkbox', { name: /active account/i })).toBeDisabled();
  });

  it('renders governed external identity and exceptional purge controls', () => {
    render(
      <Edit
        auth={{
          user: { id: 9 },
          can: { manage_identity: true, manage_privileges: true },
        }}
        user={{
          id: 42,
          name: 'Inactive User',
          email: 'inactive@example.test',
          username: 'inactive-user',
          role: 'user',
          is_active: false,
          is_protected: false,
          identity_purged_at: null,
          external_identities: [{
            id: 5,
            provider: 'authentik',
            subject_fingerprint: '0123456789abcdef',
            provider_email_at_link: 'inactive@example.test',
            is_active: true,
          }],
          purge_requests: [{
            uuid: '019f5a00-0000-7000-8000-000000000001',
            author_user_id: 7,
            author_name: 'Identity Author',
            reason: 'Retention reviewed request for identifier erasure.',
            status: 'pending',
          }],
        }}
      />,
    );

    expect(screen.getByText(/Subject fingerprint 0123456789abcdef/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /unlink identity/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /request exceptional identity purge/i })).toBeInTheDocument();
    expect(screen.getByLabelText(/^purge decision$/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/^purge decision reason$/i)).toBeInTheDocument();
    expect(screen.getByText(/requires prior deactivation, recent step-up/i)).toBeInTheDocument();
  });

  it('makes a purged account visibly immutable', () => {
    render(
      <Edit
        auth={{ can: { manage_identity: true, manage_privileges: true } }}
        user={{
          id: 88,
          name: 'Purged account 88',
          email: 'purged+88@example.test',
          username: 'purged_88',
          role: 'user',
          is_active: false,
          is_protected: false,
          identity_purged_at: '2026-07-13T00:00:00Z',
          external_identities: [],
          purge_requests: [],
        }}
      />,
    );

    expect(screen.getByText(/approved identity purge/i)).toBeInTheDocument();
    expect(screen.getByLabelText('Name')).toBeDisabled();
    expect(screen.getByLabelText('Email')).toBeDisabled();
    expect(screen.getByRole('button', { name: /update user/i })).toBeDisabled();
    expect(screen.queryByRole('button', { name: /request exceptional identity purge/i })).not.toBeInTheDocument();
  });
});
