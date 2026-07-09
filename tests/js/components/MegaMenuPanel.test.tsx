import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { MegaMenuPanel } from '@/Components/Navigation/MegaMenuPanel';
import { NAVIGATION } from '@/config/navigationConfig';

const rtdc = NAVIGATION.find((d) => d.key === 'rtdc')!;
const admin = NAVIGATION.find((d) => d.key === 'admin')!;

describe('MegaMenuPanel', () => {
  it('renders the dashboard header link and every group title', () => {
    render(<MegaMenuPanel domain={rtdc} access={{ isAdmin: false }} />);
    // P4a: the header link is the primary live workspace, not a dead overview.
    expect(screen.getByText('Bed Tracking Board').closest('a')).toHaveAttribute(
      'href',
      '/rtdc/bed-tracking',
    );
    expect(screen.getByText('Operations')).toBeInTheDocument();
    expect(screen.getByText('Predictions')).toBeInTheDocument();
    // P5: the RTDC Analytics group re-homed to the Study altitude.
    expect(screen.queryByText('Analytics')).not.toBeInTheDocument();
  });

  it('renders each item as a link with the correct href', () => {
    render(<MegaMenuPanel domain={rtdc} access={{ isAdmin: false }} />);
    const bedTracking = screen.getByText('Bed Tracking').closest('a');
    expect(bedTracking).toHaveAttribute('href', '/rtdc/bed-tracking');
    expect(screen.getByText('Discharge').closest('a')).toHaveAttribute('href', '/rtdc/predictions/discharge');
  });

  it('hides admin-only items when not an admin', () => {
    render(<MegaMenuPanel domain={admin} access={{ isAdmin: false }} />);
    expect(screen.queryByText('User Management')).not.toBeInTheDocument();
  });

  it('shows admin-only items for an admin', () => {
    render(<MegaMenuPanel domain={admin} access={{ isAdmin: true }} />);
    expect(screen.getByText('User Management').closest('a')).toHaveAttribute('href', '/users');
  });
});
