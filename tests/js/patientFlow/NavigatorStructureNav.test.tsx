import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import NavigatorStructureNav from '@/Components/PatientFlowNavigator/NavigatorStructureNav';
import type { PatientFlowLocation, PatientFlowLocations } from '@/features/patientFlowNavigator/types';

/** E3 — the keyboard-walkable structure tree over the action-list seam. */

function loc(code: string, name: string, unit: string, x: number): PatientFlowLocation {
  return {
    facility_space_id: 1,
    location_code: code,
    source_location_code: code,
    name,
    category: 'bed',
    floor: 3,
    unit_code: unit,
    position_m: { x, y: 0, z: 0 },
  } as PatientFlowLocation;
}

const LOCATIONS: PatientFlowLocations = {
  a: loc('3-5E-01', 'Bed 01', '5E', 10),
  b: loc('3-5E-02', 'Bed 02', '5E', 12),
};

function setup(overrides: Partial<React.ComponentProps<typeof NavigatorStructureNav>> = {}) {
  const onSelectBed = vi.fn();
  const onFrame = vi.fn();
  render(
    <NavigatorStructureNav
      locations={LOCATIONS}
      onSelectBed={onSelectBed}
      onFrame={onFrame}
      {...overrides}
    />,
  );
  return { onSelectBed, onFrame };
}

describe('NavigatorStructureNav (E3)', () => {
  it('is collapsed by default and reveals the tree on toggle', () => {
    setup();
    expect(screen.queryByRole('tree')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Structure/ }));
    expect(screen.getByRole('tree', { name: 'Building structure' })).toBeInTheDocument();
  });

  it('expands a floor with ArrowRight, then descends to the unit', () => {
    setup();
    fireEvent.click(screen.getByRole('button', { name: /Structure/ }));

    const floor = screen.getByRole('treeitem', { name: /Floor 3/ });
    expect(floor).toHaveAttribute('aria-expanded', 'false');

    const floorButton = screen.getByRole('button', { name: /Floor 3/ });
    fireEvent.keyDown(floorButton, { key: 'ArrowRight' });
    // Floor now expanded — the 5E unit row appears.
    expect(screen.getByRole('button', { name: /5E/ })).toBeInTheDocument();
  });

  it('selects a bed through the shared seam and frames it', () => {
    const { onSelectBed, onFrame } = setup();
    fireEvent.click(screen.getByRole('button', { name: /Structure/ }));
    // Expand floor then unit by activating them.
    fireEvent.click(screen.getByRole('button', { name: /Floor 3/ }));
    fireEvent.click(screen.getByRole('button', { name: /5E/ }));

    fireEvent.click(screen.getByRole('button', { name: /Bed 01/ }));
    expect(onSelectBed).toHaveBeenCalledWith('3-5E-01');
    expect(onFrame).toHaveBeenCalled();
  });

  it('collapses an expanded floor with ArrowLeft', () => {
    setup();
    fireEvent.click(screen.getByRole('button', { name: /Structure/ }));
    fireEvent.click(screen.getByRole('button', { name: /Floor 3/ })); // expands + frames
    expect(screen.getByRole('button', { name: /5E/ })).toBeInTheDocument();

    fireEvent.keyDown(screen.getByRole('button', { name: /Floor 3/ }), { key: 'ArrowLeft' });
    expect(screen.queryByRole('button', { name: /5E/ })).not.toBeInTheDocument();
  });

  it('renders nothing when there are no locations', () => {
    const { container } = render(
      <NavigatorStructureNav locations={{}} onSelectBed={vi.fn()} onFrame={vi.fn()} />,
    );
    expect(container).toBeEmptyDOMElement();
  });
});
