import { Bar, BarChart, CartesianGrid, Legend, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { ModalityUtilization } from '@/features/radiology/schemas';

type Scanner = ModalityUtilization['scanners'][number];

function percent(minutes: number | null, available: number) {
  return minutes === null || available <= 0 ? 0 : Number((100 * minutes / available).toFixed(1));
}

function chartRow(scanner: Scanner) {
  const planned = percent(scanner.plannedDowntimeMinutes, scanner.availableMinutes);
  const unplanned = percent(scanner.unplannedDowntimeMinutes, scanner.availableMinutes);
  const exam = percent(scanner.examMinutes, scanner.availableMinutes);
  const idle = percent(scanner.idleMinutes, scanner.availableMinutes);
  const known = planned + unplanned + exam + idle;

  return {
    scanner: `${scanner.label} (${scanner.modality})`,
    exam,
    planned,
    unplanned,
    idle,
    unknown: scanner.availableMinutes <= 0 ? 100 : Number(Math.max(0, 100 - known).toFixed(1)),
  };
}

function UtilizationTooltip({ active, payload, label }: { active?: boolean; payload?: Array<{ name?: string; value?: number; color?: string }>; label?: string }) {
  if (!active || !payload?.length) return null;

  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-3 shadow-lg dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{label}</p>
      <ul className="mt-2 space-y-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        {payload.filter((entry) => Number(entry.value) > 0).map((entry) => <li key={entry.name} className="flex justify-between gap-6"><span>{entry.name}</span><span className="tabular-nums">{entry.value}%</span></li>)}
      </ul>
    </div>
  );
}

export default function ModalityUtilizationChart({ scanners, referenceLines }: { scanners: Scanner[]; referenceLines: ModalityUtilization['referenceLines'] }) {
  const rows = scanners.map(chartRow);
  if (rows.length === 0) return <p className="p-6 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No scanner utilization rows are available.</p>;

  return (
    <figure role="img" aria-label="Stacked scanner operating-window utilization with exam, planned downtime, unplanned downtime, idle, and unknown coverage shares">
      <div style={{ height: `${Math.max(280, rows.length * 54 + 96)}px` }}>
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={rows} layout="vertical" margin={{ top: 20, right: 28, bottom: 20, left: 24 }}>
            <CartesianGrid stroke="var(--color-gray-300)" strokeDasharray="3 3" horizontal={false} />
            <XAxis type="number" domain={[0, 100]} unit="%" tick={{ fill: 'var(--color-gray-500)', fontSize: 12 }} />
            <YAxis type="category" dataKey="scanner" width={150} tick={{ fill: 'var(--color-gray-500)', fontSize: 12 }} />
            <Tooltip content={<UtilizationTooltip />} />
            <Legend />
            {referenceLines.map((line) => <ReferenceLine key={line.key} x={line.value} stroke="var(--healthcare-primary)" strokeDasharray="5 4" label={{ value: line.label, fill: 'var(--healthcare-primary)', fontSize: 12, position: 'insideTopRight' }} />)}
            <Bar dataKey="exam" name="Covered exam" stackId="window" fill="var(--healthcare-success)" />
            <Bar dataKey="planned" name="Planned downtime" stackId="window" fill="var(--healthcare-warning)" />
            <Bar dataKey="unplanned" name="Unplanned downtime" stackId="window" fill="var(--healthcare-critical)" />
            <Bar dataKey="idle" name="Idle" stackId="window" fill="var(--healthcare-info)" />
            <Bar dataKey="unknown" name="Unknown coverage" stackId="window" fill="var(--color-gray-400)" />
          </BarChart>
        </ResponsiveContainer>
      </div>
      <figcaption className="px-4 pb-4 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Each bar partitions the declared staffed window. The dashed reference is the covered scanner portfolio average, not a benchmark target.
      </figcaption>
      <div className="sr-only">
        <table>
          <caption>Accessible scanner utilization summary</caption>
          <thead><tr><th>Scanner</th><th>Covered exam</th><th>Planned downtime</th><th>Unplanned downtime</th><th>Idle</th><th>Unknown</th></tr></thead>
          <tbody>{rows.map((row) => <tr key={row.scanner}><th>{row.scanner}</th><td>{row.exam}%</td><td>{row.planned}%</td><td>{row.unplanned}%</td><td>{row.idle}%</td><td>{row.unknown}%</td></tr>)}</tbody>
        </table>
      </div>
    </figure>
  );
}
