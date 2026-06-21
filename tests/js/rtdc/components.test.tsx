import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { BedNeedReadout } from '@/Components/RTDC/BedNeedReadout';
import { DischargeTierEntry } from '@/Components/RTDC/DischargeTierEntry';

describe('BedNeedReadout', () => {
  it('shows a deficit in critical styling when bed_need is positive', () => {
    render(<BedNeedReadout bedNeed={3} capacityNow={2} demandExpected={5} />);
    expect(screen.getByText(/short 3 beds/i)).toBeInTheDocument();
  });

  it('shows surplus when bed_need is negative', () => {
    render(<BedNeedReadout bedNeed={-2} capacityNow={5} demandExpected={3} />);
    expect(screen.getByText(/2 beds surplus/i)).toBeInTheDocument();
  });
});

describe('DischargeTierEntry', () => {
  it('emits changes for each confidence tier', () => {
    const onChange = vi.fn();
    render(<DischargeTierEntry definite={1} probable={0} possible={0} onChange={onChange} />);
    fireEvent.change(screen.getByLabelText(/definite/i), { target: { value: '4' } });
    expect(onChange).toHaveBeenCalledWith({ definite: 4, probable: 0, possible: 0 });
  });
});
