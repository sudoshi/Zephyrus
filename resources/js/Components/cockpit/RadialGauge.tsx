// resources/js/Components/cockpit/RadialGauge.tsx
//
// Generalization of Components/CommandCenter/Gauge.tsx (Zephyrus 2.0 P0):
// `scale` replaces `max`, color is DERIVED from status via statusStyle (the
// free-hex color prop is gone — a tightening), and an optional `bands` array
// renders a multi-segment context arc (e.g. the NEDOCS 0–200 five-band scale).
// Bands are faint context; the value arc is the signal. The original Gauge
// stays in place for its existing consumers until P2 migrates them.
import type { StatusLevel } from '@/types/commandCenter';
import { statusStyle } from './statusStyle';

export interface RadialGaugeBand {
  /** Upper edge of the band, in scale units (bands render from the previous edge). */
  edge: number;
  level: StatusLevel;
}

export interface RadialGaugeProps {
  value: number;
  /** Full-scale value (default 100). */
  scale?: number;
  status: StatusLevel;
  bands?: RadialGaugeBand[];
  /** Optional target — drawn as a tick mark on the ring. */
  target?: number | null;
  size?: number;
  strokeWidth?: number;
  /** Center label (headline value). */
  big?: string;
  /** Center sub-label. */
  small?: string;
  /** Tailwind size class for the center label. */
  bigClass?: string;
}

export function RadialGauge({
  value,
  scale = 100,
  status,
  bands,
  target = null,
  size = 68,
  strokeWidth = 8,
  big,
  small,
  bigClass = 'text-sm',
}: RadialGaugeProps) {
  const color = statusStyle(status).color;
  const r = (size - strokeWidth) / 2;
  const c = 2 * Math.PI * r;
  const frac = scale === 0 ? 0 : Math.max(0, Math.min(1, value / scale));
  const dashoffset = c * (1 - frac);
  const cx = size / 2;
  const cy = size / 2;

  // Target tick — angle measured from the top (12 o'clock), clockwise.
  let tick = null;
  if (target != null && scale > 0) {
    const a = Math.max(0, Math.min(1, target / scale)) * 2 * Math.PI;
    const sin = Math.sin(a);
    const cos = Math.cos(a);
    const inner = r - strokeWidth / 2 - 1;
    const outer = r + strokeWidth / 2 + 1;
    tick = (
      <line
        x1={cx + inner * sin}
        y1={cy - inner * cos}
        x2={cx + outer * sin}
        y2={cy - outer * cos}
        className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
        stroke="currentColor"
        strokeWidth={2}
        strokeLinecap="round"
        opacity={0.9}
      />
    );
  }

  // Multi-band context arc: each band paints its slice of the TRACK circle at
  // low opacity, so the scale's danger zones are visible without shouting.
  let bandArcs = null;
  if (bands && bands.length > 0 && scale > 0) {
    let prevEdge = 0;
    bandArcs = bands.map((band) => {
      const from = Math.max(0, Math.min(1, prevEdge / scale));
      const to = Math.max(from, Math.min(1, band.edge / scale));
      prevEdge = band.edge;
      const segLen = (to - from) * c;
      if (segLen <= 0) return null;
      return (
        <circle
          key={`${band.level}-${band.edge}`}
          cx={cx} cy={cy} r={r} fill="none" strokeWidth={strokeWidth}
          stroke={statusStyle(band.level).color} opacity={0.22}
          strokeDasharray={`${segLen} ${c - segLen}`}
          strokeDashoffset={-from * c}
        />
      );
    });
  }

  return (
    <div className="relative inline-flex items-center justify-center" style={{ width: size, height: size }}>
      {/* Track + band context + progress arc (rotated so arcs start at the top). */}
      <svg width={size} height={size} className="-rotate-90" aria-hidden="true">
        <circle
          cx={cx} cy={cy} r={r} fill="none" strokeWidth={strokeWidth}
          className="text-healthcare-border dark:text-healthcare-border-dark"
          stroke="currentColor"
        />
        {bandArcs}
        <circle
          cx={cx} cy={cy} r={r} fill="none" strokeWidth={strokeWidth}
          stroke={color} strokeLinecap="round"
          strokeDasharray={c} strokeDashoffset={dashoffset}
          className="transition-[stroke-dashoffset] duration-700 ease-out"
        />
      </svg>
      {/* Target tick — drawn un-rotated, also measured from the top. */}
      {tick && (
        <svg width={size} height={size} className="absolute inset-0" aria-hidden="true">
          {tick}
        </svg>
      )}
      {(big || small) && (
        <div className="pointer-events-none absolute flex flex-col items-center leading-none">
          {big && (
            <span className={`font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark ${bigClass}`}>
              {big}
            </span>
          )}
          {small && (
            <span className="mt-0.5 text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {small}
            </span>
          )}
        </div>
      )}
    </div>
  );
}
