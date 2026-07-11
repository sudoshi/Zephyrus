// resources/js/Components/arena/review/format.ts
//
// Shared formatting + status-token maps for the Flow Review. Severity is the
// ONLY channel that carries status colour (earned urgency); kind is conveyed by
// a neutral outline chip + glyph, never a hue (CLAUDE.md two-system canon).
import type { BarrierSeverity } from '@/features/arena/reviewSchema';

// Humanize a duration in seconds — matches PerformancePane's fmtDuration so the
// Review and the Study read the same.
export function fmtDuration(seconds: number): string {
  if (seconds < 90) return `${Math.round(seconds)}s`;
  if (seconds < 5400) return `${Math.round(seconds / 60)}m`;
  return `${(seconds / 3600).toFixed(1)}h`;
}

// Signed delta as a compact, screen-reader-friendly string.
export function fmtDeltaPct(pct: number): string {
  const rounded = Math.round(pct);
  return `${rounded > 0 ? '+' : ''}${rounded}%`;
}

export const SEVERITY_STRIPE: Record<BarrierSeverity, string> = {
  critical: 'bg-healthcare-critical dark:bg-healthcare-critical-dark',
  warning: 'bg-healthcare-warning dark:bg-healthcare-warning-dark',
  watch: 'bg-healthcare-info dark:bg-healthcare-info-dark',
};

export const SEVERITY_TEXT: Record<BarrierSeverity, string> = {
  critical: 'text-healthcare-critical dark:text-healthcare-critical-dark',
  warning: 'text-healthcare-warning dark:text-healthcare-warning-dark',
  watch: 'text-healthcare-info dark:text-healthcare-info-dark',
};

// Rank order so the rail and the summary agree on "worst first".
export const SEVERITY_RANK: Record<BarrierSeverity, number> = {
  critical: 0,
  warning: 1,
  watch: 2,
};

export const KIND_LABEL: Record<'flow' | 'care' | 'human', string> = {
  flow: 'FLOW',
  care: 'CARE',
  human: 'HUMAN',
};
