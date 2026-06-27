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
    // The brand lockup is the "Zephyrus" wordmark (one in each panel — sidebar + form header).
    // The earlier icon <img class="za-lockup-icon"> was retired in the split-elegant auth redesign.
    expect(screen.getAllByText('Zephyrus')).toHaveLength(2);
    expect(container.querySelectorAll('.za-wordmark')).toHaveLength(2);
    expect(screen.queryByAltText('Zephyrus application icon')).not.toBeInTheDocument();
    expect(screen.getByText('Operations Command Center')).toBeInTheDocument();
    expect(screen.getByText('Emergency Department')).toBeInTheDocument();
    // RTDC / Perioperative appear in both the domain map and the secondary tag list, so assert presence (>=1).
    expect(screen.getAllByText('RTDC').length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText('Perioperative').length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText('Process Improvement')).toBeInTheDocument();
  });
});
