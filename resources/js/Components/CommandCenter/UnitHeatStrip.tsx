// resources/js/Components/CommandCenter/UnitHeatStrip.tsx
import type { UnitCensus } from '@/types/commandCenter';
import { STATUS_VAR } from './status';

export function UnitHeatStrip({ units }: { units: UnitCensus[] }) {
  return (
    <div aria-label="Unit census heat map" className="grid gap-1"
         style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(96px, 1fr))' }}>
      {units.map((u) => (
        <div key={u.unitId}
             title={`${u.name}: ${u.occupied}/${u.staffed} occupied, ${u.available} available, ${u.blocked} blocked`}
             className="flex flex-col gap-0.5 rounded p-2
                        bg-healthcare-surface dark:bg-healthcare-surface-dark
                        border border-healthcare-border dark:border-healthcare-border-dark
                        shadow-sm transition-colors duration-300"
             style={{ borderTop: `3px solid ${STATUS_VAR[u.status]}` }}>
          <span className="truncate text-xs font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{u.name}</span>
          <span className="text-lg font-semibold tabular-nums" style={{ color: STATUS_VAR[u.status] }}>
            {u.occupancyPct}%
          </span>
          <span className="text-[10px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {u.available} open {'·'} {u.blocked} blk
          </span>
        </div>
      ))}
    </div>
  );
}
