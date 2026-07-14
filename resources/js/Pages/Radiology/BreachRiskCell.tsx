import { Gauge, MinusCircle } from 'lucide-react';
import type { BreachRiskScore } from '@/features/radiology/schemas';

const BAND_LABEL: Record<NonNullable<BreachRiskScore['band']>, string> = {
  high: 'Higher planning risk',
  moderate: 'Moderate planning risk',
  low: 'Lower planning risk',
};

/**
 * Optional Radiology breach-risk planning cell. This is a server-computed sort
 * aid, never an alarm: the band is conveyed by an icon + text label (not color
 * alone) and uses neutral operational tokens — it never borrows the coral breach
 * treatment. Missing/stale signals render as unavailable/low-confidence text
 * rather than a fabricated score.
 */
export function BreachRiskCell({ risk }: { risk: BreachRiskScore }) {
  if (risk.availability === 'unavailable' || risk.probability === null || risk.band === null) {
    return (
      <div className="flex items-center gap-1.5 rounded-md border border-healthcare-border px-2 py-1 text-xs text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark" title={risk.explanation}>
        <MinusCircle className="size-3.5" aria-hidden="true" />
        <span>Risk unavailable</span>
      </div>
    );
  }

  const percent = Math.round(risk.probability * 100);
  const bandLabel = BAND_LABEL[risk.band];

  return (
    <details className="text-left">
      <summary className="flex cursor-pointer items-center gap-1.5 rounded-md border border-healthcare-border px-2 py-1 text-xs text-healthcare-text-secondary focus:outline-none focus:ring-2 focus:ring-healthcare-info dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
        <Gauge className="size-3.5" aria-hidden="true" />
        <span className="tabular-nums">{percent}%</span>
        <span>· {bandLabel}</span>
        {risk.availability === 'low_confidence' ? <span className="text-healthcare-warning dark:text-healthcare-warning-dark">· low confidence</span> : null}
      </summary>
      <div className="mt-2 max-w-xs rounded-md border border-healthcare-border bg-healthcare-surface p-2 text-xs dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{risk.explanation}</p>
        {risk.factors.length > 0 ? (
          <ul className="mt-2 space-y-1">
            {risk.factors.map((factor) => (
              <li key={factor.feature} className="flex items-center justify-between gap-3 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                <span>{factor.label}</span>
                <span className="tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">+{factor.contribution.toFixed(2)}</span>
              </li>
            ))}
          </ul>
        ) : null}
      </div>
    </details>
  );
}
