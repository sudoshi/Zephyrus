import {
  Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import type { PharmacyTat } from '@/features/pharmacy/tat-schemas';

type DistributionChart = PharmacyTat['dailyTrend'] | PharmacyTat['breakdowns'][keyof PharmacyTat['breakdowns']];

const minutes = (value: number | null) => value === null ? 'Unavailable' : `${value.toLocaleString()} min`;
const percent = (value: number | null) => value === null ? 'Unavailable' : `${value.toLocaleString()}%`;
const cutoff = (value: string | null) => value === null ? 'Cutoff unavailable' : `Cutoff ${new Date(value).toLocaleString()}`;
const basisLabel = (basis: 'real_time' | 'warehouse_as_of') => basis === 'warehouse_as_of' ? 'Warehouse as-of' : 'Real-time';

function clock(value: DistributionChart) {
  const definition = value.clockDefinition;
  return definition === null ? 'Clock unavailable' : `${definition.label}: ${definition.startMilestoneCode} → ${definition.stopMilestoneCode} (v${definition.version})`;
}

function DistributionEvidence({ value }: { value: DistributionChart & { basis: 'real_time' | 'warehouse_as_of' } }) {
  return <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{clock(value)} · {basisLabel(value.basis)} · Cohort {value.cohortCount.toLocaleString()} · {cutoff(value.sourceCutoffAt)} · {value.benchmarkSourceLabel}</p>;
}

export function PharmacyTatDistributionChart({ value }: { value: DistributionChart & { basis: 'real_time' | 'warehouse_as_of' } }) {
  return <figure role="img" aria-label={`${value.label}. ${basisLabel(value.basis)}. Median and p90 Pharmacy turnaround in minutes.`}>
    <div className="h-64" aria-hidden="true"><ResponsiveContainer width="100%" height="100%"><BarChart data={value.points} margin={{ top: 12, right: 12, bottom: 28, left: 12 }}>
      <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="label" angle={-20} textAnchor="end" height={60} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis unit="m" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><Tooltip /><Legend />
      <Bar dataKey="medianMinutes" name="Median" fill="var(--info)" radius={[4, 4, 0, 0]} /><Bar dataKey="p90Minutes" name="P90" fill="var(--warning)" radius={[4, 4, 0, 0]} />
    </BarChart></ResponsiveContainer></div>
    <DistributionEvidence value={value} />
    <div className="mt-3 overflow-x-auto"><table aria-label={`Accessible ${value.label} summary`} className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Group</th><th className="px-2 py-1 text-right">Cohort</th><th className="px-2 py-1 text-right">Median</th><th className="px-2 py-1 text-right">P90</th><th className="px-2 py-1 text-right">Mean</th></tr></thead><tbody>{value.points.map((point) => <tr key={point.key} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{point.label}</td><td className="px-2 py-2 text-right tabular-nums">{point.count}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.medianMinutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.p90Minutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.meanMinutes)}</td></tr>)}</tbody></table></div>
  </figure>;
}

export function PharmacyTatDailyTrendChart({ value }: { value: PharmacyTat['dailyTrend'] }) {
  return <figure role="img" aria-label="Daily Pharmacy order-to-administration median and p90 trend in minutes. Warehouse as-of.">
    <div className="h-72" aria-hidden="true"><ResponsiveContainer width="100%" height="100%"><LineChart data={value.points} margin={{ top: 12, right: 16, bottom: 8, left: 12 }}>
      <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="label" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis unit="m" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><Tooltip /><Legend />
      <Line type="monotone" dataKey="medianMinutes" name="Median" stroke="var(--info)" strokeWidth={2} connectNulls={false} /><Line type="monotone" dataKey="p90Minutes" name="P90" stroke="var(--warning)" strokeWidth={2} connectNulls={false} />
    </LineChart></ResponsiveContainer></div>
    <DistributionEvidence value={value} />
    <div className="mt-3 overflow-x-auto"><table aria-label="Accessible daily Pharmacy TAT trend summary" className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Date</th><th className="px-2 py-1 text-right">Cohort</th><th className="px-2 py-1 text-right">Median</th><th className="px-2 py-1 text-right">P90</th><th className="px-2 py-1 text-right">Mean</th></tr></thead><tbody>{value.points.map((point) => <tr key={point.key} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{point.key}</td><td className="px-2 py-2 text-right tabular-nums">{point.count}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.medianMinutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.p90Minutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.meanMinutes)}</td></tr>)}</tbody></table></div>
  </figure>;
}

export function PharmacyTatWaterfallChart({ value }: { value: PharmacyTat['waterfall'] }) {
  const data = value.map((row) => ({ label: row.phase.replaceAll('_', ' '), medianMinutes: row.medianMinutes, p90Minutes: row.p90Minutes }));
  return <figure role="img" aria-label="Governed Pharmacy verification, prepare, dispense, deliver, and order-to-administration median and p90 waterfall. Delivery and administration segments are warehouse as-of.">
    <div className="h-80" aria-hidden="true"><ResponsiveContainer width="100%" height="100%"><BarChart data={data} layout="vertical" margin={{ top: 12, right: 20, bottom: 8, left: 110 }}>
      <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis type="number" unit="m" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis type="category" dataKey="label" width={105} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><Tooltip /><Legend />
      <Bar dataKey="medianMinutes" name="Median" fill="var(--info)" radius={[0, 4, 4, 0]} /><Bar dataKey="p90Minutes" name="P90" fill="var(--warning)" radius={[0, 4, 4, 0]} />
    </BarChart></ResponsiveContainer></div>
    <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Each row names its exact effective clock, basis, cohort, exclusions, cutoff, and reference status. Delivery and administration segments are cutoff-qualified warehouse facts, never real-time.</p>
    <div className="mt-3 overflow-x-auto"><table aria-label="Accessible Pharmacy TAT segment waterfall summary" className="w-full min-w-[1080px] text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Phase / clock</th><th className="px-2 py-1">Basis</th><th className="px-2 py-1 text-right">Cohort</th><th className="px-2 py-1 text-right">Median</th><th className="px-2 py-1 text-right">P90</th><th className="px-2 py-1 text-right">Missing / invalid</th><th className="px-2 py-1">Cutoff / reference</th></tr></thead><tbody>{value.map((row) => <tr key={row.definition.definitionUuid} className="border-t border-healthcare-border align-top dark:border-healthcare-border-dark"><td className="px-2 py-2"><span className="font-medium capitalize">{row.phase.replaceAll('_', ' ')} · {row.definition.label}</span><span className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.definition.startMilestoneCode} → {row.definition.stopMilestoneCode} · v{row.definition.version}</span></td><td className="px-2 py-2 text-xs">{basisLabel(row.basis)}</td><td className="px-2 py-2 text-right tabular-nums">{row.cohortCount}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(row.medianMinutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(row.p90Minutes)}</td><td className="px-2 py-2 text-right tabular-nums">{row.missingIntervalCount} / {row.invalidTimestampCount + row.excludedNegativeCount}</td><td className="px-2 py-2 text-xs">{cutoff(row.sourceCutoffAt)}<span className="block">{row.benchmarkSourceLabel}</span></td></tr>)}</tbody></table></div>
  </figure>;
}

export function PharmacyQueueHeatmap({ value }: { value: PharmacyTat['queueDepthHeatmap'] }) {
  const lookup = new Map(value.cells.map((cell) => [`${cell.dayIndex}:${cell.hour}`, cell.count]));
  const cellStyle = (count: number) => {
    if (count === 0 || value.peakCount === 0) {
      return 'bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark';
    }
    const ratio = count / value.peakCount;
    if (ratio >= 0.75) return 'bg-healthcare-primary text-white';
    if (ratio >= 0.4) return 'bg-healthcare-primary/60 text-white';
    return 'bg-healthcare-primary/25 text-healthcare-text-primary dark:text-healthcare-text-primary-dark';
  };

  return <figure role="img" aria-label="Verification queue depth heatmap by weekday and hour. A full accessible table follows.">
    <div className="overflow-x-auto" aria-hidden="true">
      <table className="w-full min-w-[760px] border-separate border-spacing-0.5 text-center text-xs tabular-nums">
        <thead><tr><th className="px-1 py-1 text-left text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Day</th>{value.hours.map((hour) => <th key={hour} className="px-1 py-1 font-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{hour}</th>)}</tr></thead>
        <tbody>{value.days.map((day, dayIndex) => <tr key={day}><td className="px-1 py-1 text-left font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{day}</td>{value.hours.map((hour) => { const count = lookup.get(`${dayIndex + 1}:${hour}`) ?? 0; return <td key={hour} className={`rounded-sm px-1 py-1 ${cellStyle(count)}`}>{count === 0 ? '' : count}</td>; })}</tr>)}</tbody>
      </table>
    </div>
    <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Real-time · {value.totalQueued.toLocaleString()} queued verifications · Peak cell {value.peakCount} · {cutoff(value.sourceCutoffAt)}</p>
    <div className="mt-3 overflow-x-auto"><table aria-label="Accessible verification queue depth by weekday and hour" className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Weekday</th><th className="px-2 py-1 text-right">Hour</th><th className="px-2 py-1 text-right">Queued</th></tr></thead><tbody>{value.cells.length === 0 ? <tr><td className="px-2 py-2" colSpan={3}>No queued verifications in the bounded window.</td></tr> : value.cells.map((cell) => <tr key={`${cell.dayIndex}-${cell.hour}`} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{cell.day}</td><td className="px-2 py-2 text-right tabular-nums">{cell.hour}:00</td><td className="px-2 py-2 text-right tabular-nums">{cell.count}</td></tr>)}</tbody></table></div>
  </figure>;
}

export function PharmacyMissingDoseParetoChart({ value }: { value: PharmacyTat['missingDosePareto'] }) {
  return <figure role="img" aria-label="Missing-dose Pareto by preparation branch with cumulative percentage. Real-time, descriptive.">
    <div className="h-64" aria-hidden="true"><ResponsiveContainer width="100%" height="100%"><BarChart data={value.points} margin={{ top: 12, right: 16, bottom: 36, left: 12 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="label" angle={-20} textAnchor="end" height={70} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis yAxisId="count" allowDecimals={false} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis yAxisId="percent" orientation="right" domain={[0, 100]} unit="%" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><Tooltip /><Legend /><Bar yAxisId="count" dataKey="count" name="Missing-dose orders" fill="var(--warning)" radius={[4, 4, 0, 0]} /><Line yAxisId="percent" type="monotone" dataKey="cumulativePercent" name="Cumulative %" stroke="var(--info)" strokeWidth={2} /></BarChart></ResponsiveContainer></div>
    <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Real-time · {value.chainCount} missing-dose chains · {cutoff(value.sourceCutoffAt)} · {value.clockDefinition}</p>
    <div className="mt-3 overflow-x-auto"><table aria-label="Accessible missing-dose Pareto summary" className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Branch</th><th className="px-2 py-1 text-right">Count</th><th className="px-2 py-1 text-right">Share</th><th className="px-2 py-1 text-right">Cumulative</th></tr></thead><tbody>{value.points.length === 0 ? <tr><td className="px-2 py-2" colSpan={4}>No missing-dose chains in the bounded window.</td></tr> : value.points.map((point) => <tr key={point.key} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{point.label}</td><td className="px-2 py-2 text-right tabular-nums">{point.count}</td><td className="px-2 py-2 text-right tabular-nums">{point.percent}%</td><td className="px-2 py-2 text-right tabular-nums">{point.cumulativePercent}%</td></tr>)}</tbody></table></div>
  </figure>;
}

export function PharmacyDischargeReadinessChart({ value }: { value: PharmacyTat['dischargeReadinessTrend'] }) {
  return <figure role="img" aria-label="Discharge medication ready-on-time trend. Real-time operational pipeline.">
    <div className="h-64" aria-hidden="true"><ResponsiveContainer width="100%" height="100%"><LineChart data={value.points} margin={{ top: 12, right: 16, bottom: 8, left: 12 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="label" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis domain={[0, 100]} unit="%" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><Tooltip /><Legend /><Line type="monotone" dataKey="readyOnTimePercent" name="Ready on time" stroke="var(--success)" strokeWidth={2} connectNulls={false} /></LineChart></ResponsiveContainer></div>
    <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Real-time · Cohort {value.cohortCount} · {cutoff(value.sourceCutoffAt)} · {value.clockDefinition}</p>
    <div className="mt-3 overflow-x-auto"><table aria-label="Accessible discharge readiness trend summary" className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Date</th><th className="px-2 py-1 text-right">Cohort</th><th className="px-2 py-1 text-right">Ready on time</th><th className="px-2 py-1 text-right">Rate</th></tr></thead><tbody>{value.points.length === 0 ? <tr><td className="px-2 py-2" colSpan={4}>No discharge-queue orders in the bounded window.</td></tr> : value.points.map((point) => <tr key={point.date} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{point.date}</td><td className="px-2 py-2 text-right tabular-nums">{point.cohortCount}</td><td className="px-2 py-2 text-right tabular-nums">{point.readyOnTimeCount}</td><td className="px-2 py-2 text-right tabular-nums">{percent(point.readyOnTimePercent)}</td></tr>)}</tbody></table></div>
  </figure>;
}

export function PharmacyShortageImpactChart({ value }: { value: PharmacyTat['shortageImpact'] }) {
  return <figure role="img" aria-label="Order-to-administration percentiles contrasted by formulary shortage flag. Warehouse as-of, descriptive contrast only.">
    <div className="h-56" aria-hidden="true"><ResponsiveContainer width="100%" height="100%"><BarChart data={value.points} margin={{ top: 12, right: 16, bottom: 8, left: 12 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis dataKey="label" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis unit="m" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><Tooltip /><Legend /><Bar dataKey="medianMinutes" name="Median" fill="var(--info)" radius={[4, 4, 0, 0]} /><Bar dataKey="p90Minutes" name="P90" fill="var(--warning)" radius={[4, 4, 0, 0]} /></BarChart></ResponsiveContainer></div>
    <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Warehouse as-of · {value.shortageOrderCount} shortage orders · {cutoff(value.sourceCutoffAt)} · Descriptive contrast, not a causal claim.</p>
    <div className="mt-3 overflow-x-auto"><table aria-label="Accessible shortage impact contrast summary" className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Group</th><th className="px-2 py-1 text-right">Cohort</th><th className="px-2 py-1 text-right">Median</th><th className="px-2 py-1 text-right">P90</th><th className="px-2 py-1 text-right">Mean</th></tr></thead><tbody>{value.points.map((point) => <tr key={point.key} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{point.label}</td><td className="px-2 py-2 text-right tabular-nums">{point.count}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.medianMinutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.p90Minutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(point.meanMinutes)}</td></tr>)}</tbody></table></div>
  </figure>;
}

export function PharmacyMappingCoverageChart({ value }: { value: PharmacyTat['mappingCoverage'] }) {
  return <figure role="img" aria-label="Terminology mapping coverage of the bounded Pharmacy cohort.">
    <div className="h-48" aria-hidden="true"><ResponsiveContainer width="100%" height="100%"><BarChart data={value.points} layout="vertical" margin={{ top: 8, right: 16, bottom: 8, left: 110 }}><CartesianGrid strokeDasharray="3 3" stroke="var(--border)" /><XAxis type="number" allowDecimals={false} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><YAxis type="category" dataKey="label" width={105} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} /><Tooltip /><Bar dataKey="count" name="Orders" fill="var(--info)" radius={[0, 4, 4, 0]} /></BarChart></ResponsiveContainer></div>
    <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{value.totalOrderCount} bounded orders · {value.mappedCount} mapped ({percent(value.mappedPercent)}) · {value.unmappedLocalCount} unmapped local ({percent(value.unmappedLocalPercent)}). Unmapped orders are counted, not hidden.</p>
    <div className="mt-3 overflow-x-auto"><table aria-label="Accessible mapping coverage summary" className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Terminology</th><th className="px-2 py-1 text-right">Orders</th></tr></thead><tbody>{value.points.map((point) => <tr key={point.key} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{point.label}</td><td className="px-2 py-2 text-right tabular-nums">{point.count}</td></tr>)}</tbody></table></div>
  </figure>;
}
