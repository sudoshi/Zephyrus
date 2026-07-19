import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import NavigatorFeed from '@/Components/PatientFlowNavigator/NavigatorFeed';
import type { PatientFlowEvent } from '@/features/patientFlowNavigator/types';

function event(overrides: Partial<PatientFlowEvent>): PatientFlowEvent {
  return {
    event_id: 'e-1',
    event_category: 'movement',
    event_type: 'transfer',
    patient_id: 'p-1',
    patient_display_id: 'PT-0001',
    encounter_id: 'enc-1',
    occurred_at: '2026-07-19T12:00:00Z',
    from_location: '3W-01',
    to_location: '5E-12',
    ...overrides,
  } as PatientFlowEvent;
}

describe('NavigatorFeed selection (H1.2)', () => {
  it('rows select the patient when a handler is provided', () => {
    const onSelectPatient = vi.fn();
    render(
      <NavigatorFeed
        feed={[event({})]}
        redactIdentity={false}
        onSelectPatient={onSelectPatient}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: /PT-0001 transfer/ }));
    expect(onSelectPatient).toHaveBeenCalledWith('p-1');
  });

  it('renders plain rows (no buttons) for aggregate lenses', () => {
    render(<NavigatorFeed feed={[event({})]} redactIdentity />);
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.getByText(/transfer/)).toBeInTheDocument();
  });
});
