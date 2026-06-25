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
    const { container } = render(<AuthLayout><div>my form</div></AuthLayout>);

    expect(screen.getByText('my form')).toBeInTheDocument();
    expect(screen.getAllByText('Zephyrus')).toHaveLength(2);
    expect(screen.queryByAltText('Zephyrus application icon')).not.toBeInTheDocument();
    expect(container.querySelector('.za-lockup-icon')).toHaveAttribute('alt', '');
    expect(container.querySelector('.za-lockup-icon')).toHaveAttribute('aria-hidden', 'true');
    expect(screen.getByText('Operations Command Center')).toBeInTheDocument();
    expect(screen.getByText('Emergency Department')).toBeInTheDocument();
    expect(screen.getByText('RTDC')).toBeInTheDocument();
    expect(screen.getByText('Perioperative')).toBeInTheDocument();
    expect(screen.getByText('Process Improvement')).toBeInTheDocument();
  });
});
