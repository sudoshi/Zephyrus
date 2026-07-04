// tests/js/cockpit/DrillModal.test.tsx
//
// P3 acceptance in miniature: the A2 drill renders a §3.3 payload (KPI strip
// of Tiles + §6.4 Cell-grammar tables) inside the Radix dialog, closes via
// ESC / the close button, and degrades to an in-modal error card with retry
// when the payload breaks contract — never a crash over the cockpit.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { DrillModal } from '@/Components/cockpit/DrillModal';
import { fetchCockpitDrill } from '@/features/cockpit/api';

vi.mock('@/features/cockpit/api', () => ({
  fetchCockpitSnapshot: vi.fn(),
  fetchCockpitDrill: vi.fn(),
}));
const mockedFetch = vi.mocked(fetchCockpitDrill);

const kpi = (key: string, status = 'normal') => ({
  key,
  label: key,
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
});

const payload = {
  domain: 'ed',
  title: 'Emergency Department — Track Board',
  sub: 'NEDOCS 142 — Overcrowded',
  asOf: '2026-07-04T12:00:00+00:00',
  kpis: [kpi('ed.nedocs', 'crit'), kpi('ed.in_dept'), kpi('ed.boarders', 'warn')],
  tables: [
    {
      caption: 'Active track board',
      columns: [
        { key: 'room', header: 'Bed', align: 'left' },
        { key: 'esi', header: 'ESI', align: 'left' },
        { key: 'occupancy', header: 'Occupancy', align: 'left' },
        { key: 'status', header: '', align: 'right' },
      ],
      rows: [
        {
          room: { v: 'ED-12', strong: true },
          esi: { tag: { text: 'ESI 2', status: 'critical' } },
          occupancy: { bar: { pct: 88, status: 'warning', label: '88%' } },
          status: { chip: 'critical' },
        },
      ],
    },
    { caption: 'Acuity mix', columns: [{ key: 'level', header: 'ESI level' }], rows: [{ level: 'ESI 1' }] },
  ],
  drilldownHref: '/api/command-center/drilldown',
};

function renderModal(domain: 'ed' | null = 'ed', onClose = () => undefined) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <DrillModal domain={domain} onClose={onClose} />
    </QueryClientProvider>,
  );
}

describe('DrillModal', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders the drill grammar: title, sub, KPI tiles, and every Cell-grammar table', async () => {
    mockedFetch.mockResolvedValue(payload);
    renderModal();

    await waitFor(() =>
      expect(screen.getByText('Emergency Department — Track Board')).toBeInTheDocument(),
    );
    expect(screen.getByText('NEDOCS 142 — Overcrowded')).toBeInTheDocument();
    expect(screen.getAllByTestId(/^cockpit-tile-/)).toHaveLength(3);
    expect(screen.getAllByTestId('cockpit-drill-table')).toHaveLength(2);
    // Cell grammar renders: strong text, tag, chip all present.
    expect(screen.getByText('ED-12')).toBeInTheDocument();
    expect(screen.getByText('ESI 2')).toBeInTheDocument();
    // Accent = worst KPI (crit) — earned urgency on the header bar.
    expect(screen.getByTestId('cockpit-drill-modal').dataset.accent).toBe('critical');
  });

  it('renders nothing when no drill is open', () => {
    renderModal(null);
    expect(screen.queryByTestId('cockpit-drill-modal')).not.toBeInTheDocument();
    expect(mockedFetch).not.toHaveBeenCalled();
  });

  it('closes via ESC and via the close button (Radix wiring)', async () => {
    mockedFetch.mockResolvedValue(payload);
    const onClose = vi.fn();
    renderModal('ed', onClose);
    await waitFor(() => expect(screen.getByTestId('cockpit-drill-modal')).toBeInTheDocument());

    fireEvent.keyDown(screen.getByTestId('cockpit-drill-modal'), { key: 'Escape' });
    expect(onClose).toHaveBeenCalledTimes(1);

    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(onClose).toHaveBeenCalledTimes(2);
  });

  it('degrades to an in-modal error card with retry when the payload breaks contract', async () => {
    mockedFetch.mockResolvedValue({ domain: 'ed', title: 'broken' }); // missing kpis/tables
    renderModal();

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    expect(screen.getByText('Could not load this drill-down.')).toBeInTheDocument();

    mockedFetch.mockResolvedValue(payload);
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() =>
      expect(screen.getByText('Emergency Department — Track Board')).toBeInTheDocument(),
    );
  });

  it('shows the error card on a failed request too (404 unknown domain)', async () => {
    mockedFetch.mockRejectedValue(new Error('Request failed with status code 404'));
    renderModal();

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    expect(screen.getByText(/Request failed/)).toBeInTheDocument();
  });
});
