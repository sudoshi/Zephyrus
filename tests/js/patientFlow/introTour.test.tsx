import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import NavigatorIntro from '@/Components/PatientFlowNavigator/NavigatorIntro';
import {
  INTRO_SEEN,
  introStops,
  introTourKey,
  persistIntroSeen,
  shouldAutoStartIntro,
} from '@/features/patientFlowNavigator/introTour';

/**
 * H5.1 first-run intro: persona-keyed one-time dismissal, rounds stop only
 * when a run is loaded, and blocked storage (kiosk privacy mode on the wall)
 * degrading to "never auto-start" — the 6h demo refresh must not loop the
 * welcome card.
 */

describe('introTour helpers', () => {
  it('keys dismissal per persona, defaulting to house', () => {
    expect(introTourKey(null)).toBe('flow4d.tour.house');
    expect(introTourKey(undefined)).toBe('flow4d.tour.house');
    expect(introTourKey('charge_nurse')).toBe('flow4d.tour.charge_nurse');
  });

  it('auto-starts only on a readable, unseen store', () => {
    expect(shouldAutoStartIntro(() => null)).toBe(true);
    expect(shouldAutoStartIntro(() => INTRO_SEEN)).toBe(false);
  });

  it('blocked storage never auto-starts (kiosk wall, 6h refresh)', () => {
    expect(
      shouldAutoStartIntro(() => {
        throw new Error('storage disabled');
      }),
    ).toBe(false);
  });

  it('persists the dismissal, degrading silently when storage is blocked', () => {
    const write = vi.fn();
    persistIntroSeen(write);
    expect(write).toHaveBeenCalledTimes(1);
    expect(() =>
      persistIntroSeen(() => {
        throw new Error('storage disabled');
      }),
    ).not.toThrow();
  });

  it('includes the rounds stop only when a run is loaded', () => {
    const withoutRounds = introStops(false);
    const withRounds = introStops(true);
    expect(withoutRounds).toHaveLength(4);
    expect(withoutRounds.some((stop) => stop.id === 'rounds')).toBe(false);
    expect(withRounds).toHaveLength(5);
    expect(withRounds[withRounds.length - 1].id).toBe('rounds');
  });

  it('anchors every stop to an existing overlay class selector', () => {
    for (const stop of introStops(true)) {
      expect(stop.anchor.startsWith('.patient-flow-')).toBe(true);
      expect(stop.title.length).toBeGreaterThan(0);
      expect(stop.body.length).toBeGreaterThan(0);
    }
  });
});

describe('NavigatorIntro', () => {
  const stops = introStops(false);

  it('renders the current stop with a tabular step count', () => {
    render(
      <NavigatorIntro stops={stops} index={0} onIndexChange={vi.fn()} onDismiss={vi.fn()} />,
    );
    expect(screen.getByRole('dialog', { name: 'Navigator introduction' })).toBeTruthy();
    expect(screen.getByText('Census scope')).toBeTruthy();
    expect(screen.getByText('1 of 4')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Back' })).toBeNull();
  });

  it('advances with Next and retreats with Back', () => {
    const onIndexChange = vi.fn();
    const { rerender } = render(
      <NavigatorIntro stops={stops} index={0} onIndexChange={onIndexChange} onDismiss={vi.fn()} />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(onIndexChange).toHaveBeenCalledWith(1);
    rerender(
      <NavigatorIntro stops={stops} index={2} onIndexChange={onIndexChange} onDismiss={vi.fn()} />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(onIndexChange).toHaveBeenCalledWith(1);
  });

  it('offers Done instead of Next on the last stop, and Done dismisses', () => {
    const onDismiss = vi.fn();
    render(
      <NavigatorIntro
        stops={stops}
        index={stops.length - 1}
        onIndexChange={vi.fn()}
        onDismiss={onDismiss}
      />,
    );
    expect(screen.queryByRole('button', { name: 'Next' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Done' }));
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });

  it('dismisses via Skip and via Escape on the card', () => {
    const onDismiss = vi.fn();
    render(
      <NavigatorIntro stops={stops} index={1} onIndexChange={vi.fn()} onDismiss={onDismiss} />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Skip' }));
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(onDismiss).toHaveBeenCalledTimes(2);
  });

  it('clamps when the stop list shrinks (rounds run unloads mid-intro)', () => {
    render(
      <NavigatorIntro stops={stops} index={4} onIndexChange={vi.fn()} onDismiss={vi.fn()} />,
    );
    expect(screen.getByText('Floors & shortcuts')).toBeTruthy();
    expect(screen.getByText('4 of 4')).toBeTruthy();
  });
});
