// resources/js/Components/CommandCenter/KpiTile.tsx
import { Link } from '@inertiajs/react';
import type { KpiMetric } from '@/types/commandCenter';
import { STATUS_VAR } from './status';

function Sparkline({ points, color }: { points: number[]; color: string }) {
  if (points.length < 2) return null;
  const w = 56, h = 16;
  const min = Math.min(...points);
  const span = Math.max(...points) - min || 1;
  const d = points
    .map((p, i) => {
      const x = (i / (points.length - 1)) * w;
      const y = h - ((p - min) / span) * h;
      return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
    })
    .join(' ');
  return (
    <svg width={w} height={h} viewBox={`0 0 ${w} ${h}`} aria-hidden="true">
      <path d={d} fill="none" stroke={color} strokeWidth={1.5} />
    </svg>
  );
}

export function KpiTile({ metric }: { metric: KpiMetric }) {
  const color = STATUS_VAR[metric.status];
  const arrow = metric.trajectory
    ? metric.trajectory.direction === 'up' ? '▲'
      : metric.trajectory.direction === 'down' ? '▼' : '▬'
    : null;

  const body = (
    <div className="flex h-full flex-col gap-1 rounded-md p-3
                    bg-healthcare-surface dark:bg-healthcare-surface-dark
                    border border-healthcare-border dark:border-healthcare-border-dark
                    shadow-sm hover:shadow-md dark:hover:shadow-[0_4px_12px_rgba(0,0,0,0.25)]
                    transition-all duration-300"
         style={{ borderLeft: `3px solid ${color}` }}>
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {metric.label}
        </span>
        <span title={metric.definition} aria-label={`Definition: ${metric.definition}`}
              className="text-xs leading-none text-healthcare-text-secondary/50 dark:text-healthcare-text-secondary-dark/50">
          {'ⓘ'}
        </span>
      </div>
      <div className="flex items-end justify-between gap-2">
        <span className="text-2xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {metric.display}
        </span>
        {metric.trajectory && (
          <span className="flex items-center gap-1 text-xs" style={{ color }}>
            <Sparkline points={metric.trajectory.points} color={color} />
            <span aria-hidden="true">{arrow}</span>
          </span>
        )}
      </div>
      {metric.targetDisplay && (
        <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Target {metric.targetDisplay}
        </span>
      )}
    </div>
  );

  if (metric.drillHref) {
    return (
      <Link href={metric.drillHref} className="block h-full" data-testid={`kpi-${metric.key}`}>
        {body}
      </Link>
    );
  }
  return <div data-testid={`kpi-${metric.key}`} className="h-full">{body}</div>;
}
