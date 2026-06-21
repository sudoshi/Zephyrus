import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { RecommendationCard } from '@/Components/RTDC/RecommendationCard';

const rec = {
  bed_id: 1, bed_label: '5E-01', unit_id: 1, unit_name: '5 East', score: 30,
  breakdown: [{ term: 'acuity_headroom', value: 20 }, { term: 'isolation_fragmentation', value: -25 }],
  chips: [{ label: 'Ratio OK', ok: true }, { label: 'Acuity headroom: 4', ok: true }],
};

describe('RecommendationCard', () => {
  it('shows bed, score, chips, breakdown, and the not-automated safety note', () => {
    render(<RecommendationCard rec={rec} isTop runnerUpDelta={12} onAccept={() => {}} />);
    expect(screen.getByText('5E-01')).toBeInTheDocument();
    expect(screen.getByText(/5 East/)).toBeInTheDocument();
    expect(screen.getByText('Ratio OK')).toBeInTheDocument();
    expect(screen.getByText(/acuity_headroom/)).toBeInTheDocument();
    expect(screen.getByText(/not an automated assignment/i)).toBeInTheDocument();
  });

  it('fires onAccept with the bed id', () => {
    const onAccept = vi.fn();
    render(<RecommendationCard rec={rec} isTop runnerUpDelta={12} onAccept={onAccept} />);
    fireEvent.click(screen.getByRole('button', { name: /accept/i }));
    expect(onAccept).toHaveBeenCalledWith(1);
  });
});
