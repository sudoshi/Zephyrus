// tests/js/commandCenter/UnitHeatStrip.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { UnitHeatStrip } from '@/Components/CommandCenter/UnitHeatStrip';
import type { UnitCensus } from '@/types/commandCenter';

const units: UnitCensus[] = [
  { unitId: 1, name: '5 East', type: 'Med-Surg', staffed: 30, occupied: 27, blocked: 1,
    available: 2, occupancyPct: 90, acuityAdjustedPct: 92, status: 'warning' },
  { unitId: 3, name: 'MICU', type: 'ICU', staffed: 16, occupied: 15, blocked: 1,
    available: 0, occupancyPct: 94, acuityAdjustedPct: 97, status: 'critical' },
];

describe('UnitHeatStrip', () => {
  it('renders each unit name and occupancy', () => {
    render(<UnitHeatStrip units={units} />);
    expect(screen.getByText('5 East')).toBeInTheDocument();
    expect(screen.getByText('90%')).toBeInTheDocument();
    expect(screen.getByText('MICU')).toBeInTheDocument();
    expect(screen.getByText('94%')).toBeInTheDocument();
  });

  it('has an accessible label', () => {
    render(<UnitHeatStrip units={units} />);
    expect(screen.getByLabelText('Unit census heat map')).toBeInTheDocument();
  });

  it('shows an empty state (still labelled) when no units report', () => {
    render(<UnitHeatStrip units={[]} />);
    expect(screen.getByLabelText('Unit census heat map')).toBeInTheDocument();
    expect(screen.getByText('No units reporting census')).toBeInTheDocument();
  });
});
