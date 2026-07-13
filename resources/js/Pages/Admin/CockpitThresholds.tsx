// resources/js/Pages/Admin/CockpitThresholds.tsx
//
// ADM-POLICY — governed cockpit threshold policy. Every effective threshold is
// a versioned policy (owner, scope, unit, direction, validation constraints,
// effective date, reason); a change is proposed with preview, independently
// approved (author ≠ approver, step-up enforced server-side), then applied.
// Rollback appends a NEW version referencing a prior one. Duplicate/ambiguous
// metric keys are detected server-side and rendered with domain/scope/status
// filtering. Inertia pages are default-exported by project convention.
import { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { ArrowDown, ArrowLeft, ArrowUp, History, Minus, ShieldAlert } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import {
  AdminMetricStrip,
  AdminSectionHeading,
  type AdminMetric,
} from '@/Pages/Admin/components/AdminPrimitives';

interface ThresholdPolicy {
  metric_key: string;
  owner: string | null;
  scope: string;
  unit: string | null;
  direction: 'up' | 'down' | 'neutral';
  target: number | null;
  ok_edge: number | null;
  warn_edge: number | null;
  crit_edge: number | null;
  refresh_secs: number;
  alert_template: string | null;
  is_active: boolean;
}

interface ThresholdDefinition {
  metricKey: string;
  label: string;
  domain: string;
  scope: string;
  status: 'active' | 'inactive';
  policy: ThresholdPolicy;
  flagged: boolean;
  currentVersion: { versionNumber: number; changeKind: string; effectiveAtIso: string | null } | null;
}

interface DuplicateGroup {
  normalizedKey: string;
  kind: 'duplicate' | 'ambiguous';
  members: { metricKey: string; domain: string; scope: string; active: boolean }[];
}

interface PendingChange {
  changeRequestUuid: string;
  metricKey: string;
  reason: string;
  requestedAtIso: string;
  expiresAtIso: string;
  author: { id: number; name: string; username: string } | null;
  authoredByCurrentUser: boolean;
  decision: { decision: string; reason: string; decidedAtIso: string } | null;
  changedFields: string[];
  proposalVersionNumber: number | null;
  rollbackToVersionNumber: number | null;
}

interface VersionRecord {
  versionId: number;
  versionNumber: number;
  changeKind: string;
  changeReason: string;
  policy: ThresholdPolicy;
  policySha256: string;
  rolledBackToVersionId: number | null;
  effectiveAtIso: string | null;
  createdBy: { id: number; name: string; username: string } | null;
}

interface PreviewResult {
  changedFields: string[];
  errors: string[];
  policySha256: string;
  proposed: ThresholdPolicy;
}

interface CockpitThresholdsProps {
  definitions: ThresholdDefinition[];
  duplicates: DuplicateGroup[];
  filters: { domains: string[]; scopes: string[]; statuses: string[] };
  selectedMetric: string | null;
  selectedMetricHistory: VersionRecord[];
  pendingChanges: PendingChange[];
  canManage: boolean;
}

interface Draft {
  owner: string;
  unit: string;
  direction: 'up' | 'down' | 'neutral';
  target: string;
  ok_edge: string;
  warn_edge: string;
  crit_edge: string;
  refresh_secs: string;
  alert_template: string;
  is_active: boolean;
  change_reason: string;
}

const inputClass =
  'rounded-md border border-healthcare-border dark:border-healthcare-border-dark ' +
  'bg-healthcare-surface dark:bg-healthcare-surface-dark px-2 py-1 text-sm ' +
  'text-healthcare-text-primary dark:text-healthcare-text-primary-dark';

const buttonClass =
  'rounded-md bg-healthcare-primary px-3 py-1.5 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-50';

const subtleButtonClass =
  'rounded-md border border-healthcare-border px-3 py-1.5 text-sm font-medium ' +
  'text-healthcare-text-primary hover:bg-healthcare-hover disabled:cursor-not-allowed disabled:opacity-50 ' +
  'dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark';

const cellClass = 'whitespace-nowrap px-3 py-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark';

const headClass = 'whitespace-nowrap px-3 py-2 text-left text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark';

function toDraft(policy: ThresholdPolicy): Draft {
  const asInput = (value: number | null): string => (value === null ? '' : String(value));
  return {
    owner: policy.owner ?? '',
    unit: policy.unit ?? '',
    direction: policy.direction,
    target: asInput(policy.target),
    ok_edge: asInput(policy.ok_edge),
    warn_edge: asInput(policy.warn_edge),
    crit_edge: asInput(policy.crit_edge),
    refresh_secs: String(policy.refresh_secs),
    alert_template: policy.alert_template ?? '',
    is_active: policy.is_active,
    change_reason: '',
  };
}

function draftUpdates(draft: Draft): Record<string, unknown> {
  const toNumber = (value: string): number | null => (value.trim() === '' ? null : Number(value));
  return {
    owner: draft.owner.trim() === '' ? null : draft.owner.trim(),
    unit: draft.unit.trim() === '' ? null : draft.unit.trim(),
    direction: draft.direction,
    target: toNumber(draft.target),
    ok_edge: toNumber(draft.ok_edge),
    warn_edge: toNumber(draft.warn_edge),
    crit_edge: toNumber(draft.crit_edge),
    refresh_secs: draft.refresh_secs.trim() === '' ? 300 : Number(draft.refresh_secs),
    alert_template: draft.alert_template.trim() === '' ? null : draft.alert_template,
    is_active: draft.is_active,
  };
}

function DirectionLabel({ direction }: { direction: 'up' | 'down' | 'neutral' }) {
  const Icon = direction === 'up' ? ArrowUp : direction === 'down' ? ArrowDown : Minus;
  const label = direction === 'up' ? 'higher is better' : direction === 'down' ? 'lower is better' : 'neutral';
  return (
    <span className="inline-flex items-center gap-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
      <Icon className="h-3.5 w-3.5" aria-hidden="true" />
      {label}
    </span>
  );
}

function failureMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    if (error.response?.status === 428) {
      window.location.assign(error.response.data?.error?.reauthentication_url ?? '/confirm-password');
      return 'Recent authentication is required. Redirecting to re-authentication.';
    }
    if (error.response?.status === 422) {
      const errors = error.response.data?.errors as Record<string, string[]> | undefined;
      return errors ? Object.values(errors).flat().join(' ') : 'The proposal failed validation.';
    }
    const code = error.response?.data?.error?.code;
    if (typeof code === 'string') {
      return `Governance rejected the request (${code.replaceAll('_', ' ')}).`;
    }
  }
  return 'The request could not be completed. No policy change was applied.';
}

export default function CockpitThresholds({
  definitions,
  duplicates,
  filters,
  selectedMetric,
  selectedMetricHistory,
  pendingChanges,
  canManage,
}: CockpitThresholdsProps) {
  const [domainFilter, setDomainFilter] = useState('all');
  const [scopeFilter, setScopeFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all');
  const [editingKey, setEditingKey] = useState<string | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [preview, setPreview] = useState<PreviewResult | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [decisionReasons, setDecisionReasons] = useState<Record<string, string>>({});
  const [rollbackReasons, setRollbackReasons] = useState<Record<number, string>>({});

  const visible = useMemo(() => definitions.filter((definition) => (
    (domainFilter === 'all' || definition.domain === domainFilter)
    && (scopeFilter === 'all' || definition.scope === scopeFilter)
    && (statusFilter === 'all' || definition.status === statusFilter)
  )), [definitions, domainFilter, scopeFilter, statusFilter]);

  const duplicateCount = duplicates.filter((group) => group.kind === 'duplicate').length;
  const ambiguousCount = duplicates.filter((group) => group.kind === 'ambiguous').length;
  const awaitingDecision = pendingChanges.filter((change) => change.decision === null).length;

  const metrics: AdminMetric[] = [
    { label: 'Threshold policies', value: definitions.length },
    { label: 'Active', value: definitions.filter((definition) => definition.status === 'active').length },
    { label: 'Duplicate keys', value: duplicateCount, tone: duplicateCount > 0 ? 'critical' : 'default' },
    { label: 'Ambiguous keys', value: ambiguousCount, tone: ambiguousCount > 0 ? 'warning' : 'default' },
    { label: 'Awaiting decision', value: awaitingDecision, tone: awaitingDecision > 0 ? 'warning' : 'default' },
  ];

  const startEditing = (definition: ThresholdDefinition) => {
    setEditingKey(definition.metricKey);
    setDraft(toDraft(definition.policy));
    setPreview(null);
    setMessage(null);
  };

  const runPreview = async () => {
    if (editingKey === null || draft === null) return;
    setBusy(true);
    setMessage(null);
    try {
      const response = await axios.post<PreviewResult>(
        `/admin/cockpit/thresholds/${encodeURIComponent(editingKey)}/preview`,
        { updates: draftUpdates(draft) },
      );
      setPreview(response.data);
    } catch (error) {
      setMessage(failureMessage(error));
    } finally {
      setBusy(false);
    }
  };

  const submitProposal = async () => {
    if (editingKey === null || draft === null) return;
    setBusy(true);
    setMessage(null);
    try {
      await axios.post(`/admin/cockpit/thresholds/${encodeURIComponent(editingKey)}/changes`, {
        updates: draftUpdates(draft),
        change_reason: draft.change_reason,
      });
      setEditingKey(null);
      setDraft(null);
      setPreview(null);
      setMessage('Change requested. It becomes effective only after an independent approval and apply.');
      router.reload({ only: ['pendingChanges'] });
    } catch (error) {
      setMessage(failureMessage(error));
    } finally {
      setBusy(false);
    }
  };

  const decide = async (change: PendingChange, approve: boolean) => {
    setBusy(true);
    setMessage(null);
    try {
      await axios.post(`/admin/cockpit/threshold-changes/${change.changeRequestUuid}/decision`, {
        approve,
        reason: decisionReasons[change.changeRequestUuid] ?? '',
      });
      setMessage(approve ? 'Change approved.' : 'Change rejected.');
      router.reload({ only: ['pendingChanges'] });
    } catch (error) {
      setMessage(failureMessage(error));
    } finally {
      setBusy(false);
    }
  };

  const apply = async (change: PendingChange) => {
    setBusy(true);
    setMessage(null);
    try {
      await axios.post(`/admin/cockpit/threshold-changes/${change.changeRequestUuid}/apply`, {});
      setMessage(`Applied the approved policy for ${change.metricKey} as a new version.`);
      router.reload();
    } catch (error) {
      setMessage(failureMessage(error));
    } finally {
      setBusy(false);
    }
  };

  const requestRollback = async (version: VersionRecord) => {
    if (selectedMetric === null) return;
    setBusy(true);
    setMessage(null);
    try {
      await axios.post(`/admin/cockpit/thresholds/${encodeURIComponent(selectedMetric)}/changes`, {
        rollback_to_version_number: version.versionNumber,
        change_reason: rollbackReasons[version.versionNumber] ?? '',
      });
      setMessage(`Rollback to version ${version.versionNumber} requested; it awaits independent approval.`);
      router.reload({ only: ['pendingChanges'] });
    } catch (error) {
      setMessage(failureMessage(error));
    } finally {
      setBusy(false);
    }
  };

  return (
    <DashboardLayout>
      <Head title="Cockpit Governance · Zephyrus" />
      <PageContentLayout
        title="Cockpit Governance"
        subtitle="Versioned threshold policies with preview, independent approval, and rollback"
        headerContent={null}
      >
        <div className="space-y-5">
          <Link href="/admin" className="inline-flex items-center gap-1 text-sm font-medium text-healthcare-info hover:underline dark:text-healthcare-info-dark">
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
            Administration overview
          </Link>

          <AdminMetricStrip metrics={metrics} />

          {message ? (
            <div role="status" className="rounded-md border border-healthcare-info/30 bg-healthcare-info/5 p-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {message}
            </div>
          ) : null}

          {duplicates.length > 0 ? (
            <section>
              <AdminSectionHeading
                title="Duplicate and ambiguous metric keys"
                description="Normalized key collisions and base names registered under more than one domain or scope"
              />
              <div className="overflow-x-auto rounded-md border border-healthcare-warning/40 bg-healthcare-surface shadow-sm dark:bg-healthcare-surface-dark">
                <table className="min-w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark">
                  <thead>
                    <tr>
                      <th className={headClass}>Normalized key</th>
                      <th className={headClass}>Kind</th>
                      <th className={headClass}>Members (key · domain · scope · status)</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                    {duplicates.map((group) => (
                      <tr key={`${group.kind}:${group.normalizedKey}`}>
                        <td className={`${cellClass} tabular-nums`}>{group.normalizedKey}</td>
                        <td className={cellClass}>
                          <span className="inline-flex items-center gap-1 font-medium text-healthcare-warning dark:text-healthcare-warning-dark">
                            <ShieldAlert className="h-3.5 w-3.5" aria-hidden="true" />
                            {group.kind}
                          </span>
                        </td>
                        <td className="px-3 py-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          {group.members.map((member) => (
                            <span key={member.metricKey} className="mr-3 inline-block tabular-nums">
                              {member.metricKey} · {member.domain} · {member.scope} · {member.active ? 'active' : 'inactive'}
                            </span>
                          ))}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          ) : null}

          <section>
            <AdminSectionHeading
              title="Threshold policies"
              description="The effective policy is a projection of its latest applied version; direct edits never bypass the ledger"
            />
            <div className="mb-2 flex flex-wrap items-center gap-2">
              <label className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" htmlFor="domain-filter">Domain</label>
              <select id="domain-filter" className={inputClass} value={domainFilter} onChange={(event) => setDomainFilter(event.target.value)}>
                <option value="all">All</option>
                {filters.domains.map((domain) => <option key={domain} value={domain}>{domain}</option>)}
              </select>
              <label className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" htmlFor="scope-filter">Scope</label>
              <select id="scope-filter" className={inputClass} value={scopeFilter} onChange={(event) => setScopeFilter(event.target.value)}>
                <option value="all">All</option>
                {filters.scopes.map((scope) => <option key={scope} value={scope}>{scope}</option>)}
              </select>
              <label className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" htmlFor="status-filter">Status</label>
              <select id="status-filter" className={inputClass} value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
                <option value="all">All</option>
                {filters.statuses.map((status) => <option key={status} value={status}>{status}</option>)}
              </select>
            </div>
            <div className="overflow-x-auto rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <table className="min-w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark">
                <thead>
                  <tr>
                    <th className={headClass}>Key</th>
                    <th className={headClass}>Owner</th>
                    <th className={headClass}>Domain / scope</th>
                    <th className={headClass}>Unit / direction</th>
                    <th className={headClass}>OK / Warn / Crit</th>
                    <th className={headClass}>Version</th>
                    <th className={headClass}>Status</th>
                    <th className={headClass}><span className="sr-only">Actions</span></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                  {visible.length === 0 ? (
                    <tr><td colSpan={8} className="px-4 py-8 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No threshold policies match this filter.</td></tr>
                  ) : visible.map((definition) => (
                    <tr key={definition.metricKey}>
                      <td className={cellClass}>
                        <span className="tabular-nums">{definition.metricKey}</span>
                        {definition.flagged ? (
                          <span className="ml-2 inline-flex items-center gap-1 rounded-md bg-healthcare-warning/10 px-1.5 py-0.5 text-xs font-medium text-healthcare-warning dark:text-healthcare-warning-dark">
                            <ShieldAlert className="h-3 w-3" aria-hidden="true" />
                            key conflict
                          </span>
                        ) : null}
                        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{definition.label}</p>
                      </td>
                      <td className={cellClass}>{definition.policy.owner ?? 'Unassigned'}</td>
                      <td className={cellClass}>{definition.domain} / {definition.scope}</td>
                      <td className={cellClass}>
                        {definition.policy.unit || '—'}{' '}
                        <DirectionLabel direction={definition.policy.direction} />
                      </td>
                      <td className={`${cellClass} tabular-nums`}>
                        {definition.policy.ok_edge ?? '—'} / {definition.policy.warn_edge ?? '—'} / {definition.policy.crit_edge ?? '—'}
                      </td>
                      <td className={`${cellClass} tabular-nums`}>
                        {definition.currentVersion ? `v${definition.currentVersion.versionNumber}` : 'unversioned'}
                      </td>
                      <td className={cellClass}>{definition.status}</td>
                      <td className={`${cellClass} text-right`}>
                        <button
                          type="button"
                          className="mr-2 inline-flex items-center gap-1 text-sm font-medium text-healthcare-info hover:underline dark:text-healthcare-info-dark"
                          onClick={() => router.visit(`/admin/cockpit/thresholds?metric=${encodeURIComponent(definition.metricKey)}`, { preserveScroll: true })}
                        >
                          <History className="h-3.5 w-3.5" aria-hidden="true" />
                          History
                        </button>
                        {canManage ? (
                          <button type="button" className={subtleButtonClass} onClick={() => startEditing(definition)}>
                            Propose change
                          </button>
                        ) : null}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          {canManage && editingKey !== null && draft !== null ? (
            <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <AdminSectionHeading
                title={`Propose threshold policy for ${editingKey}`}
                description="Preview the exact versioned policy, then submit it for independent approval"
              />
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Owner
                  <input className={`${inputClass} mt-1 w-full`} value={draft.owner} onChange={(event) => setDraft({ ...draft, owner: event.target.value })} />
                </label>
                <label className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Unit
                  <input className={`${inputClass} mt-1 w-full`} value={draft.unit} onChange={(event) => setDraft({ ...draft, unit: event.target.value })} />
                </label>
                <label className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Direction
                  <select className={`${inputClass} mt-1 w-full`} value={draft.direction} onChange={(event) => setDraft({ ...draft, direction: event.target.value as Draft['direction'] })}>
                    <option value="up">up (higher is better)</option>
                    <option value="down">down (lower is better)</option>
                    <option value="neutral">neutral</option>
                  </select>
                </label>
                <label className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Target
                  <input type="number" className={`${inputClass} mt-1 w-full tabular-nums`} value={draft.target} onChange={(event) => setDraft({ ...draft, target: event.target.value })} />
                </label>
                <label className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  OK edge
                  <input type="number" className={`${inputClass} mt-1 w-full tabular-nums`} value={draft.ok_edge} onChange={(event) => setDraft({ ...draft, ok_edge: event.target.value })} />
                </label>
                <label className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Warn edge
                  <input type="number" className={`${inputClass} mt-1 w-full tabular-nums`} value={draft.warn_edge} onChange={(event) => setDraft({ ...draft, warn_edge: event.target.value })} />
                </label>
                <label className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Crit edge
                  <input type="number" className={`${inputClass} mt-1 w-full tabular-nums`} value={draft.crit_edge} onChange={(event) => setDraft({ ...draft, crit_edge: event.target.value })} />
                </label>
                <label className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Refresh seconds
                  <input type="number" className={`${inputClass} mt-1 w-full tabular-nums`} value={draft.refresh_secs} onChange={(event) => setDraft({ ...draft, refresh_secs: event.target.value })} />
                </label>
                <label className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark sm:col-span-2">
                  Alert template
                  <input className={`${inputClass} mt-1 w-full`} value={draft.alert_template} onChange={(event) => setDraft({ ...draft, alert_template: event.target.value })} />
                </label>
                <label className="flex items-center gap-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  <input type="checkbox" checked={draft.is_active} onChange={(event) => setDraft({ ...draft, is_active: event.target.checked })} />
                  Active
                </label>
              </div>
              <label className="mt-3 block text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Change reason (required, 10–500 characters)
                <textarea className={`${inputClass} mt-1 w-full`} rows={2} value={draft.change_reason} onChange={(event) => setDraft({ ...draft, change_reason: event.target.value })} />
              </label>
              {preview ? (
                <div className="mt-3 rounded-md border border-healthcare-border bg-healthcare-surface-secondary p-3 text-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-hover-dark">
                  <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Preview</p>
                  <p className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Changed fields: {preview.changedFields.length > 0 ? preview.changedFields.join(', ') : 'none'}
                  </p>
                  {preview.errors.length > 0 ? (
                    <p role="alert" className="mt-1 font-medium text-healthcare-critical dark:text-healthcare-critical-dark">
                      Validation constraints failed: {preview.errors.join(', ')}
                    </p>
                  ) : (
                    <p className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      Policy hash <span className="tabular-nums">{preview.policySha256.slice(0, 16)}…</span> passes validation.
                    </p>
                  )}
                </div>
              ) : null}
              <div className="mt-3 flex gap-2">
                <button type="button" className={subtleButtonClass} disabled={busy} onClick={() => void runPreview()}>Preview</button>
                <button type="button" className={buttonClass} disabled={busy || draft.change_reason.trim().length < 10} onClick={() => void submitProposal()}>
                  Submit for approval
                </button>
                <button type="button" className={subtleButtonClass} disabled={busy} onClick={() => { setEditingKey(null); setDraft(null); setPreview(null); }}>
                  Cancel
                </button>
              </div>
            </section>
          ) : null}

          <section>
            <AdminSectionHeading
              title="Pending governed changes"
              description="Request → independent decision → execution; the author can never approve their own change"
            />
            <div className="overflow-x-auto rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <table className="min-w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark">
                <thead>
                  <tr>
                    <th className={headClass}>Metric</th>
                    <th className={headClass}>Reason</th>
                    <th className={headClass}>Author</th>
                    <th className={headClass}>State</th>
                    <th className={headClass}><span className="sr-only">Actions</span></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                  {pendingChanges.length === 0 ? (
                    <tr><td colSpan={5} className="px-4 py-8 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No governed threshold changes are awaiting action.</td></tr>
                  ) : pendingChanges.map((change) => (
                    <tr key={change.changeRequestUuid}>
                      <td className={`${cellClass} tabular-nums`}>
                        {change.metricKey}
                        {change.rollbackToVersionNumber !== null ? (
                          <span className="ml-2 rounded-md bg-healthcare-info/10 px-1.5 py-0.5 text-xs font-medium text-healthcare-info dark:text-healthcare-info-dark">rollback to v{change.rollbackToVersionNumber}</span>
                        ) : null}
                      </td>
                      <td className="max-w-96 px-3 py-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{change.reason}</td>
                      <td className={cellClass}>{change.author?.name ?? 'Unknown'}</td>
                      <td className={cellClass}>{change.decision === null ? 'awaiting decision' : change.decision.decision}</td>
                      <td className={`${cellClass} text-right`}>
                        {canManage && change.decision === null ? (
                          <span className="inline-flex items-center gap-2">
                            <input
                              className={`${inputClass} w-56`}
                              placeholder="Decision reason (required)"
                              aria-label={`Decision reason for ${change.metricKey}`}
                              value={decisionReasons[change.changeRequestUuid] ?? ''}
                              onChange={(event) => setDecisionReasons({ ...decisionReasons, [change.changeRequestUuid]: event.target.value })}
                            />
                            <button type="button" className={buttonClass} disabled={busy || change.authoredByCurrentUser} onClick={() => void decide(change, true)}>Approve</button>
                            <button type="button" className={subtleButtonClass} disabled={busy || change.authoredByCurrentUser} onClick={() => void decide(change, false)}>Reject</button>
                            {change.authoredByCurrentUser ? (
                              <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">You authored this change</span>
                            ) : null}
                          </span>
                        ) : null}
                        {canManage && change.decision?.decision === 'approved' ? (
                          <button type="button" className={buttonClass} disabled={busy} onClick={() => void apply(change)}>Apply</button>
                        ) : null}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          {selectedMetric !== null ? (
            <section>
              <AdminSectionHeading
                title={`Version history — ${selectedMetric}`}
                description="Append-only ledger; rollback requests a NEW version referencing the selected prior one"
              />
              <div className="overflow-x-auto rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                <table className="min-w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark">
                  <thead>
                    <tr>
                      <th className={headClass}>Version</th>
                      <th className={headClass}>Kind</th>
                      <th className={headClass}>Owner / edges</th>
                      <th className={headClass}>Reason</th>
                      <th className={headClass}>Effective</th>
                      <th className={headClass}><span className="sr-only">Rollback</span></th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                    {selectedMetricHistory.map((version) => (
                      <tr key={version.versionId}>
                        <td className={`${cellClass} tabular-nums`}>v{version.versionNumber}</td>
                        <td className={cellClass}>{version.changeKind.replaceAll('_', ' ')}</td>
                        <td className={`${cellClass} tabular-nums`}>
                          {version.policy.owner ?? 'Unassigned'} · {version.policy.ok_edge ?? '—'} / {version.policy.warn_edge ?? '—'} / {version.policy.crit_edge ?? '—'}
                        </td>
                        <td className="max-w-96 px-3 py-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{version.changeReason}</td>
                        <td className={cellClass}>{version.effectiveAtIso ? new Date(version.effectiveAtIso).toLocaleString() : '—'}</td>
                        <td className={`${cellClass} text-right`}>
                          {canManage && version.changeKind !== 'proposal' ? (
                            <span className="inline-flex items-center gap-2">
                              <input
                                className={`${inputClass} w-56`}
                                placeholder="Rollback reason (required)"
                                aria-label={`Rollback reason for version ${version.versionNumber}`}
                                value={rollbackReasons[version.versionNumber] ?? ''}
                                onChange={(event) => setRollbackReasons({ ...rollbackReasons, [version.versionNumber]: event.target.value })}
                              />
                              <button type="button" className={subtleButtonClass} disabled={busy} onClick={() => void requestRollback(version)}>
                                Request rollback
                              </button>
                            </span>
                          ) : null}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          ) : null}
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
