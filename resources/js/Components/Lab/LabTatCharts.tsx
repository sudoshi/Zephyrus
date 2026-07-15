import {
  Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import type { LabTat } from '@/features/lab/schemas';

type DistributionChart = LabTat['dailyTrend'] | LabTat['breakdowns'][keyof LabTat['breakdowns']];

const minutes = (value: number | null) => value === null ? 'Unavailable' : `${value.toLocaleString()} min`;
const percent = (value: number | null) => value === null ? 'Unavailable' : `${value.toLocaleString()}%`;
const cutoff = (value: string | null) => value === null ? 'Cutoff unavailable' : `Cutoff ${new Date(value).toLocaleString()}`;

function clock(value: DistributionChart) {
  const definition = value.clockDefinition;
  return definition === null ? 'Clock unavailable' : `${definition.label}: ${definition.startMilestoneCode} → ${definition.stopMilestoneCode} (v${definition.version})`;
}

function DistributionEvidence({ value }: { value: DistributionChart }) {
  return <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{clock(value)} · Cohort {value.cohortCount.toLocaleString()} · {cutoff(value.sourceCutoffAt)} · {value.benchmarkSourceLabel}</p>;
}

export function LabTatDistributionChart({ value }: { value: DistributionChart }) {
  return <figure role="img" aria-label={`${value.label}. Median and p90 Laboratory turnaround in minutes.`}>
    <div className="h-64" aria-hidden="true"><ResponsiveContainer width="100%" height="100%"><BarChart data={value.points} margin={{ top: 12, right: 12, bottom: 28, left: 12 }}>
      <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="label" angle={-20} textAnchor="end" height={60} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis unit="m" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><Tooltip /><Legend />
      <Bar dataKey="medianMinutes" name="Median" fill="var(--info)" radius={[4, 4, 0, 0]} /><Bar dataKey="p90Minutes" name="P90" fill="var(--warning)" radius={[4, 4, 0, 0]} />
    </BarChart></ResponsiveContainer></div>
    <DistributionEvidence value={value} />
    <div className="mt-3 overflow-x-auto"><table aria-label={`Accessible ${value.label} summary`} className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Group</th><th className="px-2 py-1 text-right">Cohort</th><th className="px-2 py-1 text-right">Median</th><th className="px-2 py-1 text-right">P90</th><th className="px-2 py-1 text-right">Mean</th></tr></thead><tbody>{value.points.map((point) => <tr key={point.key} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{point.label}</td><td className="px-2 py-2 text-right tabular-nums">{point.count}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.medianMinutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.p90Minutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.meanMinutes)}</td></tr>)}</tbody></table></div>
  </figure>;
}

export function LabTatDailyTrendChart({ value }: { value: LabTat['dailyTrend'] }) {
  return <figure role="img" aria-label="Daily Laboratory order-to-verification median and p90 trend in minutes.">
    <div className="h-72" aria-hidden="true"><ResponsiveContainer width="100%" height="100%"><LineChart data={value.points} margin={{ top: 12, right: 16, bottom: 8, left: 12 }}>
      <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="label" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis unit="m" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><Tooltip /><Legend />
      <Line type="monotone" dataKey="medianMinutes" name="Median" stroke="var(--info)" strokeWidth={2} connectNulls={false} /><Line type="monotone" dataKey="p90Minutes" name="P90" stroke="var(--warning)" strokeWidth={2} connectNulls={false} />
    </LineChart></ResponsiveContainer></div>
    <DistributionEvidence value={value} />
    <div className="mt-3 overflow-x-auto"><table aria-label="Accessible daily Laboratory TAT trend summary" className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Date</th><th className="px-2 py-1 text-right">Cohort</th><th className="px-2 py-1 text-right">Median</th><th className="px-2 py-1 text-right">P90</th><th className="px-2 py-1 text-right">Mean</th></tr></thead><tbody>{value.points.map((point) => <tr key={point.key} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{point.key}</td><td className="px-2 py-2 text-right tabular-nums">{point.count}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.medianMinutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.p90Minutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.meanMinutes)}</td></tr>)}</tbody></table></div>
  </figure>;
}

export function LabTatWaterfallChart({ value }: { value: LabTat['waterfall'] }) {
  const data = value.map((row) => ({ label: row.phase.replaceAll('_', ' '), medianMinutes: row.medianMinutes, p90Minutes: row.p90Minutes }));
  const cutoffAt = value.map((row) => row.sourceCutoffAt).find((item) => item !== null) ?? null;
  return <figure role="img" aria-label="Governed Laboratory collection, transport, analytic, post-analytic, and end-to-end median and p90 waterfall.">
    <div className="h-80" aria-hidden="true"><ResponsiveContainer width="100%" height="100%"><BarChart data={data} layout="vertical" margin={{ top: 12, right: 20, bottom: 8, left: 110 }}>
      <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis type="number" unit="m" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis type="category" dataKey="label" width={105} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><Tooltip /><Legend />
      <Bar dataKey="medianMinutes" name="Median" fill="var(--info)" radius={[0, 4, 4, 0]} /><Bar dataKey="p90Minutes" name="P90" fill="var(--warning)" radius={[0, 4, 4, 0]} />
    </BarChart></ResponsiveContainer></div>
    <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Each row below names its exact effective clock, cohort, exclusions, cutoff, and reference status · {cutoff(cutoffAt)}</p>
    <div className="mt-3 overflow-x-auto"><table aria-label="Accessible Laboratory TAT segment waterfall summary" className="w-full min-w-[1040px] text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Phase / clock</th><th className="px-2 py-1 text-right">Cohort</th><th className="px-2 py-1 text-right">Median</th><th className="px-2 py-1 text-right">P90</th><th className="px-2 py-1 text-right">Missing / invalid</th><th className="px-2 py-1">Cutoff / reference</th></tr></thead><tbody>{value.map((row) => <tr key={row.definition.definitionUuid} className="border-t border-healthcare-border align-top dark:border-healthcare-border-dark"><td className="px-2 py-2"><span className="font-medium capitalize">{row.phase.replaceAll('_', ' ')} · {row.definition.label}</span><span className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.definition.startMilestoneCode} → {row.definition.stopMilestoneCode} · v{row.definition.version}</span></td><td className="px-2 py-2 text-right tabular-nums">{row.cohortCount}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(row.medianMinutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(row.p90Minutes)}</td><td className="px-2 py-2 text-right tabular-nums">{row.missingIntervalCount} / {row.invalidTimestampCount + row.excludedNegativeCount}</td><td className="px-2 py-2 text-xs">{cutoff(row.sourceCutoffAt)}<span className="block">{row.benchmarkSourceLabel}</span></td></tr>)}</tbody></table></div>
  </figure>;
}

export function LabAmReadinessChart({ value }: { value: LabTat['amReadiness'] }) {
  const clockLabel = value.clockDefinition === null ? 'Clock unavailable' : `${value.clockDefinition.startMilestoneCode} → ${value.clockDefinition.stopMilestoneCode} (v${value.clockDefinition.version})`;
  return <figure role="img" aria-label="AM Laboratory readiness percentage by local clock hour.">
    <div className="h-64" aria-hidden="true"><ResponsiveContainer width="100%" height="100%"><LineChart data={value.points} margin={{ top: 12, right: 16, bottom: 8, left: 12 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="label" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis domain={[0, 100]} unit="%" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><Tooltip /><Legend /><Line type="monotone" dataKey="readyPercent" name="Verified by hour" stroke="var(--success)" strokeWidth={2} connectNulls={false} /></LineChart></ResponsiveContainer></div>
    <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Clock {clockLabel} · Cohort {value.cohortCount} · {cutoff(value.sourceCutoffAt)} · {value.populationDefinition}</p>
    <div className="mt-3 overflow-x-auto"><table aria-label="Accessible AM Laboratory readiness curve summary" className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Local hour</th><th className="px-2 py-1 text-right">Eligible</th><th className="px-2 py-1 text-right">Verified</th><th className="px-2 py-1 text-right">Ready</th></tr></thead><tbody>{value.points.map((point) => <tr key={point.hour} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{point.label}</td><td className="px-2 py-2 text-right tabular-nums">{point.eligibleCount}</td><td className="px-2 py-2 text-right tabular-nums">{point.readyCount}</td><td className="px-2 py-2 text-right tabular-nums">{percent(point.readyPercent)}</td></tr>)}</tbody></table></div>
  </figure>;
}

export function LabAutoVerificationChart({ value }: { value: LabTat['autoVerification'] }) {
  return <figure role="img" aria-label="Daily Laboratory auto-verification rate trend.">
    <div className="h-64" aria-hidden="true"><ResponsiveContainer width="100%" height="100%"><LineChart data={value.points} margin={{ top: 12, right: 16, bottom: 8, left: 12 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="label" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis domain={[0, 100]} unit="%" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><Tooltip /><Legend /><Line type="monotone" dataKey="ratePercent" name="Auto-verified" stroke="var(--info)" strokeWidth={2} connectNulls={false} /></LineChart></ResponsiveContainer></div>
    <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Clock: {value.clockDefinition} · Cohort {value.cohortCount} · {cutoff(value.sourceCutoffAt)} · {value.populationDefinition}</p>
    <div className="mt-3 overflow-x-auto"><table aria-label="Accessible Laboratory auto-verification trend summary" className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Date</th><th className="px-2 py-1 text-right">Verified</th><th className="px-2 py-1 text-right">Auto-verified</th><th className="px-2 py-1 text-right">Rate</th></tr></thead><tbody>{value.points.map((point) => <tr key={point.date} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{point.date}</td><td className="px-2 py-2 text-right tabular-nums">{point.verifiedCount}</td><td className="px-2 py-2 text-right tabular-nums">{point.autoVerifiedCount}</td><td className="px-2 py-2 text-right tabular-nums">{point.ratePercent}%</td></tr>)}</tbody></table></div>
  </figure>;
}

export function LabQualityChart({ value }: { value: LabTat['specimenQuality'] }) {
  const data = [{ label: 'Rejected', value: value.rejectionRatePercent }, { label: 'Recollect', value: value.recollectRatePercent }];
  return <figure role="img" aria-label="Laboratory specimen rejection and recollect rates.">
    <div className="h-56" aria-hidden="true"><ResponsiveContainer width="100%" height="100%"><BarChart data={data} margin={{ top: 12, right: 16, bottom: 8, left: 12 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="label" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis domain={[0, 100]} unit="%" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><Tooltip /><Bar dataKey="value" name="Rate" fill="var(--warning)" radius={[4, 4, 0, 0]} /></BarChart></ResponsiveContainer></div>
    <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Clock: {value.clockDefinition} · Denominator {value.denominator} original specimens · {value.populationDefinition}</p>
    <div className="mt-3 overflow-x-auto"><table aria-label="Accessible Laboratory specimen-quality rate summary" className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Measure</th><th className="px-2 py-1 text-right">Count</th><th className="px-2 py-1 text-right">Denominator</th><th className="px-2 py-1 text-right">Rate</th></tr></thead><tbody><tr className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">Rejected</td><td className="px-2 py-2 text-right tabular-nums">{value.rejectedCount}</td><td className="px-2 py-2 text-right tabular-nums">{value.denominator}</td><td className="px-2 py-2 text-right tabular-nums">{percent(value.rejectionRatePercent)}</td></tr><tr className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">Recollect</td><td className="px-2 py-2 text-right tabular-nums">{value.recollectCount}</td><td className="px-2 py-2 text-right tabular-nums">{value.denominator}</td><td className="px-2 py-2 text-right tabular-nums">{percent(value.recollectRatePercent)}</td></tr></tbody></table></div>
  </figure>;
}

export function LabCriticalCallbackChart({ value }: { value: LabTat['criticalCallbacks'] }) {
  const definition = value.clockDefinition;
  const clockLabel = definition === null ? 'Governed clock unavailable' : `${definition.startMilestoneCode} → ${definition.stopMilestoneCode} (v${definition.version})`;
  return <figure role="img" aria-label="Laboratory critical callback state and closed-loop performance.">
    <div className="h-56" aria-hidden="true"><ResponsiveContainer width="100%" height="100%"><BarChart data={value.stateCounts} margin={{ top: 12, right: 16, bottom: 32, left: 12 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="label" angle={-15} textAnchor="end" height={50} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis allowDecimals={false} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><Tooltip /><Bar dataKey="count" name="Callback loops" fill="var(--critical)" radius={[4, 4, 0, 0]} /></BarChart></ResponsiveContainer></div>
    <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Clock {clockLabel} · Cohort {value.cohortCount} · {cutoff(value.sourceCutoffAt)} · {value.populationDefinition}</p>
    <dl className="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4"><div><dt>Closed-loop median</dt><dd className="font-semibold tabular-nums">{minutes(value.medianMinutes)}</dd></div><div><dt>P90</dt><dd className="font-semibold tabular-nums">{minutes(value.p90Minutes)}</dd></div><div><dt>Open</dt><dd className="font-semibold tabular-nums">{value.openCount}</dd></div><div><dt>Invalid</dt><dd className="font-semibold tabular-nums">{value.invalidIntervalCount}</dd></div></dl>
  </figure>;
}

export function LabBarrierParetoChart({ value }: { value: LabTat['barrierPareto'] }) {
  return <figure role="img" aria-label="Laboratory persisted breach Pareto with cumulative percentage.">
    <div className="h-64" aria-hidden="true"><ResponsiveContainer width="100%" height="100%"><BarChart data={value.points} margin={{ top: 12, right: 16, bottom: 36, left: 12 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="label" angle={-20} textAnchor="end" height={70} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis yAxisId="count" allowDecimals={false} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis yAxisId="percent" orientation="right" domain={[0, 100]} unit="%" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><Tooltip /><Legend /><Bar yAxisId="count" dataKey="count" name="Breaches" fill="var(--critical)" radius={[4, 4, 0, 0]} /><Line yAxisId="percent" type="monotone" dataKey="cumulativePercent" name="Cumulative %" stroke="var(--info)" strokeWidth={2} /></BarChart></ResponsiveContainer></div>
    <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Clock: {value.clockDefinition} · Cohort {value.cohortCount} · {cutoff(value.sourceCutoffAt)}</p>
    <div className="mt-3 overflow-x-auto"><table aria-label="Accessible Laboratory breach Pareto summary" className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Reason / clock</th><th className="px-2 py-1 text-right">Count</th><th className="px-2 py-1 text-right">Share</th><th className="px-2 py-1 text-right">Cumulative</th></tr></thead><tbody>{value.points.map((point) => <tr key={point.key} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{point.label}</td><td className="px-2 py-2 text-right tabular-nums">{point.count}</td><td className="px-2 py-2 text-right tabular-nums">{point.percent}%</td><td className="px-2 py-2 text-right tabular-nums">{point.cumulativePercent}%</td></tr>)}</tbody></table></div>
  </figure>;
}
