// tests/js/commandCenter/StrainIndex.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { StrainIndex } from '@/Components/CommandCenter/StrainIndex';
import type { StrainState } from '@/types/commandCenter';

const strain: StrainState = {
  level: 2, label: 'Surge Level 2', status: 'warning', previousLevel: 1,
  drivers: [
    { label: 'Occupancy', value: '88%', status: 'warning' },
    { label: 'ED boarding', value: '4', status: 'warning' },
  ],
  updatedAtIso: '2026-06-22T12:00:00Z',
};

describe('StrainIndex', () => {
  it('renders the surge label and drivers', () => {
    render(<StrainIndex strain={strain} />);
    expect(screen.getByText('Surge Level 2')).toBeInTheDocument();
    expect(screen.getByText('Occupancy')).toBeInTheDocument();
    expect(screen.getByText('88%')).toBeInTheDocument();
  });

  it('exposes an accessible status label', () => {
    render(<StrainIndex strain={strain} />);
    expect(screen.getByRole('status')).toHaveAttribute('aria-label', expect.stringContaining('Surge Level 2'));
  });
});
