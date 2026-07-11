// resources/js/Components/arena/review/ReviewSummaryStrip.tsx
//
// The summary-before-detail row: the five numbers a reviewer scans first, each
// with a this-vs-last delta the 48-hour job computes. Summary before detail is
// the dashboard discipline (CLAUDE.md).
import type { ReactNode } from 'react';
import type { ArenaReviewResponse } from '@/features/arena/reviewSchema';
import { DeltaBadge } from './DeltaBadge';

type Stats = Extract<ArenaReviewResponse, { available: true }>['stats'];

function Tile({ label, value, children }: { label: string; value: string; children?: ReactNode }) {
  return (
    <div className="flex-1 rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark" style={{ minWidth: '9rem' }}>
      <div className="text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</div>
      <div className="mt-1 tabular-nums text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</div>
      <div className="mt-0.5 h-4">{children}</div>
    </div>
  );
}

export function ReviewSummaryStrip({ stats }: { stats: Stats }) {
  const pathwayPct = stats.worst_pathway.rate === null ? '—' : `${Math.round(stats.worst_pathway.rate * 100)}%`;
  const handoffDelta = stats.worst_handoff.delta_pct;
  const pathwayDelta = stats.worst_pathway.delta_pt;

  return (
    <div className="flex flex-wrap gap-3">
      <Tile label="Open barriers" value={stats.open_barriers.toLocaleString()} />
      <Tile label="New this window" value={stats.new_barriers.toLocaleString()} />
      <Tile label="Worst hand-off" value={stats.worst_handoff.value_label}>
        {handoffDelta !== null && (
          <DeltaBadge direction={handoffDelta > 0 ? 'up' : handoffDelta < 0 ? 'down' : 'flat'} label={`${handoffDelta > 0 ? '+' : ''}${Math.round(handoffDelta)}% vs last`} />
        )}
      </Tile>
      <Tile label="Worst pathway" value={pathwayPct}>
        {pathwayDelta !== null && (
          <DeltaBadge direction={pathwayDelta > 0 ? 'down' : pathwayDelta < 0 ? 'up' : 'flat'} label={`${pathwayDelta > 0 ? '+' : ''}${Math.round(pathwayDelta)}pt vs last`} />
        )}
      </Tile>
      <Tile label="Actions pending" value={stats.actions_pending.toLocaleString()} />
    </div>
  );
}
