// Scope + template + run selection and the run lifecycle controls.
// Dense and quiet: selects and small buttons, no hero treatment.
import { CalendarClock, CirclePause, CirclePlay, CircleStop, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { RoundScope, RoundTemplate, RunSummary } from '@/features/virtualRounds/types';
import { formatClock, RUN_STATUS_LABEL } from './format';

const selectClass =
  'rounded-md border border-healthcare-border bg-healthcare-surface px-2 py-1.5 text-sm ' +
  'text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark ' +
  'dark:text-healthcare-text-primary-dark';

const buttonClass =
  'inline-flex items-center gap-1.5 rounded-md border border-healthcare-border px-2.5 py-1.5 text-sm ' +
  'font-medium text-healthcare-text-primary hover:bg-healthcare-hover dark:border-healthcare-border-dark ' +
  'dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark disabled:opacity-50';

const primaryButtonClass =
  'inline-flex items-center gap-1.5 rounded-md bg-healthcare-primary px-2.5 py-1.5 text-sm font-medium ' +
  'text-white hover:bg-healthcare-primary-hover dark:bg-healthcare-primary-dark disabled:opacity-50';

interface Props {
  scopes: RoundScope[];
  templates: RoundTemplate[];
  runs: RunSummary[];
  selectedScopeKey: string | null;
  selectedTemplateUuid: string | null;
  selectedRun: RunSummary | null;
  busy: boolean;
  onScopeChange: (scopeKey: string) => void;
  onTemplateChange: (templateUuid: string) => void;
  onRunChange: (runUuid: string) => void;
  onCreateRun: () => void;
  onLifecycle: (action: 'start' | 'pause' | 'resume' | 'complete' | 'cancel') => void;
}

export default function RoundsCommandBar({
  scopes,
  templates,
  runs,
  selectedScopeKey,
  selectedTemplateUuid,
  selectedRun,
  busy,
  onScopeChange,
  onTemplateChange,
  onRunChange,
  onCreateRun,
  onLifecycle,
}: Props) {
  const [confirmingComplete, setConfirmingComplete] = useState(false);
  const [showCancelled, setShowCancelled] = useState(false);
  const status = selectedRun?.status ?? null;

  // Cancelled runs are archive, not workflow: demo/seed cycles accumulate dozens
  // of near-identical cancelled runs that bury the live one (HFE audit VR-01).
  // They stay reachable behind the toggle; the selected run always stays listed
  // so the select never loses its value.
  const cancelledCount = useMemo(() => runs.filter((run) => run.status === 'cancelled').length, [runs]);
  const visibleRuns = useMemo(
    () =>
      showCancelled
        ? runs
        : runs.filter((run) => run.status !== 'cancelled' || run.run_uuid === selectedRun?.run_uuid),
    [runs, showCancelled, selectedRun],
  );

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <label className="flex items-center gap-1.5 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Unit
        <select
          className={selectClass}
          value={selectedScopeKey ?? ''}
          onChange={(e) => onScopeChange(e.target.value)}
          data-testid="rounds-scope-select"
        >
          <option value="" disabled>
            Select unit
          </option>
          {scopes.map((scope) => (
            <option key={scope.scope_key} value={scope.scope_key}>
              {scope.label}
            </option>
          ))}
        </select>
      </label>

      <label className="flex items-center gap-1.5 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Template
        <select
          className={selectClass}
          value={selectedTemplateUuid ?? ''}
          onChange={(e) => onTemplateChange(e.target.value)}
          data-testid="rounds-template-select"
        >
          <option value="" disabled>
            Select template
          </option>
          {templates.map((t) => (
            <option key={t.template_uuid} value={t.template_uuid}>
              {t.name}
            </option>
          ))}
        </select>
      </label>

      <button
        type="button"
        className={primaryButtonClass}
        onClick={onCreateRun}
        disabled={busy || !selectedScopeKey || !selectedTemplateUuid}
        data-testid="rounds-create-run"
      >
        <Plus className="h-4 w-4" aria-hidden />
        Start today&apos;s run
      </button>

      <div className="mx-1 h-6 w-px bg-healthcare-border dark:bg-healthcare-border-dark" aria-hidden />

      <label className="flex items-center gap-1.5 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Run
        <select
          className={selectClass}
          value={selectedRun?.run_uuid ?? ''}
          onChange={(e) => onRunChange(e.target.value)}
          data-testid="rounds-run-select"
        >
          <option value="" disabled>
            {visibleRuns.length === 0 ? 'No runs yet' : 'Select run'}
          </option>
          {visibleRuns.map((run) => (
            <option key={run.run_uuid} value={run.run_uuid}>
              {run.scope_label ?? run.scope_key} · {RUN_STATUS_LABEL[run.status]} ·{' '}
              {formatClock(run.planned_start_at)}
            </option>
          ))}
        </select>
      </label>

      {cancelledCount > 0 && (
        <label className="flex items-center gap-1.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          <input
            type="checkbox"
            checked={showCancelled}
            onChange={(e) => setShowCancelled(e.target.checked)}
            data-testid="rounds-show-cancelled"
          />
          Show cancelled ({cancelledCount})
        </label>
      )}

      {selectedRun && (
        <>
          <span className="inline-flex items-center gap-1 rounded-full border border-healthcare-border px-2 py-0.5 text-xs font-medium text-healthcare-text-primary dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark">
            {RUN_STATUS_LABEL[selectedRun.status]}
          </span>

          {(status === 'draft' || status === 'scheduled') && (
            <button type="button" className={buttonClass} onClick={() => onLifecycle('start')} disabled={busy}>
              <CirclePlay className="h-4 w-4" aria-hidden /> Start
            </button>
          )}
          {status === 'active' && (
            <button type="button" className={buttonClass} onClick={() => onLifecycle('pause')} disabled={busy}>
              <CirclePause className="h-4 w-4" aria-hidden /> Pause
            </button>
          )}
          {status === 'paused' && (
            <button type="button" className={buttonClass} onClick={() => onLifecycle('resume')} disabled={busy}>
              <CirclePlay className="h-4 w-4" aria-hidden /> Resume
            </button>
          )}
          {status === 'active' &&
            (confirmingComplete ? (
              <span className="inline-flex items-center gap-1.5">
                <button
                  type="button"
                  className={primaryButtonClass}
                  onClick={() => {
                    setConfirmingComplete(false);
                    onLifecycle('complete');
                  }}
                  disabled={busy}
                >
                  Confirm complete
                </button>
                <button type="button" className={buttonClass} onClick={() => setConfirmingComplete(false)}>
                  Keep rounding
                </button>
              </span>
            ) : (
              <button
                type="button"
                className={buttonClass}
                onClick={() => setConfirmingComplete(true)}
                disabled={busy}
              >
                <CircleStop className="h-4 w-4" aria-hidden /> Complete run
              </button>
            ))}

          {selectedRun.source_cutoff_at && (
            <span className="ml-auto inline-flex items-center gap-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              <CalendarClock className="h-3.5 w-3.5" aria-hidden />
              Census as of <span className="tabular-nums">{formatClock(selectedRun.source_cutoff_at)}</span>
            </span>
          )}
        </>
      )}
    </div>
  );
}
