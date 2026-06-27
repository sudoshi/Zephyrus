// resources/js/Components/CommandCenter/KpiTile.tsx
import { Link } from '@inertiajs/react';
import type { KpiMetric } from '@/types/commandCenter';
import { STATUS_VAR } from './status';
import { Panel } from './Panel';
import { Gauge } from './Gauge';

// All status color flows through STATUS_VAR (the canonical CSS-var palette) so a
// tile shows exactly one coral, one amber, one teal — never a second near-match
// from the Tailwind healthcare-* tokens.

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

function DetailVisualization({ metric }: { metric: KpiMetric }) {
  if (!metric.detail) return null;

  const positiveSegments = metric.detail.segments.map((segment) => ({
    ...segment,
    value: Math.max(0, segment.value),
  }));
  const total = positiveSegments.reduce((sum, segment) => sum + segment.value, 0);
  const equalWidth = positiveSegments.length > 0 ? 100 / positiveSegments.length : 0;

  return (
    <div className="mt-1 flex flex-col gap-2" data-testid={`metric-detail-${metric.key}`}>
      <span className="min-w-0 truncate text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        {metric.detail.caption}
      </span>

      {positiveSegments.length > 0 && (
        <div
          className="h-2 overflow-hidden rounded-full bg-healthcare-border/70 dark:bg-healthcare-border-dark/70"
          aria-label={`${metric.label} detail breakdown`}
        >
          <div className="flex h-full w-full">
            {positiveSegments.map((segment) => {
              const width = total > 0 ? (segment.value / total) * 100 : equalWidth;

              return (
                <span
                  key={segment.label}
                  className="h-full min-w-[3px]"
                  title={`${segment.label}: ${segment.display}`}
                  style={{ width: `${width}%`, backgroundColor: STATUS_VAR[segment.status] }}
                />
              );
            })}
          </div>
        </div>
      )}

      <div className="grid grid-cols-2 gap-x-3 gap-y-1">
        {metric.detail.rows.slice(0, 4).map((row) => (
          <div key={row.label} className="min-w-0">
            <div className="truncate text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {row.label}
            </div>
            <div className="truncate text-xs font-semibold tabular-nums" style={{ color: STATUS_VAR[row.status] }}>
              {row.value}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

export function KpiTile({ metric, detailed = false }: { metric: KpiMetric; detailed?: boolean }) {
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
  const trustBadge = metric.sourceTrust ? (
    <span
      title={metric.lineageSummary ?? `Source trust ${metric.sourceTrust.score}%`}
      aria-label={`Source trust: ${metric.sourceTrust.score}%`}
      style={{ color: STATUS_VAR[metric.sourceTrust.status] }}
      className="shrink-0 rounded-full border border-healthcare-border/70 bg-healthcare-surface/70 px-1.5 py-0.5 text-xs font-semibold tabular-nums dark:border-healthcare-border-dark/70 dark:bg-healthcare-surface-dark/70"
    >
      Trust {metric.sourceTrust.score}%
    </span>
  ) : null;

  const body = (
    <Panel className="flex h-full flex-col gap-2 p-4">
      <div className="flex items-center justify-between gap-2">
        <span className="flex min-w-0 items-center gap-1.5">
          {/* Status dot (replaces the banned left side-stripe); the gauge/number
              color + trend arrow carry the same status redundantly. */}
          <span aria-hidden="true" className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ background: color }} />
          <span className="min-w-0 truncate text-xs uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {metric.label}
          </span>
        </span>
        <span className="flex shrink-0 items-center gap-1">
          {trustBadge}
          <span title={metric.definition} aria-label={`Definition: ${metric.definition}`}
                className="text-xs leading-none text-healthcare-text-secondary/50 dark:text-healthcare-text-secondary-dark/50">
            {'ⓘ'}
          </span>
        </span>
      </div>

      {isPct ? (
        <>
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
          {detailed && metric.detail && metric.trajectory && (
            <Sparkline points={metric.trajectory.points} color={color} target={metric.target} id={metric.key} />
          )}
          {detailed && <DetailVisualization metric={metric} />}
        </>
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
          {detailed && metric.trajectory && (
            <Sparkline points={metric.trajectory.points} color={color} target={metric.target} id={metric.key} />
          )}
          {targetRow}
          {detailed && <DetailVisualization metric={metric} />}
        </>
      )}
      {metric.caption && (
        <span className="mt-auto text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          {metric.caption}
        </span>
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
