import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { MegaMenuPanel } from '@/Components/Navigation/MegaMenuPanel';
import { NAVIGATION } from '@/config/navigationConfig';

const rtdc = NAVIGATION.find((d) => d.key === 'rtdc')!;
const admin = NAVIGATION.find((d) => d.key === 'admin')!;

describe('MegaMenuPanel', () => {
  it('renders the dashboard header link and every group title', () => {
    render(<MegaMenuPanel domain={rtdc} isAdmin={false} />);
    expect(screen.getByText('RTDC Dashboard')).toBeInTheDocument();
    expect(screen.getByText('Operations')).toBeInTheDocument();
    expect(screen.getByText('Analytics')).toBeInTheDocument();
    expect(screen.getByText('Predictions')).toBeInTheDocument();
  });

  it('renders each item as a link with the correct href', () => {
    render(<MegaMenuPanel domain={rtdc} isAdmin={false} />);
    const bedTracking = screen.getByText('Bed Tracking').closest('a');
    expect(bedTracking).toHaveAttribute('href', '/rtdc/bed-tracking');
    expect(screen.getByText('Discharge').closest('a')).toHaveAttribute('href', '/rtdc/predictions/discharge');
  });

  it('hides admin-only items when not an admin', () => {
    render(<MegaMenuPanel domain={admin} isAdmin={false} />);
    expect(screen.queryByText('User Management')).not.toBeInTheDocument();
  });

  it('shows admin-only items for an admin', () => {
    render(<MegaMenuPanel domain={admin} isAdmin />);
    expect(screen.getByText('User Management').closest('a')).toHaveAttribute('href', '/users');
  });
});
