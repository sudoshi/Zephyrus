import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import NavigatorLegend from '@/Components/PatientFlowNavigator/NavigatorLegend';
import type { PatientLayerState } from '@/features/patientFlowNavigator/types';

const ALL_ON: PatientLayerState = {
  base: true,
  tokens: true,
  trails: true,
  heat: true,
  ghosts: true,
  barriers: true,
  rounds: true,
};

describe('NavigatorLegend (E-1)', () => {
  it('collapses to a Key pill by default and expands on demand', () => {
    render(<NavigatorLegend layers={ALL_ON} />);

    const toggle = screen.getByRole('button', { name: 'Key' });
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    expect(screen.queryByRole('region', { name: 'Scene key' })).not.toBeInTheDocument();

    fireEvent.click(toggle);
    expect(toggle).toHaveAttribute('aria-expanded', 'true');
    const panel = screen.getByRole('region', { name: 'Scene key' });
    expect(panel).toBeInTheDocument();

    // Sections and the shape grammar are present.
    expect(screen.getByText('People & movement')).toBeInTheDocument();
    expect(screen.getByText('Barriers')).toBeInTheDocument();
    expect(screen.getByText('Building')).toBeInTheDocument();
    expect(screen.getByText('Open barrier — 48h+')).toBeInTheDocument();

    fireEvent.click(toggle);
    expect(screen.queryByRole('region', { name: 'Scene key' })).not.toBeInTheDocument();
  });

  it('dims entries for layers that are toggled off and says (hidden)', () => {
    render(<NavigatorLegend layers={{ ...ALL_ON, rounds: false }} />);

    fireEvent.click(screen.getByRole('button', { name: 'Key' }));
    expect(screen.getByText('Round stop (hidden)')).toBeInTheDocument();
    // A visible layer's entries carry no suffix.
    expect(screen.getByText('Patient')).toBeInTheDocument();
    expect(screen.queryByText('Patient (hidden)')).not.toBeInTheDocument();
  });
});
