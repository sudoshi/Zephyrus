import { useState } from 'react';
import axios from 'axios';
import {
  AlertTriangle,
  CheckCircle2,
  FilePlus2,
  FileWarning,
  GitCompareArrows,
  Loader2,
  MinusCircle,
  PencilLine,
  ShieldCheck,
} from 'lucide-react';
import { Section } from '@/Components/system';
import {
  applyEnterpriseImport,
  decideEnterpriseImport,
  previewEnterpriseImport,
  requestEnterpriseImportCommit,
  type ConflictResolutions,
  type ImportPreview,
  type ImportPreviewRow,
  type RegistryPayload,
} from '@/features/deployment/enterpriseImport';

export type EnterpriseGovernance = {
  canManage: boolean;
  entityTypes: string[];
  pendingChanges: PendingImportChange[];
  changeHistory: ChangeHistoryEntry[];
};

type PendingImportChange = {
  changeRequestUuid: string;
  reason: string;
  payloadSha256: string;
  requestedAtIso: string;
  expiresAtIso: string;
  author: { id: number; name: string; username: string } | null;
  authoredByCurrentUser: boolean;
  decision: { decision: string; reason: string; decidedAtIso: string } | null;
  summary: { create: number; update: number; noChange: number; readinessScore: number };
};

type ChangeHistoryEntry = {
  entityType: string;
  naturalKey: string;
  changeKind: string;
  sourceOfTruth: string;
  changedFields: string[];
  recordedAtIso: string | null;
  governedChangeRequestUuid: string | null;
};

const INPUT =
  'w-full rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm text-healthcare-text-primary placeholder:text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark';

const BTN_PRIMARY =
  'inline-flex items-center gap-1.5 rounded-md bg-healthcare-primary px-3 py-1.5 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50';
const BTN_SECONDARY =
  'inline-flex items-center gap-1.5 rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-1.5 text-sm font-medium text-healthcare-text-primary hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark disabled:opacity-50';

function failureMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    if (error.response?.status === 428) {
      window.location.assign(error.response.data?.error?.reauthentication_url ?? '/confirm-password');
      return 'Recent authentication is required. Redirecting to re-authentication.';
    }
    if (error.response?.status === 422) {
      const errors = error.response.data?.errors as Record<string, string[]> | undefined;
      return errors ? Object.values(errors).flat().join(' ') : 'The import failed validation.';
    }
    const code = error.response?.data?.error?.code;
    if (typeof code === 'string') {
      return `Governance rejected the request (${code.replaceAll('_', ' ')}).`;
    }
  }
  return 'The request could not be completed. No enterprise change was applied.';
}

const CHANGE_KIND_META: Record<
  string,
  { label: string; icon: typeof FilePlus2; tone: string }
> = {
  create: { label: 'Create', icon: FilePlus2, tone: 'text-healthcare-success dark:text-healthcare-success-dark' },
  update: { label: 'Update', icon: PencilLine, tone: 'text-healthcare-info dark:text-healthcare-info-dark' },
  no_change: { label: 'No change', icon: MinusCircle, tone: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark' },
  conflict: { label: 'Conflict', icon: AlertTriangle, tone: 'text-healthcare-critical dark:text-healthcare-critical-dark' },
  skipped: { label: 'Skipped', icon: MinusCircle, tone: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark' },
  blocked: { label: 'Blocked', icon: FileWarning, tone: 'text-healthcare-warning dark:text-healthcare-warning-dark' },
};

function CountTile({
  label,
  value,
  icon: Icon,
  tone,
}: {
  label: string;
  value: number;
  icon: typeof FilePlus2;
  tone: string;
}) {
  return (
    <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className={`flex items-center gap-1.5 text-xs font-medium ${tone}`}>
        <Icon className="size-3.5" aria-hidden="true" />
        {label}
      </div>
      <div className="mt-1 text-2xl font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {value}
      </div>
    </div>
  );
}

function ChangeKindPill({ kind }: { kind: string }) {
  const meta = CHANGE_KIND_META[kind] ?? CHANGE_KIND_META.no_change;
  const Icon = meta.icon;
  return (
    <span className={`inline-flex items-center gap-1 text-xs font-medium ${meta.tone}`}>
      <Icon className="size-3.5" aria-hidden="true" />
      {meta.label}
    </span>
  );
}

export function EnterpriseImport({ governance }: { governance: EnterpriseGovernance }) {
  const [payloadText, setPayloadText] = useState('');
  const [payload, setPayload] = useState<RegistryPayload | null>(null);
  const [preview, setPreview] = useState<ImportPreview | null>(null);
  const [resolutions, setResolutions] = useState<ConflictResolutions>({});
  const [changeReason, setChangeReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);

  const runPreview = async (nextResolutions: ConflictResolutions = resolutions) => {
    setBusy(true);
    setMessage(null);
    let parsed: RegistryPayload;
    try {
      parsed = JSON.parse(payloadText) as RegistryPayload;
    } catch {
      setBusy(false);
      setMessage({ tone: 'error', text: 'The registry payload is not valid JSON.' });
      return;
    }
    setPayload(parsed);
    try {
      const result = await previewEnterpriseImport(parsed, nextResolutions);
      setPreview(result);
    } catch (error) {
      setMessage({ tone: 'error', text: failureMessage(error) });
    } finally {
      setBusy(false);
    }
  };

  const resolveConflict = (conflictKey: string, resolution: 'adopt' | 'skip') => {
    const next = { ...resolutions, [conflictKey]: resolution };
    setResolutions(next);
    void runPreview(next);
  };

  const submitCommit = async () => {
    if (payload === null) return;
    setBusy(true);
    setMessage(null);
    try {
      await requestEnterpriseImportCommit(payload, resolutions, changeReason);
      setMessage({
        tone: 'success',
        text: 'Import commit requested. An independent steward must approve before it applies.',
      });
      setChangeReason('');
      window.setTimeout(() => window.location.reload(), 1200);
    } catch (error) {
      setMessage({ tone: 'error', text: failureMessage(error) });
    } finally {
      setBusy(false);
    }
  };

  const decide = async (uuid: string, approve: boolean) => {
    const reason = window.prompt(
      approve ? 'Approval reason (10-500 chars):' : 'Rejection reason (10-500 chars):',
    );
    if (reason === null) return;
    setBusy(true);
    setMessage(null);
    try {
      await decideEnterpriseImport(uuid, approve, reason);
      window.location.reload();
    } catch (error) {
      setMessage({ tone: 'error', text: failureMessage(error) });
      setBusy(false);
    }
  };

  const applyChange = async (uuid: string) => {
    if (payload === null) {
      setMessage({
        tone: 'error',
        text: 'Re-paste and preview the exact approved payload before applying.',
      });
      return;
    }
    setBusy(true);
    setMessage(null);
    try {
      await applyEnterpriseImport(uuid, payload, resolutions);
      setMessage({ tone: 'success', text: 'Enterprise import applied.' });
      window.setTimeout(() => window.location.reload(), 1200);
    } catch (error) {
      setMessage({ tone: 'error', text: failureMessage(error) });
      setBusy(false);
    }
  };

  const unresolved = preview?.unresolvedConflictCount ?? 0;

  return (
    <div className="space-y-4">
      {message && (
        <div
          role={message.tone === 'error' ? 'alert' : 'status'}
          className={`flex items-center gap-2 rounded-md border px-3 py-2 text-sm ${
            message.tone === 'error'
              ? 'border-healthcare-critical/40 text-healthcare-critical dark:text-healthcare-critical-dark'
              : 'border-healthcare-success/40 text-healthcare-success dark:text-healthcare-success-dark'
          }`}
        >
          {message.tone === 'error' ? (
            <AlertTriangle className="size-4" aria-hidden="true" />
          ) : (
            <CheckCircle2 className="size-4" aria-hidden="true" />
          )}
          {message.text}
        </div>
      )}

      <Section
        title="Registry import"
        summary="Paste a registry payload to preview additive changes before a governed commit"
        icon="heroicons:arrow-up-tray"
      >
        <div className="space-y-3">
          <label htmlFor="registry-payload" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Registry payload (JSON)
          </label>
          <textarea
            id="registry-payload"
            className={`${INPUT} font-normal`}
            rows={8}
            spellCheck={false}
            value={payloadText}
            onChange={(e) => setPayloadText(e.target.value)}
            placeholder='{ "organizations": [ { "key": "IDN_ONE", "name": "Enterprise One" } ] }'
          />
          <div className="flex flex-wrap items-center gap-2">
            <button type="button" className={BTN_SECONDARY} onClick={() => void runPreview()} disabled={busy || payloadText.trim() === ''}>
              {busy ? <Loader2 className="size-4 animate-spin" aria-hidden="true" /> : <GitCompareArrows className="size-4" aria-hidden="true" />}
              Preview changes
            </button>
            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Existing entities are never deleted — the preview is additive/upsert only.
            </span>
          </div>
        </div>
      </Section>

      {preview && (
        <>
          <Section title="Preview" summary="What this import would create, update, or leave unchanged" icon="heroicons:document-magnifying-glass">
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                <CountTile label="Create" value={preview.summary.create} icon={FilePlus2} tone={CHANGE_KIND_META.create.tone} />
                <CountTile label="Update" value={preview.summary.update} icon={PencilLine} tone={CHANGE_KIND_META.update.tone} />
                <CountTile label="Conflict" value={preview.summary.conflict} icon={AlertTriangle} tone={CHANGE_KIND_META.conflict.tone} />
                <CountTile label="Blocked" value={preview.summary.blocked} icon={FileWarning} tone={CHANGE_KIND_META.blocked.tone} />
                <CountTile label="No change" value={preview.summary.no_change} icon={MinusCircle} tone={CHANGE_KIND_META.no_change.tone} />
              </div>

              {/* Readiness scoring — status is never color-alone (icon + label + number). */}
              <div className="flex flex-wrap items-center gap-3 rounded-lg border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                {preview.readiness.committable ? (
                  <ShieldCheck className="size-5 text-healthcare-success dark:text-healthcare-success-dark" aria-hidden="true" />
                ) : (
                  <AlertTriangle className="size-5 text-healthcare-warning dark:text-healthcare-warning-dark" aria-hidden="true" />
                )}
                <div>
                  <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    Readiness {preview.readiness.committable ? 'ready to commit' : 'not committable'}
                  </div>
                  <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    <span className="tabular-nums">{preview.readiness.score}</span>/100 · {preview.readiness.appliedCount} to apply ·{' '}
                    {unresolved} unresolved conflict{unresolved === 1 ? '' : 's'} · {preview.readiness.blockedCount} blocked
                  </div>
                </div>
              </div>

              {Object.entries(preview.entities).map(([entityType, entity]) => (
                <div key={entityType}>
                  <div className="mb-1 text-sm font-medium capitalize text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {entityType.replaceAll('_', ' ')} <span className="tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">({entity.total})</span>
                  </div>
                  <ul className="divide-y divide-healthcare-border rounded-md border border-healthcare-border dark:divide-healthcare-border-dark dark:border-healthcare-border-dark">
                    {entity.rows.map((row) => (
                      <EntityRow key={row.conflictKey} row={row} />
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          </Section>

          {preview.conflicts.length > 0 && (
            <Section title="Conflict review" summary="Every conflict must be explicitly resolved before commit" icon="heroicons:exclamation-triangle">
              <ul className="space-y-2">
                {preview.conflicts.map((conflict) => (
                  <li
                    key={conflict.conflictKey}
                    className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-healthcare-border bg-healthcare-surface p-3 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
                  >
                    <div className="min-w-0">
                      <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {conflict.entityType.replaceAll('_', ' ')} · {conflict.naturalKey}
                      </div>
                      <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {(conflict.reason ?? 'conflict').replaceAll('_', ' ')}
                        {conflict.collidingNaturalKey && ` — collides with ${conflict.collidingNaturalKey}`}
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      {conflict.resolution && (
                        <span className="inline-flex items-center gap-1 text-xs font-medium text-healthcare-success dark:text-healthcare-success-dark">
                          <CheckCircle2 className="size-3.5" aria-hidden="true" />
                          {conflict.resolution === 'adopt' ? 'Adopting payload' : 'Skipping'}
                        </span>
                      )}
                      {governance.canManage && (
                        <>
                          <button type="button" className={BTN_SECONDARY} onClick={() => resolveConflict(conflict.conflictKey, 'adopt')} disabled={busy}>
                            Adopt
                          </button>
                          <button type="button" className={BTN_SECONDARY} onClick={() => resolveConflict(conflict.conflictKey, 'skip')} disabled={busy}>
                            Skip
                          </button>
                        </>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            </Section>
          )}

          {governance.canManage && (
            <Section title="Commit" summary="Committing an import is a governed, dual-controlled, step-up change" icon="heroicons:lock-closed">
              <div className="space-y-3">
                <textarea
                  className={`${INPUT} font-normal`}
                  rows={2}
                  value={changeReason}
                  onChange={(e) => setChangeReason(e.target.value)}
                  placeholder="Change reason (10-500 chars) — recorded on the governed change."
                />
                <button
                  type="button"
                  className={BTN_PRIMARY}
                  onClick={() => void submitCommit()}
                  disabled={busy || !preview.readiness.committable || changeReason.trim().length < 10}
                >
                  {busy ? <Loader2 className="size-4 animate-spin" aria-hidden="true" /> : <ShieldCheck className="size-4" aria-hidden="true" />}
                  Request governed commit
                </button>
                {!preview.readiness.committable && (
                  <p className="text-xs text-healthcare-warning dark:text-healthcare-warning-dark">
                    Resolve every conflict and ensure at least one create/update before requesting a commit.
                  </p>
                )}
              </div>
            </Section>
          )}
        </>
      )}

      {governance.pendingChanges.length > 0 && (
        <Section title="Pending governed imports" summary="Awaiting independent decision or exact-payload execution" icon="heroicons:clock">
          <ul className="space-y-2">
            {governance.pendingChanges.map((change) => (
              <li
                key={change.changeRequestUuid}
                className="rounded-md border border-healthcare-border bg-healthcare-surface p-3 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
              >
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="min-w-0">
                    <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{change.reason}</div>
                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      by {change.author?.name ?? 'unknown'} · +{change.summary.create} / ~{change.summary.update} ·{' '}
                      readiness <span className="tabular-nums">{change.summary.readinessScore}</span>
                      {change.decision && ` · ${change.decision.decision}`}
                    </div>
                  </div>
                  {governance.canManage && (
                    <div className="flex items-center gap-2">
                      {!change.decision && !change.authoredByCurrentUser && (
                        <>
                          <button type="button" className={BTN_SECONDARY} onClick={() => void decide(change.changeRequestUuid, true)} disabled={busy}>
                            Approve
                          </button>
                          <button type="button" className={BTN_SECONDARY} onClick={() => void decide(change.changeRequestUuid, false)} disabled={busy}>
                            Reject
                          </button>
                        </>
                      )}
                      {change.decision?.decision === 'approved' && (
                        <button type="button" className={BTN_PRIMARY} onClick={() => void applyChange(change.changeRequestUuid)} disabled={busy}>
                          Apply approved import
                        </button>
                      )}
                    </div>
                  )}
                </div>
              </li>
            ))}
          </ul>
        </Section>
      )}

      {governance.changeHistory.length > 0 && (
        <Section title="Change history" summary="Append-only ledger of governed enterprise changes" icon="heroicons:queue-list">
          <ul className="divide-y divide-healthcare-border rounded-md border border-healthcare-border dark:divide-healthcare-border-dark dark:border-healthcare-border-dark">
            {governance.changeHistory.slice(0, 50).map((entry, index) => (
              <li key={`${entry.entityType}-${entry.naturalKey}-${index}`} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                <div className="flex items-center gap-2">
                  <ChangeKindPill kind={entry.changeKind} />
                  <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {entry.entityType.replaceAll('_', ' ')} · {entry.naturalKey}
                  </span>
                </div>
                <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {entry.sourceOfTruth.replaceAll('_', ' ')}
                  {entry.changedFields.length > 0 && ` · ${entry.changedFields.join(', ')}`}
                </span>
              </li>
            ))}
          </ul>
        </Section>
      )}
    </div>
  );
}

function EntityRow({ row }: { row: ImportPreviewRow }) {
  return (
    <li className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
      <div className="min-w-0">
        <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{row.displayName}</span>
        <span className="ml-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.naturalKey}</span>
      </div>
      <div className="flex items-center gap-3">
        {row.changedFields.length > 0 && (
          <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{row.changedFields.join(', ')}</span>
        )}
        {row.blockedReason && (
          <span className="text-xs text-healthcare-warning dark:text-healthcare-warning-dark">{row.blockedReason.replaceAll('_', ' ')}</span>
        )}
        <ChangeKindPill kind={row.changeKind} />
      </div>
    </li>
  );
}
