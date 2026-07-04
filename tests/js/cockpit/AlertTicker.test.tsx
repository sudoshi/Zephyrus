// tests/js/cockpit/AlertTicker.test.tsx
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { AlertTicker } from '@/Components/cockpit/AlertTicker';
import type { CockpitAlert } from '@/types/cockpit';

const alerts: CockpitAlert[] = [
  { key: 'ed.nedocs', status: 'crit', text: 'ED OVERCROWDED — NEDOCS 142', provenance: 'demo' },
  { key: 'rtdc.boarders', status: 'crit', text: 'ED boarding — 7 admitted patients holding in the ED' },
  { key: 'staffing.open_shifts', status: 'warn', text: 'Staffing gap — 9 open shifts next 24h' },
];

describe('AlertTicker', () => {
  it('renders every alert in the server order (crit-first is decided server-side)', () => {
    render(<AlertTicker alerts={alerts} />);
    const texts = alerts.map((a) => screen.getByText(a.text));
    expect(texts).toHaveLength(3);
    // DOM order must preserve payload order — the ration/sort is server truth.
    expect(
      texts[0].compareDocumentPosition(texts[1]) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(
      texts[1].compareDocumentPosition(texts[2]) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
  });

  it('badges demo-provenance alerts and only those', () => {
    render(<AlertTicker alerts={alerts} />);
    expect(screen.getAllByTestId('provenance-badge')).toHaveLength(1);
  });

  it('keeps a stable, quiet row when there are no alerts (no layout shift on the wall)', () => {
    render(<AlertTicker alerts={[]} />);
    expect(screen.getByTestId('cockpit-alert-ticker')).toBeInTheDocument();
    expect(screen.getByText(/No active alerts/)).toBeInTheDocument();
  });

  it('does not marquee when the strip fits (jsdom: zero overflow)', () => {
    render(<AlertTicker alerts={alerts} />);
    expect(document.querySelector('.cockpit-marquee-track')).toBeNull();
  });
});
