import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { OperationalDataError, SourceFreshnessBanner } from '@/Components/Operations/OperationalDataState';

describe('operational data states', () => {
  it('renders a retryable error instead of an inferred empty state', () => {
    const retry = vi.fn();
    render(<OperationalDataError title="Transport worklist unavailable" onRetry={retry} />);

    expect(screen.getByRole('alert')).toHaveTextContent('no empty or healthy state is being inferred');
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    expect(retry).toHaveBeenCalledOnce();
  });

  it('labels stale synthetic data', () => {
    render(<SourceFreshnessBanner source={{
      key: 'prod.transport_operations',
      label: 'Transport operations data',
      status: 'stale',
      generated_at: '2026-07-09T12:00:00Z',
      last_observed_at: '2026-07-04T12:00:00Z',
      age_minutes: 7200,
      expected_cadence_minutes: 15,
      stale_after_minutes: 60,
      synthetic: true,
      message: 'Transport operations data is stale.',
    }} />);

    expect(screen.getByRole('alert')).toHaveTextContent('stale');
    expect(screen.getByRole('alert')).toHaveTextContent('120 hr 0 min 0 sec ago');
    expect(screen.getByRole('alert')).not.toHaveTextContent('7200 minutes');
    expect(screen.getByText('Synthetic')).toBeInTheDocument();
  });
});
