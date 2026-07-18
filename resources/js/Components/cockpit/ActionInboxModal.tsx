// resources/js/Components/cockpit/ActionInboxModal.tsx
//
// P6 WS-5 — the AgentInbox silo page reconciled into the cockpit: an
// alert/action modal over the SAME OperationalActionLifecycleService queue
// (useAgentInbox + useDecideApproval). The standalone /ops/agent-inbox page
// remains as a deep-link; this is the in-cockpit path so the loop
// alert → proposal → approval never leaves the surface. Severity encodes
// through the WS-6 riskStatus mapping — identical shape+color everywhere.
import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link } from '@inertiajs/react';
import { Surface } from '@/Components/ui/Surface';
import { useAgentInbox, useDecideApproval } from '@/features/ops/hooks';
import type { AgentInboxApprovalItem } from '@/features/ops/types';
import { cockpitStatusStyle } from './statusStyle';
import { statusForRisk } from './riskStatus';

/**
 * HFE Phase 1 — the review-before-approve evidence sheet. Approve is only
 * reachable through this expansion: scope, rationale, confidence, expected
 * effect, provenance, and timing all visible before the decision.
 */
function ApprovalEvidence({ approval }: { approval: AgentInboxApprovalItem }) {
  const rec = approval.action?.recommendation ?? null;
  const impact = Object.entries(rec?.expectedImpact ?? {});

  const rows: Array<[string, string]> = [
    ['Scope', rec?.scopeKey ? `${rec.scopeType ?? 'scope'} · ${rec.scopeKey}` : rec?.scopeType ?? '—'],
    ['Action type', approval.action?.type ?? '—'],
    ['Rationale', rec?.rationale ?? '—'],
    ['Confidence', rec?.confidence !== null && rec?.confidence !== undefined ? `${Math.round(rec.confidence * 100)}%` : '—'],
    ['Source', rec?.source ?? '—'],
    ['Owner', approval.action?.ownerName ?? 'unassigned'],
    ['Requested', approval.requestedAtIso ? new Date(approval.requestedAtIso).toLocaleString() : '—'],
    ['Due', approval.action?.dueAtIso ? new Date(approval.action.dueAtIso).toLocaleString() : '—'],
    ['Expires', approval.action?.expiresAtIso ? new Date(approval.action.expiresAtIso).toLocaleString() : '—'],
  ];

  return (
    <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 border-t border-healthcare-border pt-2 text-xs dark:border-healthcare-border-dark">
      {rows.map(([label, value]) => (
        <div key={label} className="contents">
          <dt className="font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</dt>
          <dd className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</dd>
        </div>
      ))}
      {impact.length > 0 && (
        <div className="contents">
          <dt className="font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Expected effect</dt>
          <dd className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {impact.map(([key, value]) => `${key.replaceAll('_', ' ')}: ${String(value)}`).join(' · ')}
          </dd>
        </div>
      )}
    </dl>
  );
}

function RiskGlyph({ risk }: { risk: string | null | undefined }) {
  const s = cockpitStatusStyle(statusForRisk(risk));

  return (
    <span className="inline-flex items-center gap-1 text-xs" style={{ color: s.color }}>
      <span role="img" aria-label={s.label}>{s.glyph}</span>
      {risk ?? 'pending'}
    </span>
  );
}

export function ActionInboxModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const inbox = useAgentInbox();
  const decide = useDecideApproval();
  // Review-step state: Approve/Reject live ONLY inside the expanded evidence
  // sheet — there is no one-click inline decision (HFE audit A11Y-01/§4.3).
  const [reviewingId, setReviewingId] = useState<number | null>(null);

  useEffect(() => {
    if (!open) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;

  const approvals = inbox.data?.approvals ?? [];
  const actions = inbox.data?.actions ?? [];

  return createPortal(
    <div
      className="fixed inset-0 z-[70] flex items-start justify-center overflow-y-auto p-4 pt-[8vh]"
      role="dialog"
      aria-modal="true"
      aria-label="Action inbox"
      data-testid="cockpit-action-inbox"
    >
      <div className="absolute inset-0 bg-black/30" onClick={onClose} aria-hidden="true" />
      <Surface className="relative w-full max-w-2xl p-4 shadow-lg">
        <div className="flex items-center justify-between gap-2">
          <h2 className="text-base font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Action inbox
          </h2>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close action inbox"
            className="rounded-md p-1.5 text-healthcare-text-secondary hover:bg-healthcare-surface-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-surface-hover-dark"
          >
            <span aria-hidden="true">✕</span>
          </button>
        </div>

        <h3 className="mt-3 text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Pending approvals
        </h3>
        {inbox.isLoading ? (
          <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Loading…</p>
        ) : inbox.isError ? (
          // Unknown is not zero — a failed fetch must never read as an empty queue.
          <p role="alert" className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Approvals unavailable.{' '}
            <button type="button" onClick={() => inbox.refetch()} className="font-medium text-healthcare-primary dark:text-healthcare-primary-dark">
              Retry
            </button>
          </p>
        ) : approvals.length === 0 ? (
          <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            No pending approvals.
          </p>
        ) : (
          <ul className="mt-2 space-y-2">
            {approvals.map((approval) => {
              const approvalSubject = approval.action?.recommendation?.title ?? approval.action?.type ?? 'action';
              const reviewing = reviewingId === approval.approvalId;

              return (
                <li
                  key={approval.approvalId}
                  className="rounded-md border border-healthcare-border p-2.5 dark:border-healthcare-border-dark"
                >
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="min-w-0">
                      <p className="truncate text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {approval.action?.recommendation?.title ?? approval.action?.type ?? 'Action'}
                      </p>
                      <RiskGlyph risk={approval.action?.recommendation?.riskLevel} />
                    </div>
                    <button
                      type="button"
                      onClick={() => setReviewingId(reviewing ? null : approval.approvalId)}
                      aria-expanded={reviewing}
                      aria-label={`Review: ${approvalSubject}`}
                      className="shrink-0 rounded-md border border-healthcare-border px-2.5 py-1 text-xs font-medium text-healthcare-text-primary transition-colors duration-200 hover:bg-healthcare-surface-hover dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-surface-hover-dark"
                    >
                      {reviewing ? 'Close review' : 'Review'}
                    </button>
                  </div>

                  {reviewing && (
                    <>
                      <ApprovalEvidence approval={approval} />
                      <div className="mt-2.5 flex items-center gap-2">
                        <button
                          type="button"
                          disabled={decide.isPending}
                          onClick={() => {
                            decide.mutate({ approvalId: approval.approvalId, decision: 'approved' });
                            setReviewingId(null);
                          }}
                          aria-label={`Approve: ${approvalSubject}`}
                          className="rounded-md bg-healthcare-primary px-2.5 py-1 text-xs font-medium text-white transition-colors duration-200 hover:bg-healthcare-primary-hover disabled:opacity-50 dark:bg-healthcare-primary-dark"
                        >
                          Approve
                        </button>
                        <button
                          type="button"
                          disabled={decide.isPending}
                          onClick={() => {
                            decide.mutate({ approvalId: approval.approvalId, decision: 'rejected' });
                            setReviewingId(null);
                          }}
                          aria-label={`Reject: ${approvalSubject}`}
                          className="rounded-md border border-healthcare-border px-2.5 py-1 text-xs font-medium text-healthcare-text-secondary transition-colors duration-200 hover:bg-healthcare-surface-hover disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-surface-hover-dark"
                        >
                          Reject
                        </button>
                      </div>
                    </>
                  )}
                </li>
              );
            })}
          </ul>
        )}

        <h3 className="mt-4 text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Active actions
        </h3>
        {actions.length === 0 ? (
          <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            No active actions.
          </p>
        ) : (
          <ul className="mt-2 space-y-1.5">
            {actions.map((action) => (
              <li
                key={action.actionId}
                className="flex items-center justify-between gap-2 rounded-md border border-healthcare-border px-2.5 py-1.5 dark:border-healthcare-border-dark"
              >
                <span className="truncate text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {action.recommendation?.title ?? action.type}
                </span>
                <span className="shrink-0 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {action.isOverdue ? 'overdue · ' : ''}{action.status}
                </span>
              </li>
            ))}
          </ul>
        )}

        <div className="mt-4 border-t border-healthcare-border pt-3 dark:border-healthcare-border-dark">
          <Link
            href="/ops/agent-inbox"
            className="text-sm font-medium text-healthcare-primary transition-colors duration-200 hover:text-healthcare-primary-hover dark:text-healthcare-primary-dark"
          >
            Open the full agent inbox →
          </Link>
        </div>
      </Surface>
    </div>,
    document.body,
  );
}
