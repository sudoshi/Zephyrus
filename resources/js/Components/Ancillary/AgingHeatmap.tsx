export type AgingHeatmapState = 'normal' | 'warning' | 'breach' | 'no_data';

export interface AgingHeatmapCell {
  key: string;
  rowLabel: string;
  columnLabel: string;
  count: number | null;
  state: AgingHeatmapState;
}

const CELL: Record<AgingHeatmapState, string> = {
  normal: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  warning: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  breach: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  no_data: 'border-healthcare-border bg-healthcare-background text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-background-dark dark:text-healthcare-text-secondary-dark',
};

export function AgingHeatmap({ title, cells }: { title: string; cells: AgingHeatmapCell[] }) {
  if (cells.length === 0) {
    return <section aria-label={title} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark"><h3 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h3><p className="mt-2">No aging cohorts available.</p></section>;
  }

  return (
    <section aria-label={title} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <h3 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h3>
      <div role="img" aria-label={`${title} heatmap; table follows`} className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
        {cells.map((cell) => <div key={cell.key} title={`${cell.rowLabel}, ${cell.columnLabel}: ${cell.count ?? 'unavailable'}`} className={`rounded-md border p-3 ${CELL[cell.state]}`}><span className="block text-xs">{cell.rowLabel}</span><span className="block text-xs">{cell.columnLabel}</span><span className="mt-1 block text-lg font-semibold tabular-nums">{cell.count ?? '—'}</span></div>)}
      </div>
      <details className="mt-3 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        <summary className="cursor-pointer focus:outline-none focus:ring-2 focus:ring-healthcare-info">View heatmap data table</summary>
        <div className="mt-2 overflow-x-auto"><table className="w-full"><caption className="sr-only">{title} values</caption><thead><tr className="border-b border-healthcare-border dark:border-healthcare-border-dark"><th scope="col" className="p-2 text-left">Group</th><th scope="col" className="p-2 text-left">Age band</th><th scope="col" className="p-2 text-right">Orders</th><th scope="col" className="p-2 text-left">State</th></tr></thead><tbody>{cells.map((cell) => <tr key={cell.key} className="border-b border-healthcare-border/60 dark:border-healthcare-border-dark/60"><th scope="row" className="p-2 text-left font-medium">{cell.rowLabel}</th><td className="p-2">{cell.columnLabel}</td><td className="p-2 text-right tabular-nums">{cell.count ?? 'Unavailable'}</td><td className="p-2">{cell.state.replace('_', ' ')}</td></tr>)}</tbody></table></div>
      </details>
    </section>
  );
}
