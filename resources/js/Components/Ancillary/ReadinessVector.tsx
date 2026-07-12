import { AlertTriangle, Check, CircleDashed, HelpCircle } from 'lucide-react';
import type { ReadinessAxisContract, ReadinessState } from './contracts';

const STATE: Record<ReadinessState, { label: string; icon: typeof Check; className: string }> = {
  ready: { label: 'Ready', icon: Check, className: 'text-healthcare-success dark:text-healthcare-success-dark' },
  pending: { label: 'Pending', icon: CircleDashed, className: 'text-healthcare-warning dark:text-healthcare-warning-dark' },
  blocked: { label: 'Blocked', icon: AlertTriangle, className: 'text-healthcare-critical dark:text-healthcare-critical-dark' },
  unknown: { label: 'Unknown', icon: HelpCircle, className: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark' },
  not_applicable: { label: 'Not applicable', icon: HelpCircle, className: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark' },
};

export interface ReadinessVectorProps {
  axes: ReadinessAxisContract[];
  variant?: 'compact' | 'expanded';
  onDrill?: (target: string) => void;
}

export function ReadinessVector({ axes, variant = 'expanded', onDrill }: ReadinessVectorProps) {
  return (
    <section aria-label="Ancillary readiness vector" className={`grid gap-2 ${variant === 'compact' ? 'grid-cols-1' : 'sm:grid-cols-2 lg:grid-cols-3'}`}>
      {axes.map((axis) => {
        const style = STATE[axis.freshness.status === 'stale' ? 'unknown' : axis.status];
        const Icon = style.icon;
        const body = (
          <>
            <span role="img" aria-label={style.label} className={style.className}><Icon className="size-4" aria-hidden="true" /></span>
            <span className="min-w-0 flex-1 text-left">
              <span className="block truncate text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{axis.label}</span>
              <span className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {style.label} · <span className="tabular-nums">{axis.pendingCount} pending · {axis.oldestAgeMinutes === null ? 'age unavailable' : `${axis.oldestAgeMinutes} min oldest`}</span>
              </span>
              <span className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{axis.freshness.status === 'batch' ? 'Warehouse as-of' : axis.freshness.status}{axis.blocking ? ' · Discharge blocking' : ''}</span>
            </span>
          </>
        );
        return onDrill ? (
          <button key={axis.key} type="button" disabled={axis.drillTarget === null} aria-label={`Open ${axis.label}: ${style.label}`} className="flex items-start gap-2 rounded-lg border border-healthcare-border bg-healthcare-surface p-3 focus:outline-none focus:ring-2 focus:ring-healthcare-info disabled:cursor-not-allowed disabled:opacity-60 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark" onClick={() => axis.drillTarget && onDrill(axis.drillTarget)}>{body}</button>
        ) : (
          <div key={axis.key} className="flex items-start gap-2 rounded-lg border border-healthcare-border bg-healthcare-surface p-3 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">{body}</div>
        );
      })}
    </section>
  );
}
