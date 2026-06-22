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
             className="flex flex-col gap-0.5 rounded p-2"
             style={{ background: 'var(--surface-raised)', borderTop: `3px solid ${STATUS_VAR[u.status]}` }}>
          <span className="truncate text-xs font-medium" style={{ color: 'var(--text-primary)' }}>{u.name}</span>
          <span className="text-lg font-semibold tabular-nums" style={{ color: STATUS_VAR[u.status] }}>
            {u.occupancyPct}%
          </span>
          <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
            {u.available} open {'·'} {u.blocked} blk
          </span>
        </div>
      ))}
    </div>
  );
}
