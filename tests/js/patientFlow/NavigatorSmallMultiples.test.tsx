import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import NavigatorSmallMultiples from '@/Components/PatientFlowNavigator/NavigatorSmallMultiples';
import type { PatientFlowEvent, PatientFlowLocation, PatientFlowLocations } from '@/features/patientFlowNavigator/types';

/** E4 — the hourly small-multiples analysis card. */

function loc(code: string, x: number, z: number): PatientFlowLocation {
  return {
    facility_space_id: 1,
    location_code: code,
    source_location_code: code,
    name: code,
    category: 'bed',
    floor: 3,
    unit_code: 'U',
    position_m: { x, y: 0, z },
  } as PatientFlowLocation;
}

const LOCATIONS: PatientFlowLocations = { A: loc('A', 0, 0), B: loc('B', 100, 100) };
const END = Date.parse('2026-07-27T12:00:00Z');

function ev(patientId: string, to: string, at: number): PatientFlowEvent {
  return {
    event_id: `${patientId}-${at}`,
    event_category: 'movement',
    event_type: 'move',
    patient_id: patientId,
    patient_display_id: patientId,
    encounter_id: `enc-${patientId}`,
    occurred_at: new Date(at).toISOString(),
    to_location: to,
    location_floor: 3,
  } as PatientFlowEvent;
}

const TRACKS = new Map<string, PatientFlowEvent[]>([
  ['p1', [ev('p1', 'A', END - 30 * 60_000)]],
]);

function setup(overrides: Partial<React.ComponentProps<typeof NavigatorSmallMultiples>> = {}) {
  const onToggleFloorHeat = vi.fn();
  const utils = render(
    <NavigatorSmallMultiples
      tracks={TRACKS}
      locations={LOCATIONS}
      floor="3"
      endMs={END}
      floorHeatOn={false}
      onToggleFloorHeat={onToggleFloorHeat}
      {...overrides}
    />,
  );
  return { onToggleFloorHeat, ...utils };
}

describe('NavigatorSmallMultiples (E4)', () => {
  it('is collapsed by default and reveals six hourly plates on toggle', () => {
    setup();
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Last 6 h/ }));
    expect(screen.getAllByRole('img')).toHaveLength(6);
  });

  it('labels each plate as analysis, never live', () => {
    setup();
    fireEvent.click(screen.getByRole('button', { name: /Last 6 h/ }));
    expect(screen.getByText(/analysis, not live/)).toBeInTheDocument();
  });

  it('routes the on-floor flatten toggle and reflects its pressed state', () => {
    const { onToggleFloorHeat } = setup({ floorHeatOn: true });
    fireEvent.click(screen.getByRole('button', { name: /Last 6 h/ }));
    const toggle = screen.getByRole('button', { name: 'On floor' });
    expect(toggle).toHaveAttribute('aria-pressed', 'true');
    fireEvent.click(toggle);
    expect(onToggleFloorHeat).toHaveBeenCalledTimes(1);
  });

  it('renders nothing when the floor has no placed locations', () => {
    const { container } = render(
      <NavigatorSmallMultiples
        tracks={TRACKS}
        locations={{}}
        floor="9"
        endMs={END}
        floorHeatOn={false}
        onToggleFloorHeat={vi.fn()}
      />,
    );
    expect(container).toBeEmptyDOMElement();
  });
});
