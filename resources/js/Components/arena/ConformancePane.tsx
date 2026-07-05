// resources/js/Components/arena/ConformancePane.tsx
//
// Part X (X3) — patient-safety conformance. Each reference pathway shows its
// live adherence rate (an OBSERVED measure, so it earns status colour) and the
// ranked, real deviations the OCPM engine found in the OCEL log. Status is never
// colour-alone: the rate is always spelled out as a number + label.
import type { ArenaPathwayConformance } from '@/features/arena/schema';

type Band = 'success' | 'warning' | 'critical';

function band(rate: number | null): Band {
  if (rate === null) return 'warning';
  if (rate >= 0.9) return 'success';
  if (rate >= 0.75) return 'warning';
  return 'critical';
}

const BAND_TEXT: Record<Band, string> = {
  success: 'text-healthcare-success dark:text-healthcare-success-dark',
  warning: 'text-healthcare-warning dark:text-healthcare-warning-dark',
  critical: 'text-healthcare-critical dark:text-healthcare-critical-dark',
};

const BAND_BAR: Record<Band, string> = {
  success: 'bg-healthcare-success dark:bg-healthcare-success-dark',
  warning: 'bg-healthcare-warning dark:bg-healthcare-warning-dark',
  critical: 'bg-healthcare-critical dark:bg-healthcare-critical-dark',
};

function PathwayCard({ pathway }: { pathway: ArenaPathwayConformance }) {
  const rate = pathway.conformance_rate;
  const pct = rate === null ? '—' : `${Math.round(rate * 100)}%`;
  const tone = band(rate);

  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-5 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {pathway.label}
          </h3>
          <p className="mt-0.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            v{pathway.version} · {pathway.owner} · {pathway.case_type}
          </p>
        </div>
        <div className="text-right">
          <div className={`tabular-nums text-2xl font-semibold ${BAND_TEXT[tone]}`}>{pct}</div>
          <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">conformant</div>
        </div>
      </div>

      {/* rate bar */}
      <div className="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-healthcare-border dark:bg-healthcare-border-dark">
        <div className={`h-full rounded-full ${BAND_BAR[tone]}`} style={{ width: rate === null ? '0%' : `${rate * 100}%` }} />
      </div>

      <div className="mt-3 flex gap-6 text-xs">
        <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Conformant{' '}
          <span className="tabular-nums font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {pathway.conformant.toLocaleString()}
          </span>
        </span>
        <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Deviant{' '}
          <span className="tabular-nums font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {pathway.deviant.toLocaleString()}
          </span>
        </span>
        <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Cases{' '}
          <span className="tabular-nums font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {pathway.cases.toLocaleString()}
          </span>
        </span>
      </div>

      {pathway.deviations.length > 0 && (
        <ul className="mt-4 space-y-2 border-t border-healthcare-border pt-3 dark:border-healthcare-border-dark">
          {pathway.deviations.map((deviation) => (
            <li key={deviation.code} className="flex items-center justify-between gap-3 text-sm">
              <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{deviation.label}</span>
              <span className="tabular-nums font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {deviation.count.toLocaleString()}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function ConformancePane({ pathways }: { pathways: ArenaPathwayConformance[] }) {
  if (pathways.length === 0) {
    return (
      <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        No reference pathways matched the current log.
      </p>
    );
  }

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
      {pathways.map((pathway) => (
        <PathwayCard key={pathway.pathway} pathway={pathway} />
      ))}
    </div>
  );
}
