import type { ReactNode } from 'react';

export type Tone = 'critical' | 'warning' | 'success' | 'info' | 'neutral';

const TONE_PILL: Record<Tone, string> = {
  critical: 'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical/20 dark:text-healthcare-critical-dark',
  warning: 'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning/20 dark:text-healthcare-warning-dark',
  success: 'bg-healthcare-success/10 text-healthcare-success dark:bg-healthcare-success/20 dark:text-healthcare-success-dark',
  info: 'bg-healthcare-info/10 text-healthcare-info dark:bg-healthcare-info/20 dark:text-healthcare-info-dark',
  neutral: 'bg-healthcare-background text-healthcare-text-secondary dark:bg-white/5 dark:text-healthcare-text-secondary-dark',
};

const TONE_CARD: Record<Tone, string> = {
  critical: 'border-healthcare-critical/30 bg-healthcare-critical/10 dark:border-healthcare-critical/40 dark:bg-healthcare-critical/20',
  warning: 'border-healthcare-warning/30 bg-healthcare-warning/10 dark:border-healthcare-warning/40 dark:bg-healthcare-warning/20',
  success: 'border-healthcare-success/30 bg-healthcare-success/10 dark:border-healthcare-success/40 dark:bg-healthcare-success/20',
  info: 'border-healthcare-info/30 bg-healthcare-info/10 dark:border-healthcare-info/40 dark:bg-healthcare-info/20',
  neutral: 'border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark',
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
    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs/[15px] font-semibold ${TONE_PILL[tone]}`}>
      {children}
    </span>
  );
}

export function MetricTile({ label, value, tone = 'neutral' }: { label: string; value: number | string; tone?: Tone }) {
  return (
    <div className={`rounded-md border p-4 ${TONE_CARD[tone]}`}>
      <div className="text-xs/[16px] font-medium uppercase tracking-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        {label}
      </div>
      <div className="mt-1 text-2xl/[28px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {value}
      </div>
    </div>
  );
}

export function Panel({ title, icon, children }: { title: string; icon?: ReactNode; children: ReactNode }) {
  return (
    <section className="space-y-3 rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex items-center gap-2">
        {icon}
        <h2 className="text-lg/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h2>
      </div>
      {children}
    </section>
  );
}
