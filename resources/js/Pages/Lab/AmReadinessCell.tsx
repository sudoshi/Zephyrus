import { Sparkles, MinusCircle } from 'lucide-react';
import type { AmReadinessForecast } from '@/features/lab/schemas';

const BAND_LABEL: Record<NonNullable<AmReadinessForecast['band']>, string> = {
  on_track: 'On track for rounds',
  at_risk: 'At risk for rounds',
  unlikely: 'Unlikely by cutoff',
};

/**
 * Optional Laboratory AM-readiness planning FORECAST cell. This is a
 * server-computed synthetic planning aid, NOT the observed readiness state and
 * NOT an alarm. It predicts the probability a decision-class order verifies
 * before the rounds cutoff. It is deliberately styled distinctly from the
 * observed SLA/urgency badges (a "Forecast" label, sparkles icon, neutral/info
 * tokens — never the coral breach treatment); the band is conveyed by icon +
 * text label, not color alone. Missing/stale signals render as
 * unavailable/low-confidence text rather than a fabricated number.
 */
export function AmReadinessCell({ forecast }: { forecast: AmReadinessForecast }) {
  if (forecast.availability === 'unavailable' || forecast.probability === null || forecast.band === null) {
    return (
      <div className="flex items-center gap-1.5 rounded-md border border-dashed border-healthcare-border px-2 py-1 text-xs text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark" title={forecast.explanation}>
        <MinusCircle className="size-3.5" aria-hidden="true" />
        <span>Forecast unavailable</span>
      </div>
    );
  }

  const percent = Math.round(forecast.probability * 100);
  const bandLabel = BAND_LABEL[forecast.band];

  return (
    <details className="text-left">
      <summary className="flex cursor-pointer items-center gap-1.5 rounded-md border border-healthcare-info/40 bg-healthcare-info/10 px-2 py-1 text-xs text-healthcare-info focus:outline-none focus:ring-2 focus:ring-healthcare-info dark:border-healthcare-info-dark/40 dark:text-healthcare-info-dark">
        <Sparkles className="size-3.5" aria-hidden="true" />
        <span className="font-medium">Forecast</span>
        <span className="tabular-nums">{percent}%</span>
        <span>· {bandLabel}</span>
        {forecast.availability === 'low_confidence' ? <span className="text-healthcare-warning dark:text-healthcare-warning-dark">· low confidence</span> : null}
      </summary>
      <div className="mt-2 max-w-xs rounded-md border border-healthcare-border bg-healthcare-surface p-2 text-xs dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{forecast.explanation}</p>
        {forecast.roundsCutoffLabel ? (
          <p className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Cutoff: {forecast.roundsCutoffLabel}</p>
        ) : null}
        {forecast.factors.length > 0 ? (
          <div className="mt-2">
            <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Helps</p>
            <ul className="mt-1 space-y-1">
              {forecast.factors.map((factor) => (
                <li key={factor.feature} className="flex items-center justify-between gap-3 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  <span>{factor.label}</span>
                  <span className="tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">+{factor.contribution.toFixed(2)}</span>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
        {forecast.headwinds.length > 0 ? (
          <div className="mt-2">
            <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Slows</p>
            <ul className="mt-1 space-y-1">
              {forecast.headwinds.map((factor) => (
                <li key={factor.feature} className="flex items-center justify-between gap-3 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  <span>{factor.label}</span>
                  <span className="tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{factor.contribution.toFixed(2)}</span>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </div>
    </details>
  );
}
