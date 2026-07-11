// resources/js/Components/arena/PerformancePane.tsx
//
// Part X (X2) — object-centric performance (OPerA-style). Two views: the slowest
// object-lifecycle hand-offs (flow/waiting time, computed per object type so no
// convergence inflation), and the synchronization waits at object intersections
// — the signal that says which SIDE of a hand-off waits (e.g. bed-side vs
// patient-side at discharge). Durations are trimmed to plausible hand-offs.
import type { ArenaHandoff, ArenaSyncWait } from '@/features/arena/schema';
import { objectTypeColor } from './objectTypePalette';
import { formatDurationSeconds } from '@/lib/duration';

function TypeDot({ type, orderedTypes }: { type: string; orderedTypes: string[] }) {
  return <span className="inline-block h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: objectTypeColor(type, orderedTypes) }} />;
}

export function PerformancePane({
  handoffs,
  synchronization,
  orderedTypes,
}: {
  handoffs: ArenaHandoff[];
  synchronization: ArenaSyncWait[];
  orderedTypes: string[];
}) {
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      {/* Slowest hand-offs */}
      <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-5 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Slowest hand-offs</h3>
        <p className="mt-0.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Median elapsed between consecutive activities in an object&apos;s lifecycle.
        </p>
        <table className="mt-3 w-full text-sm">
          <thead>
            <tr className="border-b border-healthcare-border text-left text-xs text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
              <th className="pb-1.5 font-medium">Transition</th>
              <th className="pb-1.5 text-right font-medium">Median</th>
              <th className="pb-1.5 text-right font-medium">p90</th>
              <th className="pb-1.5 text-right font-medium">n</th>
            </tr>
          </thead>
          <tbody>
            {handoffs.map((h) => (
              <tr key={`${h.object_type}:${h.source}:${h.target}`} className="border-b border-healthcare-border/60 last:border-0 dark:border-healthcare-border-dark/60">
                <td className="py-1.5">
                  <span className="flex items-center gap-1.5">
                    <TypeDot type={h.object_type} orderedTypes={orderedTypes} />
                    <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {h.source} <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">&rarr;</span> {h.target}
                    </span>
                  </span>
                </td>
                <td className="py-1.5 text-right tabular-nums font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{formatDurationSeconds(h.median_sec)}</td>
                <td className="py-1.5 text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{formatDurationSeconds(h.p90_sec)}</td>
                <td className="py-1.5 text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{h.count}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Synchronization */}
      <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-5 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Synchronization at hand-offs</h3>
        <p className="mt-0.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          At a shared step, how long each object type waited — which side of the hand-off is the constraint.
        </p>
        <table className="mt-3 w-full text-sm">
          <thead>
            <tr className="border-b border-healthcare-border text-left text-xs text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
              <th className="pb-1.5 font-medium">Activity</th>
              <th className="pb-1.5 font-medium">Object</th>
              <th className="pb-1.5 text-right font-medium">Median wait</th>
              <th className="pb-1.5 text-right font-medium">n</th>
            </tr>
          </thead>
          <tbody>
            {synchronization.map((s) => (
              <tr key={`${s.activity}:${s.object_type}`} className="border-b border-healthcare-border/60 last:border-0 dark:border-healthcare-border-dark/60">
                <td className="py-1.5 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{s.activity}</td>
                <td className="py-1.5">
                  <span className="flex items-center gap-1.5 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    <TypeDot type={s.object_type} orderedTypes={orderedTypes} />
                    {s.object_type}
                  </span>
                </td>
                <td className="py-1.5 text-right tabular-nums font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{formatDurationSeconds(s.median_wait_sec)}</td>
                <td className="py-1.5 text-right tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{s.count}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
