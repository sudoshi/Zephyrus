import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import AuthLayout from '@/Layouts/AuthLayout';

describe('AuthLayout', () => {
  beforeEach(() => { document.documentElement.classList.remove('dark'); });

  it('forces dark mode on the document root', () => {
    render(<AuthLayout><div>child</div></AuthLayout>);
    expect(document.documentElement.classList.contains('dark')).toBe(true);
  });

  it('renders children and the brand hero', () => {
    render(<AuthLayout><div>my form</div></AuthLayout>);
    expect(screen.getByText('my form')).toBeInTheDocument();
    expect(screen.getByText('Zephyrus')).toBeInTheDocument();
  });
});
