import type { ReactNode } from 'react';
import { KpiTile, metric, Panel as SystemPanel } from '@/Components/system';

export type Tone = 'critical' | 'warning' | 'success' | 'info' | 'neutral';

const TONE_PILL: Record<Tone, string> = {
  critical: 'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical/20 dark:text-healthcare-critical-dark',
  warning: 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning/20 dark:text-healthcare-warning-dark',
  success: 'bg-healthcare-success/10 text-healthcare-success dark:bg-healthcare-success/20 dark:text-healthcare-success-dark',
  info: 'bg-healthcare-info/10 text-healthcare-info dark:bg-healthcare-info/20 dark:text-healthcare-info-dark',
  neutral: 'bg-healthcare-background text-healthcare-text-secondary dark:bg-white/5 dark:text-healthcare-text-secondary-dark',
};

export function normalizeTone(value: string | null | undefined): Tone {
  switch (value) {
    case 'critical':
      return 'critical';
    case 'warning':
    case 'high':
      return 'warning';
    case 'success':
      return 'success';
    case 'info':
    case 'medium':
    case 'low':
      return 'info';
    default:
      return 'neutral';
  }
}

export function ToneBadge({ tone, children }: { tone: Tone; children: ReactNode }) {
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs leading-none font-semibold ${TONE_PILL[tone]}`}>
      {children}
    </span>
  );
}

// Delegates to the gold-standard KpiTile so the Ops surface speaks the same
// instrument vocabulary as the rest of the app (status dot, not a colored fill).
export function MetricTile({ label, value, tone = 'neutral' }: { label: string; value: number | string; tone?: Tone }) {
  const numeric = typeof value === 'number';
  const m = metric({
    key: `ops-${label.toLowerCase().replace(/\s+/g, '-')}`,
    label,
    value: numeric ? (value as number) : 0,
    display: numeric ? undefined : String(value),
    status: tone,
  });
  return <KpiTile metric={m} />;
}

// Titled content panel → the gold-standard Surface primitive.
export function Panel({ title, icon, children }: { title: string; icon?: ReactNode; children: ReactNode }) {
  return (
    <SystemPanel className="space-y-3 p-4">
      <div className="flex items-center gap-2">
        {icon}
        <h2 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h2>
      </div>
      {children}
    </SystemPanel>
  );
}
