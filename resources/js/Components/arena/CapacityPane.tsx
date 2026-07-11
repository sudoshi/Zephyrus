import { LineChart, Line, XAxis, YAxis, Tooltip, ResponsiveContainer } from 'recharts';
import type { ArenaCapacity } from '@/features/arena/schema';

/**
 * Phase XO.3: per-unit occupancy (census) curve, mined from the QEL quantity ops.
 * This is the process-intelligence twin of the RTDC census — same reality the
 * cockpit shows, reconstructed from the OCEL log.
 */
export function CapacityPane({ data }: { data: ArenaCapacity }) {
  if (!data.objects.length) {
    return (
      <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        No occupancy quantities projected yet — the OCEL log has no admit/discharge activity in the current window.
      </p>
    );
  }
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      {data.objects.map((unit) => (
        <div
          key={unit.object_id}
          className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
        >
          <div className="flex items-baseline justify-between">
            <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {unit.object_id}
            </h3>
            <span className="tabular-nums text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              peak {unit.peak} · now {unit.current}
            </span>
          </div>
          <div className="mt-2 h-40">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={unit.series}>
                <XAxis dataKey="time" hide />
                <YAxis width={28} tick={{ fontSize: 11 }} allowDecimals={false} />
                <Tooltip formatter={((value: number) => [`${value} beds`, 'Occupied']) as never} />
                <Line
                  type="stepAfter"
                  dataKey="value"
                  stroke="var(--healthcare-primary)"
                  strokeWidth={2}
                  dot={false}
                  isAnimationActive={false}
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </div>
      ))}
    </div>
  );
}
