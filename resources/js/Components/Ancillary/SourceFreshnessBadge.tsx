import { AlertTriangle, Clock3, Database, HelpCircle } from 'lucide-react';
import type { SourceFreshnessContract } from './schemas';

const STATUS = {
  fresh: { label: 'Fresh', icon: Clock3, className: 'border-healthcare-success/40 text-healthcare-success dark:text-healthcare-success-dark' },
  stale: { label: 'Stale', icon: AlertTriangle, className: 'border-healthcare-warning/40 text-healthcare-warning dark:text-healthcare-warning-dark' },
  batch: { label: 'Warehouse as-of', icon: Database, className: 'border-healthcare-info/40 text-healthcare-info dark:text-healthcare-info-dark' },
  unknown: { label: 'Freshness unknown', icon: HelpCircle, className: 'border-healthcare-border text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark' },
} as const;

export function SourceFreshnessBadge({ value }: { value: SourceFreshnessContract }) {
  const style = STATUS[value.status];
  const Icon = style.icon;
  const cutoff = value.sourceCutoffAt ? new Date(value.sourceCutoffAt).toLocaleString() : 'cutoff unavailable';

  return (
    <span role="status" title={`${value.sourceLabel}: ${cutoff}${value.explanation ? ` — ${value.explanation}` : ''}`} className={`inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs ${style.className}`}>
      <Icon className="size-3.5" aria-hidden="true" />
      <span>{style.label}</span>
      <span className="tabular-nums">· {value.lagMinutes === null ? 'lag unavailable' : `${value.lagMinutes} min lag`}</span>
    </span>
  );
}
