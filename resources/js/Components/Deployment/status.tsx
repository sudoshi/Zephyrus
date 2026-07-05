// Deployment Console status vocabulary — the single place the "Status-Never-Alone"
// rule is enforced: every pill pairs a rationed status color with an icon AND a
// worded label, so meaning survives color-blindness and a grayscale wall display.
//
// Dimension discipline (Earned-Red / Two-System rules):
//   - readiness pass/fail/warn -> the four-color status vocabulary (teal/amber/coral/sky)
//   - review/trust status       -> status vocabulary (verification is a real signal)
//   - capability LEVEL          -> a monochrome BLUE ramp, not status color (it is
//                                  domain/info depth, not an alarm — keeps green/coral scarce)
import {
  AlertTriangle,
  CheckCircle2,
  CircleDashed,
  HelpCircle,
  Info,
  MinusCircle,
  ShieldCheck,
  XCircle,
  type LucideIcon,
} from 'lucide-react';
import type { ReadinessStatus, ReviewStatus } from '@/features/deployment/types';

export type Tone = 'success' | 'warning' | 'critical' | 'info' | 'neutral';

const TONE_CLASS: Record<Tone, string> = {
  success:
    'bg-healthcare-success/10 text-healthcare-success dark:bg-healthcare-success-dark/20 dark:text-healthcare-success-dark',
  warning:
    'bg-healthcare-warning/10 text-healthcare-warning dark:bg-healthcare-warning-dark/20 dark:text-healthcare-warning-dark',
  critical:
    'bg-healthcare-critical/10 text-healthcare-critical dark:bg-healthcare-critical-dark/20 dark:text-healthcare-critical-dark',
  info: 'bg-healthcare-info/10 text-healthcare-info dark:bg-healthcare-info-dark/20 dark:text-healthcare-info-dark',
  neutral:
    'bg-healthcare-background text-healthcare-text-secondary dark:bg-white/5 dark:text-healthcare-text-secondary-dark',
};

export function StatusPill({
  tone,
  icon: IconCmp,
  label,
  className = '',
}: {
  tone: Tone;
  icon: LucideIcon;
  label: string;
  className?: string;
}) {
  return (
    <span
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${TONE_CLASS[tone]} ${className}`}
    >
      <IconCmp className="size-3 shrink-0" aria-hidden="true" />
      {label}
    </span>
  );
}

const READINESS_META: Record<ReadinessStatus, { tone: Tone; icon: LucideIcon; label: string }> = {
  pass: { tone: 'success', icon: CheckCircle2, label: 'Pass' },
  fail: { tone: 'critical', icon: XCircle, label: 'Blocking' },
  warn: { tone: 'warning', icon: AlertTriangle, label: 'Review' },
  info: { tone: 'info', icon: Info, label: 'Info' },
  not_applicable: { tone: 'neutral', icon: MinusCircle, label: 'N/A' },
};

export function ReadinessPill({ status }: { status: ReadinessStatus }) {
  const m = READINESS_META[status];
  return <StatusPill tone={m.tone} icon={m.icon} label={m.label} />;
}

const REVIEW_META: Record<string, { tone: Tone; icon: LucideIcon; label: string }> = {
  client_verified: { tone: 'success', icon: ShieldCheck, label: 'Client-verified' },
  source_verified: { tone: 'info', icon: CheckCircle2, label: 'Source-verified' },
  assumed: { tone: 'warning', icon: CircleDashed, label: 'Assumed' },
  unknown: { tone: 'neutral', icon: HelpCircle, label: 'Unknown' },
};

export function ReviewPill({ status }: { status: ReviewStatus | string | null }) {
  const m = REVIEW_META[status ?? 'unknown'] ?? REVIEW_META.unknown;
  return <StatusPill tone={m.tone} icon={m.icon} label={m.label} />;
}

// Monochrome blue ramp for capability LEVEL — intensity by rank, never a status hue.
// The text label is always present, so the ramp is redundant, not load-bearing.
export function capabilityLevelClass(rank: number | null): string {
  const base = 'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium tabular-nums ';
  if (rank === null || rank <= 0) {
    return base + 'bg-healthcare-background text-healthcare-text-secondary dark:bg-white/5 dark:text-healthcare-text-secondary-dark';
  }
  const ramp = ['', '/10', '/10', '/20', '/30', '/40', '/50'][Math.min(rank, 6)];
  return base + `bg-healthcare-primary${ramp} text-healthcare-primary dark:text-healthcare-primary-dark`;
}
