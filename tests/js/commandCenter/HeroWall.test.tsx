// tests/js/commandCenter/HeroWall.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { HeroWall } from '@/Components/CommandCenter/HeroWall';
import type { StrainState, KpiMetric, Objective } from '@/types/commandCenter';

const strain: StrainState = {
  level: 2, label: 'Surge Level 2', status: 'warning', previousLevel: 1,
  drivers: [{ label: 'Census', value: '88%', status: 'warning' }], updatedAtIso: 'x',
};
const heroMetrics: KpiMetric[] = [{
  key: 'occupancy', label: 'Occupancy', value: 88, unit: '%', display: '88%', target: 85,
  targetDisplay: '≤85%', status: 'warning', trajectory: null, drillHref: null, definition: 'd',
}];
const objectives: Objective[] = [{
  key: 'flow', title: 'Improve access & flow',
  keyResults: [{ label: 'ED boarding', current: 168, target: 120, baseline: 192, progressPct: 33,
    status: 'warning', display: '168→<120 min' }],
}];

describe('HeroWall', () => {
  it('command mode shows strain + hero tiles', () => {
    render(<HeroWall role="command" strain={strain} heroMetrics={heroMetrics} objectives={objectives} />);
    expect(screen.getByRole('status')).toHaveAttribute('aria-label', expect.stringContaining('Surge Level 2'));
    expect(screen.getByText('Occupancy')).toBeInTheDocument();
    expect(screen.queryByLabelText('OKR scoreboard')).toBeNull();
  });

  it('executive mode shows the OKR scoreboard instead of strain', () => {
    render(<HeroWall role="executive" strain={strain} heroMetrics={heroMetrics} objectives={objectives} />);
    expect(screen.getByLabelText('OKR scoreboard')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Improve access & flow' })).toBeInTheDocument();
    expect(screen.queryByRole('status')).toBeNull();
  });
});
