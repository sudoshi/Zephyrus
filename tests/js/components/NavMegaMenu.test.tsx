import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { NavMegaMenu } from '@/Components/Navigation/NavMegaMenu';
import { NAVIGATION } from '@/config/navigationConfig';

const rtdc = NAVIGATION.find((d) => d.key === 'rtdc')!;

describe('NavMegaMenu', () => {
  it('renders the domain trigger label', () => {
    render(<NavMegaMenu domain={rtdc} access={{ isAdmin: false }} active={false} />);
    expect(screen.getByRole('button', { name: /RTDC/i })).toBeInTheDocument();
  });

  it('marks the trigger active via aria-current when active', () => {
    render(<NavMegaMenu domain={rtdc} access={{ isAdmin: false }} active />);
    expect(screen.getByRole('button', { name: /RTDC/i })).toHaveAttribute('aria-current', 'page');
  });
});
