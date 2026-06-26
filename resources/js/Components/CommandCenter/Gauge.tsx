// resources/js/Components/CommandCenter/Gauge.tsx

interface GaugeProps {
  value: number;
  max?: number;
  /** Optional target — drawn as a tick mark on the ring. */
  target?: number | null;
  /** Progress arc color (status color: a hex or var(--…)). */
  color: string;
  size?: number;
  strokeWidth?: number;
  centerLabel?: string;
  centerSubLabel?: string;
  /** Tailwind size class for the center label (e.g. text-sm, text-2xl). */
  centerLabelClass?: string;
}

/**
 * Compact ring / donut gauge. The arc fills clockwise from the top to
 * value / max, colored by status, with an optional tick at the target. Center
 * text is optional (used for the headline value or a level label).
 */
export function Gauge({
  value,
  max = 100,
  target = null,
  color,
  size = 68,
  strokeWidth = 8,
  centerLabel,
  centerSubLabel,
  centerLabelClass = 'text-sm',
}: GaugeProps) {
  const r = (size - strokeWidth) / 2;
  const c = 2 * Math.PI * r;
  const frac = max === 0 ? 0 : Math.max(0, Math.min(1, value / max));
  const dashoffset = c * (1 - frac);
  const cx = size / 2;
  const cy = size / 2;

  // Target tick — angle measured from the top (12 o'clock), clockwise.
  let tick = null;
  if (target != null && max > 0) {
    const a = Math.max(0, Math.min(1, target / max)) * 2 * Math.PI;
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

  return (
    <div className="relative inline-flex items-center justify-center" style={{ width: size, height: size }}>
      {/* Track + progress arc (rotated so the arc starts at the top). */}
      <svg width={size} height={size} className="-rotate-90" aria-hidden="true">
        <circle
          cx={cx} cy={cy} r={r} fill="none" strokeWidth={strokeWidth}
          className="text-healthcare-border dark:text-healthcare-border-dark"
          stroke="currentColor"
        />
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
      {(centerLabel || centerSubLabel) && (
        <div className="pointer-events-none absolute flex flex-col items-center leading-none">
          {centerLabel && (
            <span className={`font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark ${centerLabelClass}`}>
              {centerLabel}
            </span>
          )}
          {centerSubLabel && (
            <span className="mt-0.5 text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {centerSubLabel}
            </span>
          )}
        </div>
      )}
    </div>
  );
}
