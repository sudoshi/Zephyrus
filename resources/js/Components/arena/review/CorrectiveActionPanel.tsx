// resources/js/Components/arena/review/CorrectiveActionPanel.tsx
//
// The corrective action for the selected barrier: the copilot's PENDING draft
// (mirrors CopilotPane's trust UI — "AI-drafted", provenance, never enacted),
// the re-measure outcome of any prior action, and the deviant cases (which the
// Study fetches but never renders). Approve/Edit/Flag are disabled until the
// backend executor lands (Phase 3); "View deviant cases" is already live.
import { useState } from 'react';
import type { RankedBarrier } from '@/features/arena/reviewSchema';
import { fmtDuration } from './format';

function Placeholder() {
  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-6 text-sm text-healthcare-text-secondary shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark">
      Select a barrier to see its corrective action.
    </div>
  );
}

interface Props {
  barrier: RankedBarrier | null;
  aiEnabled: boolean;
  canApprove: boolean;
}

export function CorrectiveActionPanel({ barrier, aiEnabled, canApprove }: Props) {
  const [showCases, setShowCases] = useState(false);

  if (!barrier) return <Placeholder />;

  const action = barrier.corrective_action;
  const draft = action?.draft;
  const priorOutcome = action?.prior_outcome ?? null;
  const hasCases = (barrier.sample_cases?.length ?? 0) > 0;
  const pendingTitle = 'Enabled once the corrective-action executor ships (Phase 3)';

  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-5 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex flex-wrap items-center gap-2">
        {draft && aiEnabled && (
          <>
            <span className="inline-flex items-center rounded-full border border-healthcare-border px-2 py-0.5 text-xs font-medium text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
              AI-drafted · human-approved
            </span>
            <span className="rounded-full bg-healthcare-warning/15 px-2 py-0.5 text-xs font-semibold text-healthcare-warning dark:bg-healthcare-warning/20 dark:text-healthcare-warning-dark">PENDING APPROVAL</span>
          </>
        )}
        {priorOutcome && (
          <span className="ml-auto text-xs font-medium text-healthcare-success dark:text-healthcare-success-dark">
            {priorOutcome.label} {fmtDuration(Math.abs(priorOutcome.moved_sec))} {priorOutcome.moved_sec < 0 ? '▼' : '▲'}
          </span>
        )}
      </div>

      {draft && aiEnabled ? (
        <>
          <h3 className="mt-2 text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{draft.title ?? draft.action_type}</h3>
          <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Targets <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{barrier.title}</span> · {barrier.subtitle}
          </p>
          <p className="mt-2 tabular-nums text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            provenance · {barrier.provenance.source} · {barrier.metric.value_label}
            {barrier.metric.delta_pct !== null ? ` · Δ ${barrier.metric.delta_pct > 0 ? '+' : ''}${Math.round(barrier.metric.delta_pct)}%` : ''} · {draft.tier} · {draft.risk}
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            <button
              type="button"
              disabled
              title={canApprove ? pendingTitle : 'Requires ops:approve'}
              className="cursor-not-allowed rounded-md bg-healthcare-primary px-3 py-1.5 text-xs font-medium text-white opacity-60 dark:bg-healthcare-primary-dark"
            >
              Approve &amp; open PDSA
            </button>
            <button type="button" disabled title={pendingTitle} className="cursor-not-allowed rounded-md border border-healthcare-border px-3 py-1.5 text-xs font-medium text-healthcare-text-secondary opacity-60 dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
              Edit draft
            </button>
            <button type="button" disabled title={pendingTitle} className="cursor-not-allowed rounded-md border border-healthcare-border px-3 py-1.5 text-xs font-medium text-healthcare-text-secondary opacity-60 dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
              Flag as barrier
            </button>
            {hasCases && (
              <button
                type="button"
                onClick={() => setShowCases((value) => !value)}
                className="rounded-md border border-healthcare-border px-3 py-1.5 text-xs font-medium text-healthcare-primary hover:bg-healthcare-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-healthcare-gold dark:border-healthcare-border-dark dark:text-healthcare-primary-dark dark:hover:bg-healthcare-hover-dark"
              >
                {showCases ? 'Hide' : 'View'} {barrier.sample_cases?.length} deviant cases
              </button>
            )}
          </div>
        </>
      ) : (
        <>
          <h3 className="mt-2 text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{barrier.title}</h3>
          <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {aiEnabled
              ? 'No copilot draft for this barrier. Flag it or open a PDSA manually once the executor ships.'
              : 'Copilot drafting is off (ARENA_AI_ENABLED). This barrier can be flagged or resolved manually.'}
          </p>
          {hasCases && (
            <button
              type="button"
              onClick={() => setShowCases((value) => !value)}
              className="mt-3 rounded-md border border-healthcare-border px-3 py-1.5 text-xs font-medium text-healthcare-primary hover:bg-healthcare-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-healthcare-gold dark:border-healthcare-border-dark dark:text-healthcare-primary-dark dark:hover:bg-healthcare-hover-dark"
            >
              {showCases ? 'Hide' : 'View'} {barrier.sample_cases?.length} deviant cases
            </button>
          )}
        </>
      )}

      {showCases && barrier.sample_cases && (
        <ul className="mt-3 space-y-1.5 border-t border-healthcare-border pt-3 dark:border-healthcare-border-dark">
          {barrier.sample_cases.map((sample) => (
            <li key={sample.case_id} className="flex items-center justify-between gap-3 text-xs">
              <span className="tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{sample.case_id}</span>
              <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{sample.deviations.join(', ')}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
