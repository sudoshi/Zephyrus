// resources/js/Components/cockpit/Sparkline.tsx
//
// EXTRACTED from Components/CommandCenter/KpiTile.tsx (Zephyrus 2.0 P0) so
// every section shares ONE sparkline implementation — the same area-gradient +
// terminal-dot rendering, now status-driven via statusStyle. Decorative:
// aria-hidden; the labeled value beside it is the accessible name.
import type { StatusLevel } from '@/types/commandCenter';
import { statusStyle } from './statusStyle';

export interface SparklineProps {
  data: number[];
  status: StatusLevel;
  target?: number | null;
  id: string;
  w?: number;
  h?: number;
}

export function Sparkline({ data, status, target = null, id, w = 168, h = 42 }: SparklineProps) {
  if (data.length < 2) return null;
  const color = statusStyle(status).color;
  const pad = 4;
  const lo = Math.min(...data, ...(target != null ? [target] : []));
  const hi = Math.max(...data, ...(target != null ? [target] : []));
  const span = hi - lo || 1;
  const project = (p: number, i: number): [number, number] => [
    pad + (i / (data.length - 1)) * (w - 2 * pad),
    pad + (1 - (p - lo) / span) * (h - 2 * pad),
  ];
  const pts = data.map(project);
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
