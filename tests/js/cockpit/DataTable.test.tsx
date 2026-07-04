// tests/js/cockpit/DataTable.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
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

  it('renders an empty string for a missing cell instead of crashing', () => {
    render(<DataTable columns={columns} rows={[{ unit: 'PACU' }]} caption="sparse" />);
    expect(screen.getByText('PACU')).toBeInTheDocument();
  });
});
