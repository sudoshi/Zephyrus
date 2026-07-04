// resources/js/Components/cockpit/MeterBar.tsx
//
// EXTRACTED from the bar pattern repeated in UnitHeatStrip / OkrScoreboard /
// KpiTile detail segments (Zephyrus 2.0 P0). ISA-101 discipline: the empty
// track is always NEUTRAL (healthcare-border) — status color appears only in
// the fill, so a calm screen shows grey bars on a slate field.
import type { StatusLevel } from '@/types/commandCenter';
import { statusStyle } from './statusStyle';

export interface MeterBarProps {
  /** Fill percentage 0–100 (clamped). */
  pct: number;
  status: StatusLevel;
  /** Accessible label; when provided the bar exposes role="meter". */
  label?: string;
  /** Render the neutral track behind the fill (default true). */
  track?: boolean;
  /** Bar height in px (default 6 — matches the existing h-1.5 mini-bars). */
  h?: number;
  /** Optional data-testid applied to the FILL element (legacy test hooks). */
  testId?: string;
}

export function MeterBar({ pct, status, label, track = true, h = 6, testId }: MeterBarProps) {
  const clamped = Math.max(0, Math.min(100, pct));
  const { color } = statusStyle(status);
  const a11y = label
    ? ({ role: 'meter', 'aria-label': label, 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': clamped } as const)
    : ({ 'aria-hidden': true } as const);

  return (
    <div
      {...a11y}
      className={`w-full overflow-hidden rounded-full ${
        track ? 'bg-healthcare-border dark:bg-healthcare-border-dark' : ''
      }`}
      style={{ height: h }}
    >
      <div
        data-testid={testId}
        className="h-full rounded-full transition-[width] duration-500 ease-out"
        style={{ width: `${clamped}%`, background: color }}
      />
    </div>
  );
}
