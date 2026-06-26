import type { ReactNode } from 'react';

export type Tone = 'critical' | 'warning' | 'success' | 'info' | 'neutral';

const TONE_PILL: Record<Tone, string> = {
  critical: 'bg-red-100 text-red-700 dark:bg-red-950/30 dark:text-red-300',
  warning: 'bg-amber-100 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300',
  success: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300',
  info: 'bg-sky-100 text-sky-700 dark:bg-sky-950/30 dark:text-sky-300',
  neutral: 'bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300',
};

const TONE_CARD: Record<Tone, string> = {
  critical: 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/20',
  warning: 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/20',
  success: 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/20',
  info: 'border-sky-200 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/20',
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
    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]/[15px] font-semibold ${TONE_PILL[tone]}`}>
      {children}
    </span>
  );
}

export function MetricTile({ label, value, tone = 'neutral' }: { label: string; value: number | string; tone?: Tone }) {
  return (
    <div className={`rounded-md border p-4 ${TONE_CARD[tone]}`}>
      <div className="text-[12px]/[16px] font-medium uppercase tracking-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        {label}
      </div>
      <div className="mt-1 text-[24px]/[28px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
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
        <h2 className="text-[16px]/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h2>
      </div>
      {children}
    </section>
  );
}
