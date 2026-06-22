// tests/js/commandCenter/ForecastCurve.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ForecastCurve } from '@/Components/CommandCenter/ForecastCurve';
import type { ForecastState } from '@/types/commandCenter';

const forecast: ForecastState = {
  predictedDischarges24h: 22, predictedDischarges48h: 41, predictedEdArrivals: 60,
  predictedAdmissions: 18, netBedPosition: -3, surgeProbabilityPct: 38,
  occupancyCurve: [
    { hourOffset: 0, occupancyPct: 88, lowerPct: 85, upperPct: 91 },
    { hourOffset: 2, occupancyPct: 90, lowerPct: 87, upperPct: 93 },
  ],
  netBedByUnit: [{ unitId: 1, name: '5 East', net: -2 }],
};

describe('ForecastCurve', () => {
  it('renders the forecast summary numbers', () => {
    render(<ForecastCurve forecast={forecast} />);
    expect(screen.getByText(/Predicted discharges 24h/)).toBeInTheDocument();
    expect(screen.getByText('22')).toBeInTheDocument();
    expect(screen.getByText('-3')).toBeInTheDocument();
    expect(screen.getByText('38%')).toBeInTheDocument();
  });

  it('labels the chart region', () => {
    render(<ForecastCurve forecast={forecast} />);
    expect(screen.getByLabelText('24-hour occupancy forecast')).toBeInTheDocument();
  });
});
