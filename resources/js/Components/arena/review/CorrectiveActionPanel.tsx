// resources/js/Components/arena/review/CorrectiveActionPanel.tsx
//
// The corrective action for the selected barrier: the copilot's PENDING draft
// (mirrors CopilotPane's trust UI — "AI-drafted", provenance, never enacted),
// the re-measure outcome of any prior action, and the deviant cases (which the
// Study fetches but never renders). With the P3 executor shipped, Approve now
// posts the governed decision (materializing the PDSA on approval) and Draft
// raises a governed correction for flow/care barriers; both invalidate the
// review so the next Run reflects the loop advancing. "View deviant cases" is
// already live; Edit/Flag remain manual follow-ups.
import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { postArenaApproveAction, postArenaDraftCorrection, postArenaDraftPdsa } from '@/features/arena/api';
import type { RankedBarrier } from '@/features/arena/reviewSchema';
import { fmtDuration } from './format';

function Placeholder() {
  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-6 text-sm text-healthcare-text-secondary shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-secondary-dark">
      Select a barrier to see its corrective action.
    </div>
  );
}

// Which pathway a care barrier maps to for a governed correction (its id is
// `care-<pathway>`); null for the pathways the copilot cannot draft.
function draftPathway(barrier: RankedBarrier): 'sepsis' | 'surgical_safety' | null {
  if (barrier.kind !== 'care') return null;
  const pathway = barrier.id.replace(/^care-/, '');
  return pathway === 'sepsis' || pathway === 'surgical_safety' ? pathway : null;
}

// A flow (sync-wait) barrier drafts a PDSA on the bottleneck; a mappable care
// barrier drafts a pathway correction. Human barriers are managed manually.
function canDraft(barrier: RankedBarrier): boolean {
  return barrier.kind === 'flow' || draftPathway(barrier) !== null;
}

const PRIMARY_BUTTON =
  'rounded-md bg-healthcare-primary px-3 py-1.5 text-xs font-medium text-white transition hover:bg-healthcare-primary/90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-healthcare-gold disabled:cursor-not-allowed disabled:opacity-60 dark:bg-healthcare-primary-dark';
const SECONDARY_BUTTON =
  'rounded-md border border-healthcare-border px-3 py-1.5 text-xs font-medium text-healthcare-primary hover:bg-healthcare-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-healthcare-gold dark:border-healthcare-border-dark dark:text-healthcare-primary-dark dark:hover:bg-healthcare-hover-dark';

interface Props {
  barrier: RankedBarrier | null;
  aiEnabled: boolean;
  canApprove: boolean;
}

export function CorrectiveActionPanel({ barrier, aiEnabled, canApprove }: Props) {
  const [showCases, setShowCases] = useState(false);
  const queryClient = useQueryClient();

  const invalidateReview = () => queryClient.invalidateQueries({ queryKey: ['arena', 'review'] });

  const approveMutation = useMutation({
    mutationFn: (approvalId: number) => postArenaApproveAction(approvalId, 'approved'),
    onSuccess: invalidateReview,
  });

  const draftMutation = useMutation({
    mutationFn: (target: RankedBarrier) => {
      const pathway = draftPathway(target);
      return pathway
        ? postArenaDraftCorrection(pathway, { target_ref: target.id })
        : postArenaDraftPdsa('bottleneck', { target_ref: target.id });
    },
    onSuccess: invalidateReview,
  });

  if (!barrier) return <Placeholder />;

  const action = barrier.corrective_action;
  const draft = action?.draft;
  const priorOutcome = action?.prior_outcome ?? null;
  const hasCases = (barrier.sample_cases?.length ?? 0) > 0;
  const approvalId = draft?.approval_id;

  const casesButton = hasCases && (
    <button type="button" onClick={() => setShowCases((value) => !value)} className={SECONDARY_BUTTON}>
      {showCases ? 'Hide' : 'View'} {barrier.sample_cases?.length} deviant cases
    </button>
  );

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
              disabled={!canApprove || approvalId === undefined || approveMutation.isPending || approveMutation.isSuccess}
              onClick={() => approvalId !== undefined && approveMutation.mutate(approvalId)}
              title={canApprove ? 'Approve — materializes the PDSA cycle' : 'Requires ops:approve'}
              className={PRIMARY_BUTTON}
            >
              {approveMutation.isSuccess ? 'Approved ✓' : approveMutation.isPending ? 'Approving…' : 'Approve & open PDSA'}
            </button>
            <button type="button" disabled title="Editing a draft is a manual follow-up" className={`${SECONDARY_BUTTON} disabled:cursor-not-allowed disabled:opacity-60`}>
              Edit draft
            </button>
            <button type="button" disabled title="Manual barrier flagging is a follow-up" className={`${SECONDARY_BUTTON} disabled:cursor-not-allowed disabled:opacity-60`}>
              Flag as barrier
            </button>
            {casesButton}
          </div>
          {approveMutation.isError && (
            <p className="mt-2 text-xs text-healthcare-critical dark:text-healthcare-critical-dark">Approval failed — try again or check ops:approve.</p>
          )}
        </>
      ) : (
        <>
          <h3 className="mt-2 text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{barrier.title}</h3>
          <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {aiEnabled
              ? canDraft(barrier)
                ? 'No copilot draft yet for this barrier. Draft a governed correction for approval, or view its deviant cases.'
                : 'No copilot draft for this barrier. Human barriers are resolved operationally, not by a drafted PDSA.'
              : 'Copilot drafting is off (ARENA_AI_ENABLED). This barrier can be flagged or resolved manually.'}
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            {aiEnabled && canApprove && canDraft(barrier) && (
              <button
                type="button"
                disabled={draftMutation.isPending || draftMutation.isSuccess}
                onClick={() => draftMutation.mutate(barrier)}
                title="Raise a governed corrective-action draft for this barrier"
                className={PRIMARY_BUTTON}
              >
                {draftMutation.isSuccess ? 'Drafted ✓ — pending approval' : draftMutation.isPending ? 'Drafting…' : 'Draft corrective action'}
              </button>
            )}
            {casesButton}
          </div>
          {draftMutation.isError && (
            <p className="mt-2 text-xs text-healthcare-critical dark:text-healthcare-critical-dark">Drafting failed — confirm the copilot is enabled.</p>
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
