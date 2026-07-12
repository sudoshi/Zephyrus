// resources/js/Components/cockpit/DataTable.tsx
//
// The single drill-detail table grammar (Zephyrus 2.0 P0), implementing the
// spec §6.4 typed Cell union so the P1 DrillBuilder payload renders purely
// presentationally. Semantic table: sr-only caption, scope="col" headers,
// tabular-nums numeric columns; bar → MeterBar, chip → StatusChip, tag →
// bordered pill — every status cell carries shape + label, never color alone.
import { useId } from 'react';
import type { StatusLevel } from '@/types/commandCenter';
import type { Cell, Column } from '@/types/cockpit';
import { statusStyle } from './statusStyle';
import { MeterBar } from './MeterBar';
import { Sparkline } from './Sparkline';
import { StatusChip } from './StatusChip';

export interface DataTableProps {
  columns: Column[];
  rows: Record<string, Cell>[];
  caption: string;
  /**
   * P8 WS-4 — when set, a `drill` cell renders as a button that descends to the
   * A2P patient lens with its ptok. Absent (the default) keeps drill cells as
   * plain text, so a static / wall render never exposes a dead affordance.
   */
  onRowDrill?: (patientRef: string) => void;
}

// The §6.4 spark cell — a small-multiple trend inside a board row. useId keeps
// the SVG gradient id unique per instance (colons stripped: url(#…) fragment
// references break on them in some engines). Decorative; the numeric columns
// beside it are the accessible values.
function SparkCell({ data, status }: { data: number[]; status?: StatusLevel }) {
  const id = useId().replace(/:/g, '');
  if (data.length < 2) {
    return <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">—</span>;
  }
  return (
    <span className="inline-block w-20 align-middle" data-testid="cell-spark">
      <Sparkline data={data} status={status ?? 'neutral'} id={id} w={80} h={24} className="h-6 w-full" />
    </span>
  );
}

function CellContent({ cell, onRowDrill }: { cell: Cell; onRowDrill?: (patientRef: string) => void }) {
  if (typeof cell === 'string' || typeof cell === 'number') {
    return <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{cell}</span>;
  }
  if ('drill' in cell) {
    const label = cell.drill.text;
    if (!onRowDrill) {
      return (
        <span className={`text-healthcare-text-primary dark:text-healthcare-text-primary-dark ${cell.drill.strong ? 'font-semibold' : ''}`}>
          {label}
        </span>
      );
    }
    return (
      <button
        type="button"
        onClick={() => onRowDrill(cell.drill.patientRef)}
        aria-haspopup="dialog"
        aria-label={`Open patient lens for ${label}`}
        className={`-mx-1 rounded px-1 text-left text-healthcare-primary underline-offset-2 hover:underline dark:text-healthcare-primary-dark ${cell.drill.strong ? 'font-semibold' : 'font-medium'}`}
      >
        {label}
      </button>
    );
  }
  if ('bar' in cell) {
    return (
      <span className="flex items-center gap-2">
        <span className="w-24 shrink-0">
          <MeterBar
            pct={cell.bar.pct}
            status={cell.bar.status}
            label={cell.bar.label ?? `${Math.round(cell.bar.pct)} percent`}
          />
        </span>
        {cell.bar.label && (
          <span className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {cell.bar.label}
          </span>
        )}
      </span>
    );
  }
  if ('spark' in cell) {
    return <SparkCell data={cell.spark.data} status={cell.spark.status} />;
  }
  if ('chip' in cell) {
    return <StatusChip status={cell.chip} />;
  }
  if ('tag' in cell) {
    const s = statusStyle(cell.tag.status);
    return (
      <span
        className="inline-block rounded border px-1.5 py-0.5 text-xs font-medium leading-none"
        style={{ color: s.color, borderColor: s.color }}
        aria-label={`${cell.tag.text} (${s.label})`}
      >
        {cell.tag.text}
      </span>
    );
  }
  const s = cell.status ? statusStyle(cell.status) : null;
  const colored = s !== null && !s.valuePrimary;
  return (
    <span
      className={[
        cell.strong ? 'font-semibold' : '',
        cell.dim
          ? 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
          : colored
            ? ''
            : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark',
      ]
        .filter(Boolean)
        .join(' ')}
      style={colored ? { color: s.color } : undefined}
    >
      {cell.v}
    </span>
  );
}

export function DataTable({ columns, rows, caption, onRowDrill }: DataTableProps) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <caption className="sr-only">{caption}</caption>
        <thead>
          <tr className="border-b border-healthcare-border dark:border-healthcare-border-dark">
            {columns.map((col) => (
              <th
                key={col.key}
                scope="col"
                className={`px-2 py-1.5 text-xs font-semibold uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark ${
                  col.align === 'right' ? 'text-right' : 'text-left'
                }`}
              >
                {col.header}
                {col.note && <span className="ml-1 font-normal normal-case">{col.note}</span>}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
          {rows.map((row, i) => (
            <tr key={i}>
              {columns.map((col) => (
                <td
                  key={col.key}
                  className={`px-2 py-1.5 ${col.align === 'right' ? 'text-right tabular-nums' : ''}`}
                >
                  <CellContent cell={row[col.key] ?? ''} onRowDrill={onRowDrill} />
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
