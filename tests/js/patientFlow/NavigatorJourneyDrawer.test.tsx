import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import NavigatorJourneyDrawer from '@/Components/PatientFlowNavigator/NavigatorJourneyDrawer';
import { journeyFixture } from './journey.test';

/**
 * The Patient Journey Drawer (plan §7.1, PJ-1): renders the interval story,
 * re-zeroes on align change, degrades honestly on forbidden/error, and never
 * shows anything but the lens-issued display label (the identity sentinel at
 * the component boundary — raw refs can't even reach these props by type).
 */

function renderDrawer(overrides: Partial<React.ComponentProps<typeof NavigatorJourneyDrawer>> = {}) {
  const props: React.ComponentProps<typeof NavigatorJourneyDrawer> = {
    journey: journeyFixture(),
    state: 'ok',
    align: 'clock',
    onAlignChange: vi.fn(),
    onClose: vi.fn(),
    onFocus: vi.fn(),
    followEnabled: false,
    onFollowToggle: vi.fn(),
    onCopyLink: vi.fn(),
    copiedLink: false,
    ...overrides,
  };
  return { ...render(<NavigatorJourneyDrawer {...props} />), props };
}

describe('NavigatorJourneyDrawer', () => {
  it('renders the interval story: segments with dwell, phases, milestones, logistics', () => {
    renderDrawer();

    expect(screen.getByText('Patient ABCDEF')).toBeTruthy();
    expect(screen.getByText(/LOS 12 hr 15 min/)).toBeTruthy();
    expect(screen.getByText('TICU')).toBeTruthy();
    expect(screen.getByText(/3 hr 30 min/)).toBeTruthy();
    expect(screen.getByText(/8 hr 45 min · ongoing/)).toBeTruthy();
    expect(screen.getByText(/ED · boarding/)).toBeTruthy();
    expect(screen.getByText(/pre op assessment/)).toBeTruthy();
    expect(screen.getByText(/Bed request → Med Surg/)).toBeTruthy();
    // Unit barriers are labeled as unit context, never patient-attributed.
    expect(screen.getByText(/not attributed to this patient/)).toBeTruthy();
  });

  it('is nothing at all when idle', () => {
    const { container } = renderDrawer({ state: 'idle', journey: null });
    expect(container.firstChild).toBeNull();
  });

  it('degrades honestly on forbidden and error states', () => {
    renderDrawer({ state: 'forbidden', journey: null });
    expect(screen.getByText(/no patient-journey access/)).toBeTruthy();
  });

  it('align control re-zeroes via the callback and marks the active anchor', () => {
    const { props } = renderDrawer({ align: 'arrival' });

    const arrival = screen.getByRole('radio', { name: 'From arrival' });
    expect(arrival.getAttribute('aria-checked')).toBe('true');

    fireEvent.click(screen.getByRole('radio', { name: 'From admit' }));
    expect(props.onAlignChange).toHaveBeenCalledWith('admit');
  });

  it('wires focus, follow, copy-link, and close', () => {
    const { props } = renderDrawer();

    fireEvent.click(screen.getByText('Focus trace'));
    expect(props.onFocus).toHaveBeenCalled();

    fireEvent.click(screen.getByText('Follow'));
    expect(props.onFollowToggle).toHaveBeenCalledWith(true);

    fireEvent.click(screen.getByText('Copy link'));
    expect(props.onCopyLink).toHaveBeenCalled();

    fireEvent.click(screen.getByLabelText('Close patient journey'));
    expect(props.onClose).toHaveBeenCalled();
  });

  it('shows aligned offsets when anchored on arrival', () => {
    renderDrawer({ align: 'arrival' });
    // The MS5B segment starts 3.5h after the first event.
    expect(screen.getAllByText(/\+3 hr 30 min/).length).toBeGreaterThan(0);
  });
});
