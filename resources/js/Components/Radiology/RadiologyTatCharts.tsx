import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import type { RadiologyTat } from '@/features/radiology/schemas';

type DistributionChart = RadiologyTat['dailyTrend'] | RadiologyTat['breakdowns']['priority'] | RadiologyTat['nightWeekendComparison'];

function minutes(value: number | null) {
  return value === null ? 'Unavailable' : `${value.toLocaleString()} min`;
}

function cutoff(value: string | null) {
  return value === null ? 'Cutoff unavailable' : `Cutoff ${new Date(value).toLocaleString()}`;
}

function clock(value: DistributionChart) {
  const definition = value.clockDefinition;
  return definition === null
    ? 'Clock unavailable'
    : `${definition.label}: ${definition.startMilestoneCode} → ${definition.stopMilestoneCode} (v${definition.version})`;
}

function ChartEvidence({ value }: { value: DistributionChart }) {
  return (
    <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
      {clock(value)} · Cohort {value.cohortCount.toLocaleString()} · {cutoff(value.sourceCutoffAt)} · {value.benchmarkSourceLabel}
    </p>
  );
}

export function TatDistributionChart({ value }: { value: DistributionChart }) {
  return (
    <figure role="img" aria-label={`${value.label}. Median and p90 turnaround in minutes.`}>
      <div className="h-64" aria-hidden="true">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={value.points} margin={{ top: 12, right: 12, bottom: 24, left: 12 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
            <XAxis dataKey="label" angle={-20} textAnchor="end" height={56} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} />
            <YAxis unit="m" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} />
            <Tooltip />
            <Legend />
            <Bar dataKey="medianMinutes" name="Median" fill="var(--info)" radius={[4, 4, 0, 0]} />
            <Bar dataKey="p90Minutes" name="P90" fill="var(--warning)" radius={[4, 4, 0, 0]} />
          </BarChart>
        </ResponsiveContainer>
      </div>
      <ChartEvidence value={value} />
      <div className="mt-3 overflow-x-auto">
        <table aria-label={`Accessible ${value.label} summary`} className="w-full text-left text-sm">
          <thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Group</th><th className="px-2 py-1 text-right">Cohort</th><th className="px-2 py-1 text-right">Median</th><th className="px-2 py-1 text-right">P90</th><th className="px-2 py-1 text-right">Mean</th></tr></thead>
          <tbody>{value.points.map((point) => <tr key={point.key} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{point.label}</td><td className="px-2 py-2 text-right tabular-nums">{point.count}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.medianMinutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.p90Minutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.meanMinutes)}</td></tr>)}</tbody>
        </table>
      </div>
    </figure>
  );
}

export function TatDailyTrendChart({ value }: { value: RadiologyTat['dailyTrend'] }) {
  return (
    <figure role="img" aria-label="Daily Radiology order-to-final median and p90 trend in minutes.">
      <div className="h-72" aria-hidden="true">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={value.points} margin={{ top: 12, right: 16, bottom: 8, left: 12 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
            <XAxis dataKey="label" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} />
            <YAxis unit="m" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} />
            <Tooltip />
            <Legend />
            <Line type="monotone" dataKey="medianMinutes" name="Median" stroke="var(--info)" strokeWidth={2} connectNulls={false} />
            <Line type="monotone" dataKey="p90Minutes" name="P90" stroke="var(--warning)" strokeWidth={2} connectNulls={false} />
          </LineChart>
        </ResponsiveContainer>
      </div>
      <ChartEvidence value={value} />
      <div className="mt-3 overflow-x-auto">
        <table aria-label="Accessible daily Radiology TAT trend summary" className="w-full text-left text-sm">
          <thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Date</th><th className="px-2 py-1 text-right">Cohort</th><th className="px-2 py-1 text-right">Median</th><th className="px-2 py-1 text-right">P90</th><th className="px-2 py-1 text-right">Mean</th></tr></thead>
          <tbody>{value.points.map((point) => <tr key={point.key} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{point.key}</td><td className="px-2 py-2 text-right tabular-nums">{point.count}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.medianMinutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.p90Minutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.meanMinutes)}</td></tr>)}</tbody>
        </table>
      </div>
    </figure>
  );
}

export function TatWaterfallChart({ value }: { value: RadiologyTat['waterfall'] }) {
  const data = value.map((row) => ({ label: row.definition.label, medianMinutes: row.medianMinutes, p90Minutes: row.p90Minutes }));
  const cutoffAt = value.map((row) => row.sourceCutoffAt).find((item) => item !== null) ?? null;
  return (
    <figure role="img" aria-label="Radiology governed segment waterfall showing median and p90 turnaround in minutes.">
      <div className="h-80" aria-hidden="true">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={data} layout="vertical" margin={{ top: 12, right: 20, bottom: 8, left: 170 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
            <XAxis type="number" unit="m" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} />
            <YAxis type="category" dataKey="label" width={165} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} />
            <Tooltip />
            <Legend />
            <Bar dataKey="medianMinutes" name="Median" fill="var(--info)" radius={[0, 4, 4, 0]} />
            <Bar dataKey="p90Minutes" name="P90" fill="var(--warning)" radius={[0, 4, 4, 0]} />
          </BarChart>
        </ResponsiveContainer>
      </div>
      <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Each row names its governed clock and cohort below · {cutoff(cutoffAt)} · Numeric lines are shown only when a governed source exists.</p>
      <div className="mt-3 overflow-x-auto">
        <table aria-label="Accessible Radiology TAT segment waterfall summary" className="w-full min-w-[980px] text-left text-sm">
          <thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Clock definition</th><th className="px-2 py-1 text-right">Cohort</th><th className="px-2 py-1 text-right">Median</th><th className="px-2 py-1 text-right">P90</th><th className="px-2 py-1 text-right">Mean</th><th className="px-2 py-1">Cutoff / benchmark source</th></tr></thead>
          <tbody>{value.map((row) => <tr key={row.definition.definitionUuid} className="border-t border-healthcare-border align-top dark:border-healthcare-border-dark"><td className="px-2 py-2"><span className="font-medium">{row.definition.label}</span><span className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.definition.startMilestoneCode} → {row.definition.stopMilestoneCode} · v{row.definition.version}</span></td><td className="px-2 py-2 text-right tabular-nums">{row.cohortCount}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(row.medianMinutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(row.p90Minutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(row.meanMinutes)}</td><td className="px-2 py-2 text-xs">{cutoff(row.sourceCutoffAt)}<span className="block">{row.benchmarkSourceLabel}</span></td></tr>)}</tbody>
        </table>
      </div>
    </figure>
  );
}

export function TatParetoChart({ value }: { value: RadiologyTat['breachPareto'] }) {
  return (
    <figure role="img" aria-label="Radiology persisted breach Pareto with cumulative percentage.">
      <div className="h-64" aria-hidden="true">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={value.points} margin={{ top: 12, right: 16, bottom: 36, left: 12 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
            <XAxis dataKey="label" angle={-20} textAnchor="end" height={70} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} />
            <YAxis yAxisId="count" allowDecimals={false} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} />
            <YAxis yAxisId="percent" orientation="right" domain={[0, 100]} unit="%" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} />
            <Tooltip />
            <Legend />
            <Bar yAxisId="count" dataKey="count" name="Breaches" fill="var(--critical)" radius={[4, 4, 0, 0]} />
            <Line yAxisId="percent" type="monotone" dataKey="cumulativePercent" name="Cumulative %" stroke="var(--info)" strokeWidth={2} />
          </BarChart>
        </ResponsiveContainer>
      </div>
      <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Clock: persisted governed SLA breach lifecycle · Cohort {value.cohortCount.toLocaleString()} exams · {cutoff(value.sourceCutoffAt)} · Source: SLA definitions and governed barrier-reason catalog.</p>
      <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{value.definition}</p>
      <div className="mt-3 overflow-x-auto"><table aria-label="Accessible Radiology breach Pareto summary" className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Reason / clock</th><th className="px-2 py-1 text-right">Count</th><th className="px-2 py-1 text-right">Share</th><th className="px-2 py-1 text-right">Cumulative</th></tr></thead><tbody>{value.points.map((point) => <tr key={point.key} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{point.label}</td><td className="px-2 py-2 text-right tabular-nums">{point.count}</td><td className="px-2 py-2 text-right tabular-nums">{point.percent}%</td><td className="px-2 py-2 text-right tabular-nums">{point.cumulativePercent}%</td></tr>)}</tbody></table></div>
    </figure>
  );
}
