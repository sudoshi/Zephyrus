// resources/js/Components/arena/review/ReviewChronobar.tsx
//
// The temporal frame: the evaluated 48h as a band, with one marker per barrier
// that has an opened time, coloured by severity. Adapts the NavigatorChronobar
// grammar (solid window · shift detents · gold now-line) without its three.js
// machinery — this is a static review window, so it's plain SVG/CSS.
import type { RankedBarrier } from '@/features/arena/reviewSchema';
import { SEVERITY_STRIPE } from './format';

function fmtClock(ms: number): string {
  return new Date(ms).toLocaleString(undefined, { weekday: 'short', hour: '2-digit', minute: '2-digit' });
}

interface Props {
  window: { from: string; to: string; label: string };
  barriers: RankedBarrier[];
  selectedId: string | null;
  onSelect: (id: string) => void;
}

export function ReviewChronobar({ window: win, barriers, selectedId, onSelect }: Props) {
  const from = Date.parse(win.from);
  const to = Date.parse(win.to);
  const span = Math.max(1, to - from);

  const markers = barriers
    .filter((barrier): barrier is RankedBarrier & { opened_at: string } => Boolean(barrier.opened_at))
    .map((barrier) => ({
      barrier,
      pct: Math.min(100, Math.max(0, ((Date.parse(barrier.opened_at) - from) / span) * 100)),
    }));

  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="mb-2 flex items-center justify-between text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        <span className="tabular-nums">{fmtClock(from)}</span>
        <span className="font-medium">Review window · 48h</span>
        <span className="tabular-nums">now · {fmtClock(to)}</span>
      </div>

      <div className="relative h-8 rounded-md border border-healthcare-border bg-healthcare-hover dark:border-healthcare-border-dark dark:bg-healthcare-hover-dark">
        {/* shift detents at the window thirds */}
        <div className="absolute inset-y-0 left-1/3 w-px bg-healthcare-border dark:bg-healthcare-border-dark" />
        <div className="absolute inset-y-0 left-2/3 w-px bg-healthcare-border dark:bg-healthcare-border-dark" />
        {/* now line — gold accent via the theme token (--accent: gold, both themes) */}
        <div className="absolute inset-y-0 right-0 w-0.5" style={{ backgroundColor: 'var(--accent)' }} aria-hidden="true" />

        {markers.map(({ barrier, pct }) => (
          <button
            key={barrier.id}
            type="button"
            onClick={() => onSelect(barrier.id)}
            style={{ left: `${pct}%` }}
            title={`${barrier.title} · opened ${fmtClock(Date.parse(barrier.opened_at))}`}
            aria-label={`${barrier.title}, opened ${fmtClock(Date.parse(barrier.opened_at))}`}
            className={`absolute top-2.5 h-3 w-3 -translate-x-1/2 rounded-full border-2 border-healthcare-surface transition-transform hover:scale-125 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-healthcare-gold dark:border-healthcare-surface-dark ${SEVERITY_STRIPE[barrier.severity]} ${
              selectedId === barrier.id ? 'ring-2 ring-healthcare-primary' : ''
            }`}
          />
        ))}
      </div>
    </div>
  );
}
