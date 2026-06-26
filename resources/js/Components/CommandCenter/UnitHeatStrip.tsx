// resources/js/Components/CommandCenter/UnitHeatStrip.tsx
import type { UnitCensus } from '@/types/commandCenter';
import { STATUS_VAR } from './status';
import { Panel } from './Panel';
import { EmptyState } from './states';

export function UnitHeatStrip({ units }: { units: UnitCensus[] }) {
  if (units.length === 0) {
    return (
      <div aria-label="Unit census heat map">
        <EmptyState message="No units reporting census" />
      </div>
    );
  }
  return (
    <div aria-label="Unit census heat map" className="grid gap-1.5"
         style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(104px, 1fr))' }}>
      {units.map((u) => {
        const color = STATUS_VAR[u.status];
        const pct = Math.max(0, Math.min(100, u.occupancyPct));
        return (
          <Panel key={u.unitId}
                 title={`${u.name}: ${u.occupied}/${u.staffed} occupied, ${u.available} available, ${u.blocked} blocked`}
                 className="flex flex-col gap-1 p-2"
                 style={{ borderTop: `3px solid ${color}` }}>
            <span className="truncate text-xs font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{u.name}</span>
            <span className="text-lg font-semibold tabular-nums leading-none" style={{ color }}>
              {u.occupancyPct}%
            </span>
            {/* Per-unit occupancy mini-bar */}
            <div className="h-1 w-full overflow-hidden rounded-full bg-healthcare-border dark:bg-healthcare-border-dark">
              <div className="h-full rounded-full transition-[width] duration-500 ease-out"
                   style={{ width: `${pct}%`, background: color }} />
            </div>
            <span className="text-[10px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {u.available} open {'·'} {u.blocked} blk
            </span>
          </Panel>
        );
      })}
    </div>
  );
}
