// tests/js/cockpit/ScopedFaceView.test.tsx
//
// P8 WS-2b acceptance in miniature: a non-house mount renders the scope's own
// altitude face (KPI Tiles + §6.4 Cell-grammar tables) from /api/cockpit/face,
// shows the honest empty state when there is no live census, bounces a token
// that resolved to house to the house cockpit, and degrades to an in-place
// error card with retry when the payload breaks contract — never a crash.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ScopedFaceView } from '@/Components/cockpit/ScopedFaceView';
import { fetchCockpitFace } from '@/features/cockpit/api';

vi.mock('@/features/cockpit/api', () => ({
  fetchCockpitSnapshot: vi.fn(),
  fetchCockpitDrill: vi.fn(),
  fetchCockpitFace: vi.fn(),
}));
const mockedFetch = vi.mocked(fetchCockpitFace);

const kpi = (key: string, status = 'normal') => ({
  key,
  label: key,
  value: 42,
  display: '42',
  unit: '%',
  sub: null,
  status,
  target: null,
  direction: 'neutral',
  trend: [],
  trendLabel: null,
  updatedAt: '2026-07-04T12:00:00+00:00',
});

const unitFace = {
  scope: { level: 'unit', key: 'MICU', label: 'Medical ICU', token: 'unit:MICU' },
  render: 'face',
  title: 'Medical ICU',
  sub: 'icu — 88% occupancy',
  asOf: '2026-07-04T12:00:00+00:00',
  kpis: [kpi('unit.occupancy', 'crit'), kpi('unit.staffed'), kpi('unit.available', 'warn')],
  tables: [
    {
      caption: 'Unit capacity',
      columns: [
        { key: 'unit', header: 'Unit', align: 'left' },
        { key: 'occupancy', header: 'Occupancy', align: 'left' },
        { key: 'status', header: '', align: 'right' },
      ],
      rows: [
        {
          unit: { v: 'MICU', strong: true },
          occupancy: { bar: { pct: 88, status: 'warning', label: '88%' } },
          status: { chip: 'critical' },
        },
      ],
    },
  ],
};

const emptyFace = {
  scope: { level: 'unit', key: 'MICU', label: 'Medical ICU', token: 'unit:MICU' },
  render: 'face',
  title: 'Medical ICU',
  sub: 'No live census for this unit yet',
  asOf: '2026-07-04T12:00:00+00:00',
  kpis: [],
  tables: [],
};

const gridFace = {
  scope: { level: 'house', key: null, label: 'Summit Regional Medical Center', token: 'house' },
  render: 'grid',
  title: 'Summit Regional Medical Center',
  sub: 'House-wide operations overview',
};

function renderFace(scopeToken = 'unit:MICU', onPatientDrill?: (ref: string) => void) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ScopedFaceView scopeToken={scopeToken} onPatientDrill={onPatientDrill} />
    </QueryClientProvider>,
  );
}

// P8 WS-4 — a face whose board carries a drill cell (a bed → patient descent).
const drillFace = {
  scope: { level: 'unit', key: 'MICU', label: 'Medical ICU', token: 'unit:MICU' },
  render: 'face',
  title: 'Medical ICU',
  sub: 'icu census',
  asOf: '2026-07-04T12:00:00+00:00',
  kpis: [kpi('unit.occupancy')],
  tables: [
    {
      caption: 'Patients',
      columns: [{ key: 'bed', header: 'Bed', align: 'left' }],
      rows: [{ bed: { drill: { patientRef: 'ptok_micu001', text: 'Bed 3', strong: true } } }],
    },
  ],
};

describe('ScopedFaceView', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders the altitude face: breadcrumb, title, KPI tiles, and the Cell-grammar table', async () => {
    mockedFetch.mockResolvedValue(unitFace);
    renderFace();

    await waitFor(() => expect(screen.getByText('Medical ICU')).toBeInTheDocument());
    expect(screen.getByText('icu — 88% occupancy')).toBeInTheDocument();
    // Altitude breadcrumb chip + house link (scoped to the nav — the capacity
    // table also has a "Unit" column header).
    const breadcrumb = screen.getByRole('navigation');
    expect(within(breadcrumb).getByText('Unit')).toBeInTheDocument();
    expect(within(breadcrumb).getByRole('link', { name: 'House' })).toHaveAttribute('href', '/dashboard');
    expect(screen.getAllByTestId(/^cockpit-tile-/)).toHaveLength(3);
    expect(screen.getAllByTestId('cockpit-face-table')).toHaveLength(1);
    // Cell grammar renders the strong unit cell + chip.
    expect(screen.getByText('MICU')).toBeInTheDocument();
    // Header accent = worst KPI (crit) — earned urgency.
    expect(screen.getByText('Medical ICU').closest('header')?.dataset.accent).toBe('critical');
  });

  it('descends a bed/board drill cell to the patient lens (kills the no-op)', async () => {
    const onPatientDrill = vi.fn();
    mockedFetch.mockResolvedValue(drillFace);
    renderFace('unit:MICU', onPatientDrill);

    const bed = await screen.findByRole('button', { name: 'Open patient lens for Bed 3' });
    fireEvent.click(bed);
    expect(onPatientDrill).toHaveBeenCalledTimes(1);
    expect(onPatientDrill).toHaveBeenCalledWith('ptok_micu001');
  });

  it('shows the honest empty state when there is no live census (never fabricated)', async () => {
    mockedFetch.mockResolvedValue(emptyFace);
    renderFace();

    await waitFor(() => expect(screen.getByText('No live census for this mount yet.')).toBeInTheDocument());
    expect(screen.queryByTestId('cockpit-face-kpis')).not.toBeInTheDocument();
    expect(screen.queryByTestId('cockpit-face-table')).not.toBeInTheDocument();
  });

  it('bounces to the house cockpit when the token resolved to house (render: grid)', async () => {
    mockedFetch.mockResolvedValue(gridFace);
    renderFace('unit:GHOST');

    await waitFor(() =>
      expect(screen.getByText('Mounted at Summit Regional Medical Center.')).toBeInTheDocument(),
    );
    const link = screen.getByRole('link', { name: 'Open the house cockpit' });
    expect(link).toHaveAttribute('href', '/dashboard');
  });

  it('degrades to an in-place error card with retry when the payload breaks contract', async () => {
    mockedFetch.mockResolvedValue({ scope: gridFace.scope, render: 'face', title: 'broken' }); // missing kpis/tables
    renderFace();

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    expect(screen.getByText('Could not load this mount.')).toBeInTheDocument();

    mockedFetch.mockResolvedValue(unitFace);
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByText('icu — 88% occupancy')).toBeInTheDocument());
  });

  it('shows the error card on a failed request too', async () => {
    mockedFetch.mockRejectedValue(new Error('Request failed with status code 500'));
    renderFace();

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    expect(screen.getByText(/Request failed/)).toBeInTheDocument();
  });
});
