// tests/js/commandCenter/OkrScoreboard.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { OkrScoreboard } from '@/Components/CommandCenter/OkrScoreboard';
import type { Objective } from '@/types/commandCenter';

const objectives: Objective[] = [
  { key: 'flow', title: 'Improve access & flow', keyResults: [
    { label: 'ED boarding', current: 168, target: 120, baseline: 192, progressPct: 33,
      status: 'warning', display: '168→<120 min' },
  ] },
];

describe('OkrScoreboard', () => {
  it('renders objective title and key-result display', () => {
    render(<OkrScoreboard objectives={objectives} />);
    expect(screen.getByRole('heading', { name: 'Improve access & flow' })).toBeInTheDocument();
    expect(screen.getByText('ED boarding')).toBeInTheDocument();
    expect(screen.getByText('168→<120 min')).toBeInTheDocument();
  });

  it('renders a progress bar reflecting progressPct', () => {
    render(<OkrScoreboard objectives={objectives} />);
    expect(screen.getByTestId('kr-progress-ED boarding')).toHaveStyle({ width: '33%' });
  });
});
