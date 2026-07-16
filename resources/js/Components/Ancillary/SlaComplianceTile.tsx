import { AlertTriangle, CheckCircle2, CircleDashed, HelpCircle, LoaderCircle } from 'lucide-react';
import type { MetricTileContract, OperationalState } from './schemas';
import { SourceFreshnessBadge } from './SourceFreshnessBadge';

const STATE: Record<OperationalState, { label: string; icon: typeof CheckCircle2; className: string }> = {
  normal: { label: 'Within target', icon: CheckCircle2, className: 'text-healthcare-success dark:text-healthcare-success-dark' },
  warning: { label: 'Approaching threshold', icon: AlertTriangle, className: 'text-healthcare-warning dark:text-healthcare-warning-dark' },
  breach: { label: 'Threshold breached', icon: AlertTriangle, className: 'text-healthcare-critical dark:text-healthcare-critical-dark' },
  stale: { label: 'Stale · metric unavailable', icon: HelpCircle, className: 'text-healthcare-warning dark:text-healthcare-warning-dark' },
  no_data: { label: 'No cohort data', icon: CircleDashed, className: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark' },
  degraded: { label: 'Degraded · partial feed', icon: AlertTriangle, className: 'text-healthcare-warning dark:text-healthcare-warning-dark' },
  loading: { label: 'Loading metric', icon: LoaderCircle, className: 'text-healthcare-info dark:text-healthcare-info-dark' },
};

export function SlaComplianceTile({ value, onOpenDefinition }: { value: MetricTileContract; onOpenDefinition?: (definitionUuid: string) => void }) {
  const style = STATE[value.status];
  const Icon = style.icon;
  const unavailable = ['stale', 'no_data', 'loading'].includes(value.status);

  return (
    <article aria-label={`${value.label}: ${style.label}`} aria-busy={value.status === 'loading'} className="rounded-lg border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex items-start justify-between gap-2">
        <div>
          <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{value.label}</h3>
          <p className="mt-1 text-2xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{unavailable ? 'Unavailable' : value.displayValue}</p>
        </div>
        <span role="img" aria-label={style.label} className={style.className}><Icon className={`size-5 ${value.status === 'loading' ? 'animate-spin' : ''}`} aria-hidden="true" /></span>
      </div>
      <p className={`mt-2 text-xs ${style.className}`}>{style.label}</p>
      <dl className="mt-3 grid grid-cols-3 gap-2 text-xs">
        <div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Cohort</dt><dd className="tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value.cohortCount}</dd></div>
        <div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Median</dt><dd className="tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value.median === null ? '—' : value.median}</dd></div>
        <div><dt className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">P90</dt><dd className="tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value.p90 === null ? '—' : value.p90}</dd></div>
      </dl>
      {value.explanation && <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{value.explanation}</p>}
      <div className="mt-3 flex flex-wrap items-center gap-2">
        <SourceFreshnessBadge value={value.freshness} />
        {value.definition && onOpenDefinition && <button type="button" className="rounded-md px-2 py-1 text-xs text-healthcare-info focus:outline-none focus:ring-2 focus:ring-healthcare-info dark:text-healthcare-info-dark" onClick={() => onOpenDefinition(value.definition!.definitionUuid)}>View definition</button>}
      </div>
    </article>
  );
}
