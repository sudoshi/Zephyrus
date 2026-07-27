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

// ---------------------------------------------------------------------------
// Phase C adherence panel (§7.2 C1/C4)
// ---------------------------------------------------------------------------

function deviantAdherence() {
  return {
    state: 'ok' as const,
    verdicts: [
      {
        pathway: 'sepsis',
        pathway_version: 1,
        conformant: false,
        deviations: ['antibiotic_late', 'no_repeat_lactate'],
        activity_timeline: {
          sepsis_recognition: '2026-07-26T08:00:00+00:00',
          antibiotic_administration: '2026-07-26T11:42:00+00:00',
        },
        computed_at: '2026-07-26T14:32:00+00:00',
      },
    ],
    asOf: '2026-07-26T14:32:00+00:00',
    cadenceMinutes: 30,
  };
}

describe('NavigatorJourneyDrawer adherence panel (Phase C)', () => {
  it('is entirely absent when the surface is off — byte-identical to Phase B', () => {
    renderDrawer({ adherence: null });
    expect(screen.queryByText('Pathways')).toBeNull();
  });

  it('renders the elements-met headline, pattern deviations, evidence, provenance, and batch time', () => {
    renderDrawer({ adherence: deviantAdherence() });

    expect(screen.getByText('Sepsis bundle (SEP-3)')).toBeTruthy();
    // Two fired codes judge two distinct bundle elements → 2 of 4 met.
    expect(screen.getByText('2 of 4 elements met')).toBeTruthy();
    expect(screen.getByText('Antibiotic beyond the 3-hour target')).toBeTruthy();
    expect(screen.getByText(/Antibiotics 42 min past the 3 h target/)).toBeTruthy();
    expect(screen.getByText('late')).toBeTruthy();
    expect(screen.getByText('Repeat lactate not documented')).toBeTruthy();
    // C4 freshness honesty: provenance + as-of batch + cadence, never "live".
    expect(screen.getByText(/owner: critical care/)).toBeTruthy();
    expect(screen.getByText(/30-minute batch/)).toBeTruthy();
    expect(screen.getByText(/as of .* batch/)).toBeTruthy();
    expect(screen.queryByText(/live/i)).toBeNull();
  });

  it('states the not-on-a-pathway case honestly', () => {
    renderDrawer({ adherence: { state: 'ok', verdicts: [], asOf: null, cadenceMinutes: 30 } });
    expect(screen.getByText(/Not on a monitored pathway/)).toBeTruthy();
  });

  it('drafts an exception note through the governed callback and reports PENDING', async () => {
    const onExceptionNote = vi.fn().mockResolvedValue(true);
    renderDrawer({ adherence: deviantAdherence(), onExceptionNote });

    fireEvent.click(screen.getByText('Open an exception note'));
    fireEvent.change(screen.getByLabelText('Exception note for Sepsis bundle (SEP-3)'), {
      target: { value: 'Antibiotics held pending nephrology guidance.' },
    });
    fireEvent.click(screen.getByText('Draft for review'));

    expect(onExceptionNote).toHaveBeenCalledWith(
      'sepsis',
      ['antibiotic_late', 'no_repeat_lactate'],
      'Antibiotics held pending nephrology guidance.',
    );
    expect(await screen.findByText(/pending human review/)).toBeTruthy();
  });

  it('offers Explain only when the AI callback is wired', () => {
    renderDrawer({ adherence: deviantAdherence() });
    expect(screen.queryByText('Explain')).toBeNull();

    const onExplainDeviation = vi.fn();
    renderDrawer({ adherence: deviantAdherence(), onExplainDeviation });
    fireEvent.click(screen.getAllByText('Explain')[0]);
    expect(onExplainDeviation).toHaveBeenCalledWith(
      'sepsis',
      'Antibiotic beyond the 3-hour target',
      'Antibiotics 42 min past the 3 h target',
    );
  });
});
