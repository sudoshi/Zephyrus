// tests/js/cockpit/Tile.test.tsx
//
// The Tile promotion (P2): proves the ISA-101 grey-baseline rule is APPLIED to
// metric values — normal/watch render near-white; only earned ok, warn, crit
// color the number — in both densities from the one policy source.
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MetricRow, Tile } from '@/Components/cockpit/Tile';
import { cockpitStatusStyle } from '@/Components/cockpit/statusStyle';
import type { CockpitMetricValue, CockpitState } from '@/types/cockpit';

const metric = (status: CockpitState, overrides: Partial<CockpitMetricValue> = {}): CockpitMetricValue => ({
  key: 'ed.nedocs',
  label: 'NEDOCS',
  value: 142,
  display: '142',
  unit: null,
  sub: '142 of 200 — severe',
  status,
  target: 100,
  direction: 'down',
  trend: [120, 131, 142],
  trendLabel: null,
  updatedAt: '2026-07-04T12:00:00+00:00',
  ...overrides,
});

describe('Tile', () => {
  it('keeps a normal value near-white (grey baseline — no status color on the number)', () => {
    render(<Tile metric={metric('normal', { key: 'flow.dbn', label: 'DBN' })} />);
    const value = screen.getByText('142');
    expect(value.className).toContain('text-healthcare-text-primary');
    expect(value.style.color).toBe('');
  });

  it('colors a crit value — crit has EARNED the color', () => {
    render(<Tile metric={metric('crit')} />);
    const value = screen.getByText('142');
    expect(value.className).not.toContain('text-healthcare-text-primary');
    expect(value.style.color).not.toBe('');
  });

  it('labels demo provenance at the point of display (D5)', () => {
    render(<Tile metric={metric('crit', { metadata: { provenance: 'demo' } })} />);
    expect(screen.getByTestId('provenance-badge')).toBeInTheDocument();
  });

  it('renders sub, target, sparkline, and the status glyph', () => {
    render(<Tile metric={metric('crit')} />);
    expect(screen.getByText('142 of 200 — severe')).toBeInTheDocument();
    expect(screen.getByText('Target 100')).toBeInTheDocument();
    expect(screen.getByTestId('sparkline-ed.nedocs')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: cockpitStatusStyle('crit').label })).toBeInTheDocument();
  });
});

describe('MetricRow', () => {
  it('applies the same valuePrimary ration as the card density', () => {
    const { rerender } = render(<MetricRow metric={metric('watch')} />);
    expect(screen.getByText('142').className).toContain('text-healthcare-text-primary');

    rerender(<MetricRow metric={metric('warn')} />);
    expect(screen.getByText('142').className).not.toContain('text-healthcare-text-primary');
    expect(screen.getByText('142').style.color).not.toBe('');
  });

  it('exposes glyph + label + value on one line', () => {
    render(<MetricRow metric={metric('warn')} />);
    expect(screen.getByTestId('cockpit-row-ed.nedocs')).toBeInTheDocument();
    expect(screen.getByText('NEDOCS')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Warning' })).toBeInTheDocument();
  });
});
