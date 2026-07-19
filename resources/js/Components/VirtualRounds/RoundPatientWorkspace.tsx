// The right-side patient workspace (plan §8.2): queue reasons, completion
// requirements, contributions with supersession history, questions, tasks,
// and the review transitions. Rendered from the authorized patient detail —
// the server has already clamped what this viewer may see.
import { useState } from 'react';
import {
  AlertTriangle,
  CheckCircle2,
  CircleSlash,
  Clock3,
  Pin,
  RotateCcw,
  Undo2,
} from 'lucide-react';
import type { PatientDetail, RoundSection } from '@/features/virtualRounds/types';
import type { PatientTransitionAction } from '@/features/virtualRounds/api';
import ContributionComposer from './ContributionComposer';
import {
  formatWindow,
  PATIENT_STATUS_CLASS,
  PATIENT_STATUS_LABEL,
  PRIORITY_BAND_LABEL,
  priorityBandClass,
} from './format';

const buttonClass =
  'inline-flex items-center gap-1.5 rounded-md border border-healthcare-border px-2.5 py-1.5 text-sm ' +
  'font-medium text-healthcare-text-primary hover:bg-healthcare-hover dark:border-healthcare-border-dark ' +
  'dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark disabled:opacity-50';

interface Props {
  detail: PatientDetail;
  sections: RoundSection[];
  roles: Record<string, string>;
  busy: boolean;
  conflictMessage: string | null;
  onTransition: (
    action: PatientTransitionAction,
    body: { reason?: string; exception_reason?: string; expected_version: number },
  ) => void;
  onPin: (pinned: boolean, reason: string) => void;
  onContribute: (input: {
    section_code: string;
    author_role: string;
    structured_data: Record<string, unknown>;
    summary?: string;
    submit: boolean;
  }) => void;
}

export default function RoundPatientWorkspace({
  detail,
  sections,
  roles,
  busy,
  conflictMessage,
  onTransition,
  onPin,
  onContribute,
}: Props) {
  const patient = detail.data;
  const [reasonPrompt, setReasonPrompt] = useState<{
    action: PatientTransitionAction | 'pin' | 'unpin';
    label: string;
    exception?: boolean;
  } | null>(null);
  const [reasonText, setReasonText] = useState('');

  const transition = (action: PatientTransitionAction, body: { reason?: string; exception_reason?: string } = {}) =>
    onTransition(action, { ...body, expected_version: patient.version });

  const confirmReason = () => {
    if (!reasonPrompt || reasonText.trim() === '') {
      return;
    }
    const reason = reasonText.trim();

    if (reasonPrompt.action === 'pin') {
      onPin(true, reason);
    } else if (reasonPrompt.action === 'unpin') {
      onPin(false, reason);
    } else if (reasonPrompt.exception) {
      transition(reasonPrompt.action, { exception_reason: reason });
    } else {
      transition(reasonPrompt.action, { reason });
    }

    setReasonPrompt(null);
    setReasonText('');
  };

  const requirements = patient.requirements;
  const hardMissing = requirements.missing.filter((m) => m.requirement === 'hard');

  return (
    <div className="space-y-3" data-testid="rounds-workspace">
      {/* Header */}
      <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex items-start justify-between gap-2">
          <div>
            <h2 className="text-base font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {patient.patient_label ?? 'Patient (restricted)'}
            </h2>
            <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Bed <span className="tabular-nums">{patient.bed ?? '—'}</span> · Window{' '}
              <span className="tabular-nums">{formatWindow(patient.eta_window_start, patient.eta_window_end)}</span>
            </p>
          </div>
          <span className="inline-flex items-center gap-2">
            {/* R-2: jump to this stop's ring in the 4D navigator. */}
            <a
              href={`/rtdc/patient-flow-navigator?focus_stop=${patient.round_patient_uuid}`}
              className="text-xs font-medium text-healthcare-primary hover:underline dark:text-healthcare-primary-dark"
              title="Locate this stop in the 4D navigator"
            >
              Locate in 4D
            </a>
            <span
              className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium ${PATIENT_STATUS_CLASS[patient.status]}`}
            >
              {PATIENT_STATUS_LABEL[patient.status]}
            </span>
          </span>
        </div>

        {conflictMessage && (
          <p
            role="alert"
            className="mt-2 rounded-md bg-healthcare-warning/15 px-2 py-1.5 text-xs text-healthcare-warning dark:bg-healthcare-warning/20 dark:text-healthcare-warning-dark"
          >
            {conflictMessage}
          </p>
        )}

        {/* Transitions */}
        <div className="mt-2 flex flex-wrap gap-1.5">
          {['in_progress', 'awaiting_input'].includes(patient.status) && (
            <button
              type="button"
              className={buttonClass}
              disabled={busy}
              onClick={() => transition('mark-ready')}
              data-testid="workspace-mark-ready"
            >
              <CheckCircle2 className="h-3.5 w-3.5" aria-hidden /> Mark ready
            </button>
          )}
          {['in_progress', 'ready_for_review'].includes(patient.status) && (
            <button
              type="button"
              className={buttonClass}
              disabled={busy}
              onClick={() =>
                requirements.satisfied
                  ? transition('complete')
                  : setReasonPrompt({ action: 'complete', label: 'Exception reason to round with missing requirements', exception: true })
              }
              data-testid="workspace-complete"
            >
              <CheckCircle2 className="h-3.5 w-3.5" aria-hidden /> Mark rounded
            </button>
          )}
          {patient.status === 'rounded' && (
            <button
              type="button"
              className={buttonClass}
              disabled={busy}
              onClick={() => setReasonPrompt({ action: 'reopen', label: 'Reason for reopening' })}
            >
              <RotateCcw className="h-3.5 w-3.5" aria-hidden /> Reopen
            </button>
          )}
          {!['rounded', 'deferred', 'skipped'].includes(patient.status) && (
            <>
              <button
                type="button"
                className={buttonClass}
                disabled={busy}
                onClick={() => setReasonPrompt({ action: 'defer', label: 'Reason for deferring' })}
              >
                <Clock3 className="h-3.5 w-3.5" aria-hidden /> Defer
              </button>
              <button
                type="button"
                className={buttonClass}
                disabled={busy}
                onClick={() => setReasonPrompt({ action: 'skip', label: 'Reason for skipping' })}
              >
                <CircleSlash className="h-3.5 w-3.5" aria-hidden /> Skip
              </button>
            </>
          )}
          {['deferred', 'skipped'].includes(patient.status) && (
            <button type="button" className={buttonClass} disabled={busy} onClick={() => transition('reopen')}>
              <Undo2 className="h-3.5 w-3.5" aria-hidden /> Requeue
            </button>
          )}
          <button
            type="button"
            className={buttonClass}
            disabled={busy}
            onClick={() =>
              patient.pinned
                ? setReasonPrompt({ action: 'unpin', label: 'Reason for unpinning' })
                : setReasonPrompt({ action: 'pin', label: 'Reason for pinning as urgent' })
            }
          >
            <Pin className="h-3.5 w-3.5" aria-hidden /> {patient.pinned ? 'Unpin' : 'Pin urgent'}
          </button>
        </div>

        {reasonPrompt && (
          <div className="mt-2 space-y-1.5">
            <label className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {reasonPrompt.label} (required)
              <input
                autoFocus
                className="mt-0.5 w-full rounded-md border border-healthcare-border bg-healthcare-surface px-2 py-1.5 text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
                value={reasonText}
                onChange={(e) => setReasonText(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && confirmReason()}
                data-testid="workspace-reason-input"
              />
            </label>
            <div className="flex gap-1.5">
              <button
                type="button"
                className={buttonClass}
                disabled={reasonText.trim() === ''}
                onClick={confirmReason}
                data-testid="workspace-reason-confirm"
              >
                Confirm
              </button>
              <button
                type="button"
                className={buttonClass}
                onClick={() => {
                  setReasonPrompt(null);
                  setReasonText('');
                }}
              >
                Cancel
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Priority reasons — every ranking is explainable */}
      <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h3 className="mb-1.5 text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Why this position
        </h3>
        <ul className="space-y-1">
          {patient.priority_reasons.map((reason) => (
            <li key={reason.code} className="flex items-start gap-2 text-xs">
              <span className={`font-medium ${priorityBandClass(reason.band)}`}>
                {PRIORITY_BAND_LABEL[reason.band] ?? `Band ${reason.band}`}
              </span>
              <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {reason.explanation}
              </span>
            </li>
          ))}
        </ul>
      </div>

      {/* Requirements */}
      <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h3 className="mb-1.5 text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Completion requirements
        </h3>
        {requirements.satisfied ? (
          <p className="inline-flex items-center gap-1.5 text-xs text-healthcare-success dark:text-healthcare-success-dark">
            <CheckCircle2 className="h-3.5 w-3.5" aria-hidden /> All required inputs are in.
          </p>
        ) : (
          <ul className="space-y-1">
            {requirements.missing.map((m) => (
              <li
                key={`${m.role}-${m.section}`}
                className="flex items-center gap-1.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
              >
                {m.requirement === 'hard' ? (
                  <AlertTriangle className="h-3.5 w-3.5 text-healthcare-warning dark:text-healthcare-warning-dark" aria-hidden />
                ) : (
                  <Clock3 className="h-3.5 w-3.5" aria-hidden />
                )}
                <span>
                  {roles[m.role] ?? m.role}: {m.section.replaceAll('_', ' ')}{' '}
                  <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    ({m.requirement})
                  </span>
                </span>
              </li>
            ))}
            {hardMissing.length === 0 && (
              <li className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                Only soft requirements remain.
              </li>
            )}
          </ul>
        )}
        {requirements.stale.length > 0 && (
          <p className="mt-1.5 text-xs text-healthcare-warning dark:text-healthcare-warning-dark">
            Stale input: {requirements.stale.map((s) => `${roles[s.role] ?? s.role} (${s.section})`).join(', ')}
          </p>
        )}
      </div>

      {/* Contributions */}
      <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <h3 className="mb-1.5 text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Contributions
        </h3>
        {patient.contributions.length === 0 ? (
          <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            None yet.
          </p>
        ) : (
          <ul className="space-y-2">
            {patient.contributions.map((c) => (
              <li key={c.contribution_uuid} className="text-xs">
                <div className="flex items-center gap-2">
                  <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {c.section_code.replaceAll('_', ' ')}
                  </span>
                  <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {roles[c.author_role] ?? c.author_role}
                  </span>
                  <span
                    className={
                      c.status === 'submitted'
                        ? 'text-healthcare-success dark:text-healthcare-success-dark'
                        : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                    }
                  >
                    {c.status}
                    {c.status === 'superseded' && ' (history)'}
                  </span>
                  {c.submitted_at && (
                    <span className="tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {new Date(c.submitted_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                    </span>
                  )}
                </div>
                {c.summary && (
                  <p className="mt-0.5 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {c.summary}
                  </p>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>

      <ContributionComposer sections={sections} roles={roles} busy={busy} onSubmit={onContribute} />
    </div>
  );
}
