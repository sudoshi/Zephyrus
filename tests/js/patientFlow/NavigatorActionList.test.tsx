import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import NavigatorActionList from '@/Components/PatientFlowNavigator/NavigatorActionList';
import type { NavigatorBarrier, OccupancyInsight } from '@/features/patientFlowNavigator/types';

/**
 * F-8 non-pointer parity: the canvas raycast can select delayed disks and
 * barrier diamonds; this list gives keyboard/AT users the same reach through
 * the same selectEntity path. Labels stay location/barrier-level — never
 * patient identity — so the list is safe on a shared wall under any lens.
 */

function insight(location: string, status: OccupancyInsight['primaryStatus']): OccupancyInsight {
  return {
    key: `k-${location}`,
    location,
    locationName: `${location} · MedSurg`,
    serviceLine: 'medicine',
    position: { x: 0, y: 0, z: 0 },
    stayMinutes: 600,
    primaryStatus: status,
    timers: [],
    blockers: [],
    // Identity fields deliberately present to prove the list never surfaces them.
    patientDisplayId: 'PT-SECRET',
    patientId: 'pat-secret',
    encounterId: 'enc-secret',
  } as OccupancyInsight;
}

const barrier: NavigatorBarrier = {
  barrier_id: 42,
  unit_id: 7,
  unit_label: 'MICU',
  category: 'placement',
  reason_code: 'awaiting_bed',
  description: 'Receiving bed pending',
  owner: 'Bed Manager',
  status: 'open',
  opened_at: '2026-07-19T10:00:00Z',
  encounter_ref: null,
};

describe('NavigatorActionList', () => {
  it('renders nothing when there is nothing to act on', () => {
    const { container } = render(
      <NavigatorActionList delayed={[]} barriers={[]} onSelectLocation={vi.fn()} onSelectBarrier={vi.fn()} />,
    );
    expect(container.firstChild).toBeNull();
  });

  it('lists delayed locations as buttons and selects by location code', () => {
    const onSelectLocation = vi.fn();
    render(
      <NavigatorActionList
        delayed={[insight('MICU3-B012', 'delayed'), insight('5W-014', 'watch')]}
        barriers={[]}
        onSelectLocation={onSelectLocation}
        onSelectBarrier={vi.fn()}
      />,
    );
    expect(screen.getByText('Delayed & watch (2)')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /MICU3-B012 · MedSurg/ }));
    expect(onSelectLocation).toHaveBeenCalledWith('MICU3-B012');
  });

  it('never surfaces patient identity in a location row', () => {
    render(
      <NavigatorActionList
        delayed={[insight('MICU3-B012', 'delayed')]}
        barriers={[]}
        onSelectLocation={vi.fn()}
        onSelectBarrier={vi.fn()}
      />,
    );
    expect(screen.queryByText(/PT-SECRET|pat-secret|enc-secret/)).toBeNull();
  });

  it('lists open barriers as buttons and selects by barrier id', () => {
    const onSelectBarrier = vi.fn();
    render(
      <NavigatorActionList
        delayed={[]}
        barriers={[barrier]}
        onSelectLocation={vi.fn()}
        onSelectBarrier={onSelectBarrier}
      />,
    );
    expect(screen.getByText('Barriers (1)')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /MICU.*placement/ }));
    expect(onSelectBarrier).toHaveBeenCalledWith(42);
  });

  it('exposes both sections as labelled regions for AT navigation', () => {
    render(
      <NavigatorActionList
        delayed={[insight('MICU3-B012', 'delayed')]}
        barriers={[barrier]}
        onSelectLocation={vi.fn()}
        onSelectBarrier={vi.fn()}
      />,
    );
    expect(screen.getByRole('navigation', { name: 'Delayed locations and barriers' })).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Delayed and watched locations' })).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Open barriers' })).toBeInTheDocument();
  });
});
