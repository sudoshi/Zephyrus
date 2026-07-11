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
            created_at: '2026-07-10T00:00:00Z',
          },
        ]}
      />,
    );

    expect(screen.getByRole('link', { name: /add user/i })).toHaveAttribute('href', '/users/create');
    expect(document.querySelector('a[href="/users/42/edit"]')).toBeInTheDocument();
    expect(document.querySelector('[data-href="/users/42"][data-method="delete"]')).toBeInTheDocument();
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
        }}
      />,
    );
    fireEvent.submit(screen.getByRole('button', { name: /update user/i }).closest('form'));
    expect(put).toHaveBeenCalledWith('/users/42');
  });
});
