// The review-bucket vocabulary — Status-Never-Alone (color + icon + label always) with
// rationed color: coral (critical) is reserved for the CONFLICTS bucket, the one place a
// wrong commit overwrites a prior verified assignment (earned urgency). Everything else
// rides the calmer amber/sky/teal/neutral ramp.
import {
  CheckCircle2,
  GitCompareArrows,
  HelpCircle,
  ScanSearch,
  UserMinus,
  type LucideIcon,
} from 'lucide-react';
import { StatusPill, type Tone } from '@/Components/Deployment/status';
import type { Bucket } from '@/features/deployment/staffing/types';

export const BUCKET_META: Record<Bucket, { label: string; tone: Tone; icon: LucideIcon; blurb: string }> = {
  auto_approved: {
    label: 'Auto-approved',
    tone: 'success',
    icon: CheckCircle2,
    blurb: 'High-confidence deterministic matches. Commit as-is unless you reject.',
  },
  needs_review: {
    label: 'Needs review',
    tone: 'warning',
    icon: ScanSearch,
    blurb: 'Heuristic or low-confidence matches, and every regulated role. Confirm or edit before commit.',
  },
  conflicts: {
    label: 'Conflicts',
    tone: 'critical',
    icon: GitCompareArrows,
    blurb: 'The source disagrees with an existing committed assignment. Resolve before committing.',
  },
  unmatched: {
    label: 'Unmatched',
    tone: 'info',
    icon: HelpCircle,
    blurb: 'No rule or heuristic hit. Assign manually, or promote a rule and re-resolve.',
  },
  departed: {
    label: 'Departed',
    tone: 'neutral',
    icon: UserMinus,
    blurb: 'Present in Zephyrus but absent from this pull. Deactivate, or leave for the drift sweep.',
  },
};

export const BUCKET_ORDER: Bucket[] = ['conflicts', 'needs_review', 'unmatched', 'auto_approved', 'departed'];

export function BucketPill({ bucket, count }: { bucket: Bucket; count?: number }) {
  const m = BUCKET_META[bucket];
  return <StatusPill tone={m.tone} icon={m.icon} label={count === undefined ? m.label : `${m.label} · ${count}`} />;
}

// Confidence as a percent, tabular. Null-safe.
export function confidencePct(confidence: number | null | undefined): string {
  if (confidence === null || confidence === undefined) return '—';
  return `${Math.round(confidence * 100)}%`;
}
