import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import NavigatorMinimap from '@/Components/PatientFlowNavigator/NavigatorMinimap';
import type { PatientFlowLocation, PatientFlowLocations, OccupancyInsight } from '@/features/patientFlowNavigator/types';
import type { CameraView } from '@/Components/PatientFlowNavigator/NavigatorScene';

/** E1 — the minimap places the plate + pips and turns a click into a fly-to. */

function loc(code: string, floor: number, x: number, z: number): PatientFlowLocation {
  return {
    facility_space_id: 1,
    location_code: code,
    source_location_code: code,
    name: code,
    category: 'bed',
    floor,
    unit_code: 'U',
    position_m: { x, y: 0, z },
  } as PatientFlowLocation;
}

const LOCATIONS: PatientFlowLocations = {
  a: loc('L1', 3, 0, 0),
  b: loc('L2', 3, 100, 0),
  c: loc('L3', 3, 100, 50),
  d: loc('L4', 2, 0, 0), // other floor — excluded when floor=3
};

function delayed(location: string, status: OccupancyInsight['primaryStatus']): OccupancyInsight {
  return {
    location,
    locationName: location,
    primaryStatus: status,
    position: { x: 100, y: 0, z: 0 },
    stayMinutes: 120,
    blockers: [],
    timers: [],
  } as unknown as OccupancyInsight;
}

const CAMERA: CameraView = {
  position: { x: 50, y: 100, z: 120 },
  target: { x: 50, y: 0, z: 25 },
};

function setup(overrides: Partial<React.ComponentProps<typeof NavigatorMinimap>> = {}) {
  const onNavigate = vi.fn();
  const onFitFloor = vi.fn();
  const utils = render(
    <NavigatorMinimap
      locations={LOCATIONS}
      floor="3"
      delayed={[delayed('L2', 'delayed')]}
      deviationLocations={new Set(['L3'])}
      getCameraView={() => CAMERA}
      getSelectionPoint={() => null}
      onNavigate={onNavigate}
      onFitFloor={onFitFloor}
      {...overrides}
    />,
  );
  return { onNavigate, onFitFloor, ...utils };
}

describe('NavigatorMinimap (E1)', () => {
  it('renders the plate for the current floor and labels itself', () => {
    setup();
    expect(screen.getByRole('img', { name: /floor 3/i })).toBeInTheDocument();
    const header = screen.getByRole('button', { name: /Floor 3/ });
    expect(header).toHaveAttribute('aria-expanded', 'true');
    expect(header).toHaveAttribute('title', 'Collapse minimap');
  });

  it('draws a delay triangle and a deviation square (shape, not color alone)', () => {
    const { container } = setup();
    expect(container.querySelector('.patient-flow-minimap-delay')).toBeInTheDocument();
    expect(container.querySelector('.patient-flow-minimap-deviation')).toBeInTheDocument();
  });

  it('turns a map click into a world fly-to', () => {
    const { onNavigate, container } = setup();
    const svg = container.querySelector('svg')!;
    // jsdom getBoundingClientRect is 0×0; the handler still fires with u=v=NaN→
    // clamp-free, but we only assert it routed a navigation intent.
    vi.spyOn(svg, 'getBoundingClientRect').mockReturnValue({
      left: 0, top: 0, width: 168, height: 168, right: 168, bottom: 168, x: 0, y: 0, toJSON: () => ({}),
    } as DOMRect);
    fireEvent.click(svg, { clientX: 84, clientY: 84 });
    expect(onNavigate).toHaveBeenCalledTimes(1);
    const point = onNavigate.mock.calls[0][0];
    expect(point).toHaveProperty('x');
    expect(point).toHaveProperty('z');
  });

  it('fits the floor from the header button', () => {
    const { onFitFloor } = setup();
    fireEvent.click(screen.getByRole('button', { name: 'Fit floor' }));
    expect(onFitFloor).toHaveBeenCalledTimes(1);
  });

  it('renders nothing when the floor has no placed locations', () => {
    const { container } = render(
      <NavigatorMinimap
        locations={{}}
        floor="9"
        delayed={[]}
        deviationLocations={new Set()}
        getCameraView={() => null}
        getSelectionPoint={() => null}
        onNavigate={vi.fn()}
        onFitFloor={vi.fn()}
      />,
    );
    expect(container).toBeEmptyDOMElement();
  });
});
