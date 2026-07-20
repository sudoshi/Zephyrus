// tests/js/cockpit/CockpitOverview.test.tsx
//
// P2 acceptance in miniature: the full cockpit grammar renders from a §3.2
// fixture — CommandBar, ticker, 8 census chips, domain grid with the NEDOCS
// gauge, OKR scorecard — and the role switcher swaps OKR↔grid order without
// dropping content. Drill clicks surface through onDrillChange (D2 → P3).
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { CockpitOverview } from '@/Components/cockpit/CockpitOverview';
import type { CockpitMetricValue, CockpitSnapshotSections, CockpitState } from '@/types/cockpit';

const mv = (key: string, label: string, status: CockpitState = 'normal', overrides: Partial<CockpitMetricValue> = {}): CockpitMetricValue => ({
  key,
  label,
  value: 42,
  display: '42',
  unit: '%',
  sub: null,
  status,
  target: 85,
  direction: 'down',
  trend: [40, 41, 42],
  trendLabel: null,
  updatedAt: '2026-07-04T12:00:00+00:00',
  ...overrides,
});

const censusKeys = [
  'rtdc.census', 'rtdc.available', 'rtdc.pending_admits', 'rtdc.pending_dc',
  'rtdc.boarders', 'rtdc.icu_occupancy', 'rtdc.blocked_beds', 'rtdc.occupancy',
];

const domainKeys = [
  'rtdc', 'ed', 'periop', 'staffing', 'flow', 'quality', 'service', 'financial',
  'radiology', 'lab', 'pharmacy', 'home',
];

const sections: CockpitSnapshotSections = {
  asOf: '2026-07-04T12:00:00+00:00',
  facility: { name: 'Summit Regional Medical Center', licensedBeds: 500, level: 'Academic Medical Center' },
  capacityStatus: { level: 'Surge Level 2', code: 'yellow', status: 'warn' },
  census: censusKeys.map((key) => mv(key, key.split('.')[1])),
  alerts: [{ key: 'ed.nedocs', status: 'crit', text: 'ED OVERCROWDED — NEDOCS 142', provenance: 'demo' }],
  okrs: Array.from({ length: 9 }, (_, i) => ({
    ...mv(`okr.kr_${i}`, `Key result ${i}`),
    objective: 'Smooth Throughput',
    keyResult: `Key result ${i}`,
    owner: 'CNO',
  })),
  domains: Object.fromEntries(
    domainKeys.map((domain) => [
      domain,
      domain === 'ed'
        ? {
            provenance: 'partial' as const,
            gaugeKey: 'ed.nedocs',
            tiles: [
              mv('ed.nedocs', 'NEDOCS', 'crit', {
                value: 142, display: '142', unit: null,
                metadata: { provenance: 'demo', scale: 200 },
              }),
              mv('ed.in_dept', 'Patients in ED'),
            ],
          }
        : { provenance: 'live' as const, gaugeKey: null, tiles: [mv(`${domain}.a`, `${domain} metric`)] },
    ]),
  ),
};

const noop = () => undefined;

const renderOverview = (props: Partial<Parameters<typeof CockpitOverview>[0]> = {}) =>
  render(
    <CockpitOverview
      sections={sections}
      role="command"
      updatedLabel="just now"
      refreshing={false}
      aging={false}
      stale={false}
      onRefresh={noop}
      activeDrill={null}
      onDrillChange={noop}
      {...props}
    />,
  );

describe('CockpitOverview', () => {
  it('renders the full grammar: command bar, ticker, 8 census chips, 12 domain panels, 9 OKR cards, legend', () => {
    renderOverview();

    expect(screen.getByText('Summit Regional Medical Center')).toBeInTheDocument();
    expect(screen.getByTestId('capacity-status-pill')).toHaveTextContent('Surge Level 2');
    expect(screen.getByText('ED OVERCROWDED — NEDOCS 142')).toBeInTheDocument();
    expect(screen.getByTestId('cockpit-census-strip').children).toHaveLength(8);
    expect(screen.getByTestId('cockpit-domain-grid').children).toHaveLength(12);
    expect(screen.getByText('Hospital@Home')).toBeInTheDocument();
    expect(screen.getByText('Radiology')).toBeInTheDocument();
    expect(screen.getByTestId('cockpit-gauge-ed.nedocs')).toBeInTheDocument();
    expect(screen.getAllByTestId(/^okr-card-/)).toHaveLength(9);
    expect(screen.getByText('seeded demonstration data')).toBeInTheDocument();
  });

  it('command role reads operations-first; executive swaps the OKR scorecard above the grid', () => {
    const { unmount } = renderOverview();
    const gridFirst = screen.getByTestId('cockpit-domain-grid');
    const okrsAfter = screen.getByTestId('cockpit-okr-scorecard');
    expect(gridFirst.compareDocumentPosition(okrsAfter) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    unmount();

    renderOverview({ role: 'executive' });
    const okrsFirst = screen.getByTestId('cockpit-okr-scorecard');
    const gridAfter = screen.getByTestId('cockpit-domain-grid');
    expect(okrsFirst.compareDocumentPosition(gridAfter) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('panel headers and the OKR header are drill entry points (D2)', () => {
    const onDrillChange = vi.fn();
    renderOverview({ onDrillChange });

    fireEvent.click(screen.getByRole('button', { name: 'Open Emergency drill-down' }));
    expect(onDrillChange).toHaveBeenCalledWith('ed');

    fireEvent.click(screen.getByRole('button', { name: 'Open OKR scorecard drill-down' }));
    expect(onDrillChange).toHaveBeenCalledWith('okr');
  });

  it('renders wall mode as a static surface even when desk callbacks are supplied', () => {
    const onDrillChange = vi.fn();
    const onRefresh = vi.fn();
    const onOpenInbox = vi.fn();
    const onAlertEngage = vi.fn();
    renderOverview({
      wall: true,
      role: 'executive',
      activeDrill: 'rtdc',
      onDrillChange,
      onRefresh,
      onOpenInbox,
      onAlertEngage,
      inboxCount: 2,
      briefPanel: <button type="button">Desk-only brief action</button>,
    });

    const root = screen.getByTestId('cockpit-overview');
    expect(root.dataset.display).toBe('wall');
    expect(root.dataset.drill).toBeUndefined();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.queryByText('Desk-only brief action')).not.toBeInTheDocument();
    expect(screen.queryByText(/Inbox/)).not.toBeInTheDocument();
  });

  it('preserves refresh, inbox, alert engagement, and drill controls in desk mode', () => {
    const onDrillChange = vi.fn();
    const onRefresh = vi.fn();
    const onOpenInbox = vi.fn();
    const onAlertEngage = vi.fn();
    renderOverview({ onDrillChange, onRefresh, onOpenInbox, onAlertEngage, inboxCount: 2 });

    fireEvent.click(screen.getByRole('button', { name: 'Refresh data' }));
    fireEvent.click(screen.getByRole('button', { name: /Open action inbox/ }));
    fireEvent.click(screen.getByTestId('cockpit-alert-ed.nedocs'));
    fireEvent.click(screen.getByRole('button', { name: 'Open Emergency drill-down' }));

    expect(onRefresh).toHaveBeenCalledTimes(1);
    expect(onOpenInbox).toHaveBeenCalledTimes(1);
    expect(onAlertEngage).toHaveBeenCalledWith(sections.alerts[0]);
    expect(onDrillChange).toHaveBeenCalledWith('ed');
  });

  it('delegates the loud stale banner to app chrome (no longer inline)', () => {
    // P8 WS-6b moved the loud stale banner up to CommandCenter (StaleDataBanner)
    // so it fires at EVERY scope; the overview itself no longer renders it.
    renderOverview({ stale: true });
    expect(screen.queryByRole('button', { name: 'Retry now' })).not.toBeInTheDocument();
    expect(screen.queryByText(/Live updates interrupted/i)).not.toBeInTheDocument();
  });
});
