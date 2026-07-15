// tests/js/cockpit/DataTable.test.tsx
import { describe, it, expect, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { DataTable } from '@/Components/cockpit/DataTable';
import type { Cell, Column } from '@/types/cockpit';

const columns: Column[] = [
  { key: 'unit', header: 'Unit' },
  { key: 'occ', header: 'Occupancy', align: 'right' },
  { key: 'state', header: 'State' },
  { key: 'esi', header: 'ESI' },
];

const rows: Record<string, Cell>[] = [
  {
    unit: { v: 'Med/Surg West', strong: true },
    occ: { bar: { pct: 96, status: 'critical', label: '96%' } },
    state: { chip: 'critical' },
    esi: { tag: { text: 'ESI 2', status: 'warning' } },
  },
  { unit: 'ICU', occ: '82%', state: { chip: 'success' }, esi: 'ESI 3' },
];

describe('DataTable', () => {
  it('renders a semantic table: sr-only caption + scope="col" headers', () => {
    render(<DataTable columns={columns} rows={rows} caption="Unit census detail" />);
    const table = screen.getByRole('table', { name: 'Unit census detail' });
    expect(table).toBeInTheDocument();
    for (const col of columns) {
      expect(screen.getByRole('columnheader', { name: new RegExp(col.header) })).toHaveAttribute('scope', 'col');
    }
  });

  it('renders every Cell variant: text, bar (meter), chip (role=img), tag (labelled pill)', () => {
    render(<DataTable columns={columns} rows={rows} caption="cells" />);
    expect(screen.getByText('Med/Surg West')).toBeInTheDocument();
    expect(screen.getByRole('meter', { name: '96%' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Critical' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'On target' })).toBeInTheDocument();
    expect(screen.getByLabelText('ESI 2 (Warning)')).toBeInTheDocument();
  });

  it('renders a governed workspace href as a semantic link cell', () => {
    render(<DataTable columns={[{ key: 'measure', header: 'Measure' }]} rows={[{
      measure: { v: '33.3%', strong: true, href: '/lab?priority=stat&source=cockpit' },
    }]} caption="Ancillary health" />);

    expect(screen.getByRole('link', { name: '33.3%' })).toHaveAttribute('href', '/lab?priority=stat&source=cockpit');
  });

  it('renders an empty string for a missing cell instead of crashing', () => {
    render(<DataTable columns={columns} rows={[{ unit: 'PACU' }]} caption="sparse" />);
    expect(screen.getByText('PACU')).toBeInTheDocument();
  });

  // P8 WS-4 — the drill cell: a bed/board row descends to the A2P patient lens.
  const drillColumns: Column[] = [{ key: 'bed', header: 'Bed' }, { key: 'complaint', header: 'Chief complaint' }];
  const drillRows: Record<string, Cell>[] = [
    { bed: { drill: { patientRef: 'ptok_abc123', text: 'Room 4', strong: true } }, complaint: 'Chest pain' },
  ];

  it('renders a drill cell as a patient-lens button and fires onRowDrill with its ptok', () => {
    const onRowDrill = vi.fn();
    render(<DataTable columns={drillColumns} rows={drillRows} caption="board" onRowDrill={onRowDrill} />);

    const button = screen.getByRole('button', { name: 'Open patient lens for Room 4' });
    expect(button).toHaveAttribute('aria-haspopup', 'dialog');
    fireEvent.click(button);
    expect(onRowDrill).toHaveBeenCalledTimes(1);
    expect(onRowDrill).toHaveBeenCalledWith('ptok_abc123');
  });

  it('renders a drill cell as plain text (no dead affordance) when no handler is given', () => {
    render(<DataTable columns={drillColumns} rows={drillRows} caption="static board" />);
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.getByText('Room 4')).toBeInTheDocument();
  });

  // P8 density — the spark cell: a small-multiple trend inside a board row.
  const sparkColumns: Column[] = [{ key: 'unit', header: 'Unit' }, { key: 'census', header: '24h census' }];

  it('renders a spark cell as an inline sparkline', () => {
    const rows: Record<string, Cell>[] = [
      { unit: 'MICU', census: { spark: { data: [12, 13, 14, 14, 15], status: 'warning' } } },
    ];
    render(<DataTable columns={sparkColumns} rows={rows} caption="line board" />);
    expect(screen.getByTestId('cell-spark').querySelector('svg')).toBeInTheDocument();
  });

  it('renders an em-dash for a spark cell with fewer than 2 points, never a broken chart', () => {
    const rows: Record<string, Cell>[] = [{ unit: 'MICU', census: { spark: { data: [12] } } }];
    render(<DataTable columns={sparkColumns} rows={rows} caption="sparse line board" />);
    expect(screen.queryByTestId('cell-spark')).not.toBeInTheDocument();
    expect(screen.getByText('—')).toBeInTheDocument();
  });
});
