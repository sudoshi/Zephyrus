import { useState } from 'react';
import { proposeEddyAction } from '@/features/eddy/api';
import { cockpitStatusStyle } from '@/Components/cockpit/statusStyle';
import { statusForRisk } from '@/Components/cockpit/riskStatus';
import { useEddyStore, type EddyChatMessage } from '@/stores/eddyStore';

interface EddyApprovalCardProps {
  message: EddyChatMessage;
  surface?: string;
}

/**
 * The literal "advice, not autopilot" surface. Eddy proposed a DRAFT action; a
 * human approves or dismisses. Approve creates the governance records and (because
 * the human can) approves them through the existing ops lifecycle. Eddy never executes.
 */
export function EddyApprovalCard({ message, surface }: EddyApprovalCardProps) {
  const action = message.proposedAction;
  const state = message.proposalState ?? 'pending';
  const setProposalState = useEddyStore((s) => s.setProposalState);
  // P6: when the dock was opened from a cockpit alert, the approved proposal
  // records which alert spawned it (Recommendation evidence.alert_key).
  const alertKey = useEddyStore((s) => s.alertKey);
  const [busy, setBusy] = useState(false);

  if (!action) return null;

  const approve = async () => {
    if (busy) return;
    setBusy(true);
    try {
      await proposeEddyAction({
        action_type: action.action_type,
        title: action.title,
        surface,
        params: action.params,
        rationale: action.rationale,
        runner_up: action.runner_up,
        alert_key: alertKey ?? undefined,
        approve: true,
      });
      setProposalState(message.id, 'approved');
    } catch {
      // leave pending so the operator can retry
    } finally {
      setBusy(false);
    }
  };

  // WS-6: severity encodes through the SAME shape+color vocabulary as the
  // AlertTicker (riskStatus → cockpitStatusStyle) — one mapping, two surfaces.
  const severity = cockpitStatusStyle(statusForRisk(action.risk));

  return (
    <div className="mt-2 rounded-lg border border-healthcare-border bg-healthcare-surface p-3 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex items-center justify-between gap-2">
        <span
          className="inline-flex items-center gap-1 rounded border border-healthcare-border px-1.5 py-0.5 text-xs dark:border-healthcare-border-dark"
          style={{ color: severity.color }}
        >
          <span role="img" aria-label={severity.label}>{severity.glyph}</span>
          {action.tier} · {action.risk}
        </span>
        <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Proposed action</span>
      </div>

      <p className="mt-1.5 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{action.title}</p>

      {Object.keys(action.params).length > 0 && (
        <dl className="mt-1.5 grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5 text-xs">
          {Object.entries(action.params).map(([key, value]) => (
            <div key={key} className="contents">
              <dt className="text-healthcare-text-secondary tabular-nums dark:text-healthcare-text-secondary-dark">{key}</dt>
              <dd className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{String(value)}</dd>
            </div>
          ))}
        </dl>
      )}

      {action.rationale && (
        <p className="mt-1.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{action.rationale}</p>
      )}
      {action.runner_up && (
        <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          <span className="font-medium">Runner-up:</span> {action.runner_up}
        </p>
      )}

      <div className="mt-2.5">
        {state === 'pending' && (
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={approve}
              disabled={busy}
              className="rounded-md bg-healthcare-primary px-3 py-1.5 text-xs font-medium text-white hover:bg-healthcare-primary-hover disabled:opacity-50 dark:bg-healthcare-primary-dark"
            >
              {busy ? 'Approving…' : 'Approve'}
            </button>
            <button
              type="button"
              onClick={() => setProposalState(message.id, 'denied')}
              disabled={busy}
              className="rounded-md border border-healthcare-border px-3 py-1.5 text-xs font-medium text-healthcare-text-secondary hover:bg-healthcare-surface-hover disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-surface-hover-dark"
            >
              Dismiss
            </button>
          </div>
        )}
        {state === 'approved' && (
          <p className="inline-flex items-center gap-1 text-xs text-healthcare-success dark:text-healthcare-success-dark">
            <span aria-hidden="true">✓</span> Approved — sent to the operations inbox.
          </p>
        )}
        {state === 'denied' && (
          <p className="inline-flex items-center gap-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <span aria-hidden="true">○</span> Dismissed.
          </p>
        )}
      </div>
    </div>
  );
}
