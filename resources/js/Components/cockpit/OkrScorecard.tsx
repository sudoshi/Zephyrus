// resources/js/Components/cockpit/OkrScorecard.tsx
//
// The executive OKR scorecard (Zephyrus 2.0 P2) — all registry OKR cards from
// the snapshot (9 with the Appendix-A catalog; the grid is count-agnostic).
// Progress bars are direction-aware "progress toward target"; value coloring
// follows the same valuePrimary ration as every other cockpit number. The
// section header is a drill entry point (D2 → P3 opens the okr DrillModal).
import type { CockpitDrillDomain, OkrCard } from '@/types/cockpit';
import { Surface } from '@/Components/ui/Surface';
import { COCKPIT_STATE_TO_LEVEL, statusStyle } from './statusStyle';
import { MeterBar } from './MeterBar';
import { ProvenanceBadge } from './ProvenanceBadge';
import { formatMetricTarget } from './metricFormatting';

/**
 * Progress toward target, clamped 0–100. For lower-is-better metrics an
 * at-or-under-target value is 100%; overshoot decays as target/value so the
 * bar stays meaningful instead of pinning to zero.
 */
export function okrProgressPct(card: OkrCard): number | null {
  if (card.target == null || card.target <= 0 || card.value < 0) return null;
  if (card.direction === 'down') {
    return card.value <= card.target ? 100 : Math.max(0, Math.min(100, (card.target / card.value) * 100));
  }
  return Math.max(0, Math.min(100, (card.value / card.target) * 100));
}

interface OkrScorecardProps {
  okrs: OkrCard[];
  /** Omit for static wall mode so the section heading has no control semantics. */
  onDrill?: (domain: CockpitDrillDomain) => void;
}

export function OkrScorecard({ okrs, onDrill }: OkrScorecardProps) {
  if (okrs.length === 0) return null;

  return (
    <section aria-label="Executive OKR scorecard" data-testid="cockpit-okr-scorecard" className="flex flex-col gap-2">
      {onDrill ? (
        <button
          type="button"
          onClick={() => onDrill('okr')}
          aria-haspopup="dialog"
          aria-label="Open OKR scorecard drill-down"
          className="-m-1 flex w-fit items-center gap-2 rounded p-1 text-left transition-colors hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark"
        >
          <span className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Executive OKR Scorecard
          </span>
          <span aria-hidden="true" className="text-sm leading-none text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {'⤢'}
          </span>
        </button>
      ) : (
        <span className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          Executive OKR Scorecard
        </span>
      )}

      <div className="grid gap-2" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))' }}>
        {okrs.map((card) => {
          const s = statusStyle(COCKPIT_STATE_TO_LEVEL[card.status]);
          const pct = okrProgressPct(card);
          const demo = card.metadata?.provenance === 'demo';

          return (
            <Surface key={card.key} className="flex flex-col gap-1 p-3" data-testid={`okr-card-${card.key}`}>
              <span className="flex items-start justify-between gap-2">
                <span className="min-w-0 truncate text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {card.objective ?? 'Objective'}
                </span>
                <span className="flex shrink-0 items-center gap-1.5">
                  {demo && <ProvenanceBadge />}
                  <span role="img" aria-label={s.label} className="text-xs leading-none" style={{ color: s.color }}>
                    {s.glyph}
                  </span>
                </span>
              </span>

              <span className="text-sm font-medium leading-snug text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                {card.keyResult}
              </span>

              <span
                className={`text-xl font-semibold tabular-nums leading-none ${
                  s.valuePrimary ? 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark' : ''
                }`}
                style={s.valuePrimary ? undefined : { color: s.color }}
              >
                {card.display}
              </span>

              {pct != null && (
                <MeterBar
                  pct={pct}
                  status={COCKPIT_STATE_TO_LEVEL[card.status]}
                  label={`${card.keyResult} progress toward target`}
                />
              )}

              <span className="mt-auto flex items-center justify-between gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                <span className="tabular-nums">
                  {formatMetricTarget(card.target, card.unit)}
                </span>
                {card.owner && <span className="truncate">{card.owner}</span>}
              </span>
            </Surface>
          );
        })}
      </div>
    </section>
  );
}
