// resources/js/Components/CommandCenter/UnitHeatStrip.tsx
import { Icon } from '@iconify/react';
import type { UnitCensus, StatusLevel } from '@/types/commandCenter';
import { STATUS_VAR } from './status';
import { Panel } from './Panel';
import { EmptyState } from './states';
import { MeterBar } from '@/Components/cockpit/MeterBar';

// Non-color status cue (the Status-Never-Alone Rule): each level carries a
// distinct icon shape AND a word, so the capacity read survives grayscale wall
// displays and color-blindness — the top stripe + value color are never the
// only signal. The word labels read as capacity, not abstract severity.
const STATUS_META: Record<StatusLevel, { icon: string; label: string }> = {
  critical: { icon: 'heroicons:exclamation-triangle', label: 'Full' },
  warning: { icon: 'heroicons:exclamation-circle', label: 'Tight' },
  success: { icon: 'heroicons:check-circle', label: 'Open' },
  info: { icon: 'heroicons:information-circle', label: 'Info' },
  neutral: { icon: 'heroicons:minus-circle', label: '—' },
};

export function UnitHeatStrip({ units }: { units: UnitCensus[] }) {
  if (units.length === 0) {
    return (
      <div aria-label="Unit census heat map">
        <EmptyState message="No units reporting census" />
      </div>
    );
  }
  return (
    // 168px columns match the capacity band's KPI-tile grid so the section
    // reads as one system. Wide enough that unit names stop truncating.
    <div aria-label="Unit census heat map" className="grid gap-2"
         style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(168px, 1fr))' }}>
      {units.map((u) => {
        const color = STATUS_VAR[u.status];
        const meta = STATUS_META[u.status];
        const pct = Math.max(0, Math.min(100, u.occupancyPct));
        return (
          <Panel key={u.unitId}
                 title={`${u.name}: ${u.occupied}/${u.staffed} occupied, ${u.available} available, ${u.blocked} blocked`}
                 className="flex flex-col gap-2 p-3"
                 style={{ borderTop: `3px solid ${color}` }}>
            <div className="flex items-start justify-between gap-2">
              <span className="truncate text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                {u.name}
              </span>
              <Icon icon={meta.icon} aria-hidden="true"
                    className="mt-0.5 h-4 w-4 shrink-0" style={{ color }} />
            </div>

            <div className="flex items-baseline gap-1.5">
              <span className="text-2xl font-semibold tabular-nums leading-none" style={{ color }}>
                {u.occupancyPct}%
              </span>
              <span className="text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {meta.label}
              </span>
            </div>

            {/* Per-unit occupancy mini-bar (shared cockpit primitive) */}
            <MeterBar pct={pct} status={u.status} />

            <span className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {u.occupied}/{u.staffed} beds {'·'} {u.available} open
            </span>
          </Panel>
        );
      })}
    </div>
  );
}
