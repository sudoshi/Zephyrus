// resources/js/Components/CommandCenter/KpiTile.tsx
import { Link } from '@inertiajs/react';
import type { KpiMetric } from '@/types/commandCenter';
import { STATUS_VAR } from './status';
import { Panel } from './Panel';
import { Gauge } from './Gauge';

function Sparkline({
  points, color, target, id,
}: { points: number[]; color: string; target: number | null; id: string }) {
  if (points.length < 2) return null;
  const w = 168;
  const h = 42;
  const pad = 4;
  const lo = Math.min(...points, ...(target != null ? [target] : []));
  const hi = Math.max(...points, ...(target != null ? [target] : []));
  const span = hi - lo || 1;
  const project = (p: number, i: number): [number, number] => [
    pad + (i / (points.length - 1)) * (w - 2 * pad),
    pad + (1 - (p - lo) / span) * (h - 2 * pad),
  ];
  const pts = points.map(project);
  const line = pts.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ');
  const [firstX] = pts[0];
  const [lastX, lastY] = pts[pts.length - 1];
  const area = `${line} L${lastX.toFixed(1)},${h - pad} L${firstX.toFixed(1)},${h - pad} Z`;
  const targetY = target != null ? pad + (1 - (target - lo) / span) * (h - 2 * pad) : null;

  return (
    <svg
      className="h-10 w-full overflow-visible"
      viewBox={`0 0 ${w} ${h}`}
      preserveAspectRatio="none"
      aria-hidden="true"
      data-testid={`sparkline-${id}`}
    >
      <defs>
        <linearGradient id={`spark-${id}`} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={color} stopOpacity={0.28} />
          <stop offset="100%" stopColor={color} stopOpacity={0} />
        </linearGradient>
      </defs>
      <path d={area} fill={`url(#spark-${id})`} stroke="none" />
      {targetY != null && (
        <line
          x1={pad} y1={targetY} x2={w - pad} y2={targetY}
          className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
          stroke="currentColor" strokeWidth={1} strokeDasharray="3 2" opacity={0.5}
        />
      )}
      <path d={line} fill="none" stroke={color} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
      <circle cx={lastX} cy={lastY} r={2.8} fill={color} />
    </svg>
  );
}

export function KpiTile({ metric }: { metric: KpiMetric }) {
  const color = STATUS_VAR[metric.status];
  const arrow = metric.trajectory
    ? metric.trajectory.direction === 'up' ? '▲'
      : metric.trajectory.direction === 'down' ? '▼' : '▬'
    : null;
  const isPct = metric.unit === '%';

  const targetRow = metric.targetDisplay ? (
    <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
      Target {metric.targetDisplay}
    </span>
  ) : null;

  const body = (
    <Panel className="flex h-full flex-col gap-2 p-4" style={{ borderLeft: `3px solid ${color}` }}>
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {metric.label}
        </span>
        <span title={metric.definition} aria-label={`Definition: ${metric.definition}`}
              className="text-xs leading-none text-healthcare-text-secondary/50 dark:text-healthcare-text-secondary-dark/50">
          {'ⓘ'}
        </span>
      </div>

      {isPct ? (
        <div className="flex items-center justify-between gap-3">
          <Gauge value={metric.value} max={100} target={metric.target} color={color}
                 size={72} strokeWidth={8} centerLabel={metric.display} centerLabelClass="text-base" />
          <div className="flex flex-col items-end gap-1 text-right">
            {arrow && (
              <span className="text-lg font-semibold leading-none" style={{ color }} aria-hidden="true">{arrow}</span>
            )}
            {targetRow}
          </div>
        </div>
      ) : (
        <>
          <div className="flex items-end justify-between gap-2">
            <span className="text-2xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {metric.display}
            </span>
            {metric.trajectory && (
              <span className="text-lg font-semibold leading-none" style={{ color }} aria-hidden="true">
                {arrow}
              </span>
            )}
          </div>
          {metric.trajectory && (
            <Sparkline points={metric.trajectory.points} color={color} target={metric.target} id={metric.key} />
          )}
          {targetRow}
        </>
      )}
    </Panel>
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
