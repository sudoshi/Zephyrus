import { Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { IrSuite } from '@/features/radiology/schemas';

const minutes = (value: number | null) => value === null ? 'Unavailable' : `${value.toLocaleString()} min`;
const percent = (value: number | null) => value === null ? 'Unavailable' : `${value.toLocaleString()}%`;
const cutoff = (value: string | null) => value === null ? 'No source cutoff' : `Cutoff ${new Date(value).toLocaleString()}`;

export function IrRoomUtilizationChart({ rooms, denominator }: { rooms: IrSuite['rooms']; denominator: string }) {
  return (
    <figure role="img" aria-label="IR suite utilization by declared room with occupied, downtime, idle, and unknown coverage.">
      <div className="h-72" aria-hidden="true">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={rooms} margin={{ top: 12, right: 16, bottom: 28, left: 12 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
            <XAxis dataKey="label" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} />
            <YAxis unit="%" domain={[0, 100]} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} />
            <Tooltip /><Legend />
            <Bar dataKey="utilizationPercent" name="Utilization" fill="var(--info)" radius={[4, 4, 0, 0]} />
          </BarChart>
        </ResponsiveContainer>
      </div>
      <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Clock: selected MPPS exam start to exam end · Cohort and cutoff are listed per room · Denominator: {denominator}</p>
      <div className="mt-3 overflow-x-auto">
        <table aria-label="Accessible IR suite room utilization summary" className="w-full min-w-[920px] text-left text-sm">
          <thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Declared room</th><th className="px-2 py-1 text-right">Cases</th><th className="px-2 py-1 text-right">Available</th><th className="px-2 py-1 text-right">Occupied</th><th className="px-2 py-1 text-right">Utilization</th><th className="px-2 py-1 text-right">FCOTS</th><th className="px-2 py-1 text-right">Median turnover</th><th className="px-2 py-1">Coverage</th></tr></thead>
          <tbody>{rooms.map((room) => <tr key={room.roomUuid} className="border-t border-healthcare-border align-top dark:border-healthcare-border-dark"><td className="px-2 py-2"><span className="font-medium">{room.label}</span><span className="block text-xs">{room.timezone} · {room.operatingWindows.length} explicit window(s)</span></td><td className="px-2 py-2 text-right tabular-nums">{room.caseCount}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(room.availableMinutes)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(room.occupiedMinutes)}</td><td className="px-2 py-2 text-right tabular-nums">{percent(room.utilizationPercent)}</td><td className="px-2 py-2 text-right tabular-nums">{percent(room.fcots.percent)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(room.turnover.median)}</td><td className="px-2 py-2">{room.coverage.status.replaceAll('_', ' ')}{room.coverage.warning ? <span className="block text-xs">{room.coverage.warning}</span> : null}</td></tr>)}</tbody>
        </table>
      </div>
    </figure>
  );
}

export function IrRoomRunningChart({ value, cutoffAt, cohort }: { value: IrSuite['roomRunning']; cutoffAt: string | null; cohort: number }) {
  return (
    <figure role="img" aria-label="IR rooms running hourly profile using the shared Perioperative overlap definition.">
      <div className="h-64" aria-hidden="true">
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={value.points} margin={{ top: 12, right: 16, bottom: 8, left: 12 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
            <XAxis dataKey="hour" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} />
            <YAxis allowDecimals={false} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} />
            <Tooltip /><Legend />
            <Line type="monotone" dataKey="averageRoomsRunning" name="Average rooms running" stroke="var(--info)" strokeWidth={2} />
            <Line type="monotone" dataKey="maxRoomsRunning" name="Maximum rooms running" stroke="var(--warning)" strokeWidth={2} />
          </LineChart>
        </ResponsiveContainer>
      </div>
      <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{value.definition} · Cohort {cohort.toLocaleString()} IR cases · {cutoff(cutoffAt)} · Source: selected MPPS interval assertions.</p>
      <div className="mt-3 overflow-x-auto"><table aria-label="Accessible IR rooms running hourly summary" className="w-full text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Hour</th><th className="px-2 py-1 text-right">Average rooms</th><th className="px-2 py-1 text-right">Maximum rooms</th><th className="px-2 py-1 text-right">Sample days</th></tr></thead><tbody>{value.points.map((point) => <tr key={point.hour} className="border-t border-healthcare-border dark:border-healthcare-border-dark"><td className="px-2 py-2">{point.hour}</td><td className="px-2 py-2 text-right tabular-nums">{point.averageRoomsRunning}</td><td className="px-2 py-2 text-right tabular-nums">{point.maxRoomsRunning}</td><td className="px-2 py-2 text-right tabular-nums">{point.sampleDays}</td></tr>)}</tbody></table></div>
    </figure>
  );
}

export function IrGateChart({ gates, cohort }: { gates: IrSuite['gates']; cohort: number }) {
  return (
    <figure role="img" aria-label="IR imaging-specific preparation transport and read gate distributions showing median and p90.">
      <div className="h-64" aria-hidden="true">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={gates} margin={{ top: 12, right: 16, bottom: 38, left: 12 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
            <XAxis dataKey="label" angle={-15} textAnchor="end" height={70} tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} />
            <YAxis unit="m" tick={{ fill: 'var(--text-secondary)', fontSize: 11 }} />
            <Tooltip /><Legend />
            <Bar dataKey="median" name="Median" fill="var(--info)" radius={[4, 4, 0, 0]} />
            <Bar dataKey="p90" name="P90" fill="var(--warning)" radius={[4, 4, 0, 0]} />
          </BarChart>
        </ResponsiveContainer>
      </div>
      <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Imaging gates are additive to unchanged suite definitions · Cohort {cohort.toLocaleString()} IR cases · Each row declares selected milestones, cutoff, missing pairs, and invalid intervals.</p>
      <div className="mt-3 overflow-x-auto"><table aria-label="Accessible IR imaging gate summary" className="w-full min-w-[880px] text-left text-sm"><thead className="text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"><tr><th className="px-2 py-1">Gate clock</th><th className="px-2 py-1 text-right">Comparable</th><th className="px-2 py-1 text-right">Median</th><th className="px-2 py-1 text-right">P90</th><th className="px-2 py-1 text-right">Mean</th><th className="px-2 py-1 text-right">Missing / invalid</th><th className="px-2 py-1">Cutoff</th></tr></thead><tbody>{gates.map((gate) => <tr key={gate.key} className="border-t border-healthcare-border align-top dark:border-healthcare-border-dark"><td className="px-2 py-2"><span className="font-medium">{gate.label}</span><span className="block text-xs">{gate.startMilestoneCode} → {gate.stopMilestoneCode}</span></td><td className="px-2 py-2 text-right tabular-nums">{gate.count}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(gate.median)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(gate.p90)}</td><td className="px-2 py-2 text-right tabular-nums">{minutes(gate.meanMinutes)}</td><td className="px-2 py-2 text-right tabular-nums">{gate.missingCount} / {gate.invalidCount}</td><td className="px-2 py-2 text-xs">{cutoff(gate.sourceCutoffAt)}</td></tr>)}</tbody></table></div>
    </figure>
  );
}
