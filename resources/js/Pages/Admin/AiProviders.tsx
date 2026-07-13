// resources/js/Pages/Admin/AiProviders.tsx
//
// ADM-POLICY — /admin/ai-providers. Governs the Zephyrus/Eddy AI provider
// policy: model/provider capability, fallback order, cost limits, PHI
// eligibility, region residency, and surface routing. Every change is a
// versioned proposal that requires an independent approval before it projects
// onto the runtime policy; rollback appends a new version referencing a prior
// one. The dry-run simulator sends ONLY a surface descriptor — never prompt
// text or patient content. Inertia pages are default-exported by convention.
import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { ArrowLeft, CircuitBoard, ShieldAlert, ShieldCheck } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import {
  AdminMetricStrip,
  AdminSectionHeading,
  type AdminMetric,
} from '@/Pages/Admin/components/AdminPrimitives';

interface ProviderProfileDoc {
  profile_id: string;
  display_name: string;
  provider_type: string;
  transport: string;
  entitlement_type: string;
  model: string;
  base_url: string | null;
  region: string | null;
  is_enabled: boolean;
  capabilities: string[];
  safety: { patient_level_context_allowed: boolean };
  limits: { timeout: number | null; max_output_tokens: number | null; monthly_budget_usd: number | null };
  fallback_profile_ids: string[];
}

interface SurfacePolicyDoc {
  surface: string;
  provider_mode: string;
  default_profile_id: string | null;
  fallback_profile_ids: string[];
  allow_cloud: boolean;
  never_send_phi_to_cloud: boolean;
  required_capabilities: string[];
}

interface PolicyDocument {
  profiles: ProviderProfileDoc[];
  surfaces: SurfacePolicyDoc[];
}

interface PendingChange {
  changeRequestUuid: string;
  reason: string;
  requestedAtIso: string;
  expiresAtIso: string;
  author: { id: number; name: string; username: string } | null;
  authoredByCurrentUser: boolean;
  decision: { decision: string; reason: string; decidedAtIso: string } | null;
  changedSections: string[];
  proposalVersionNumber: number | null;
  rollbackToVersionNumber: number | null;
}

interface VersionSummary {
  versionId: number;
  versionNumber: number;
  changeKind: string;
  changeReason: string;
  policySha256: string;
  profileCount: number;
  surfaceCount: number;
  rolledBackToVersionId: number | null;
  effectiveAtIso: string | null;
  createdBy: { id: number; name: string; username: string } | null;
}

interface SimulationResult {
  configured: boolean;
  surface: string;
  provider_mode?: string;
  reason: string;
  blocked_reasons: string[];
  fallback_used?: boolean;
  will_call_paid_provider: boolean;
  selected_profile?: {
    profile_id: string;
    display_name: string;
    provider_type: string;
    transport: string;
    entitlement_type: string;
    model: string;
    is_enabled: boolean;
  } | null;
  phi_posture?: {
    never_send_phi_to_cloud: boolean;
    allow_cloud: boolean;
    cloud_kill_switch_enabled: boolean;
  };
}

interface AiProvidersProps {
  document: PolicyDocument;
  catalog: {
    surfaces: string[];
    modes: string[];
    capabilities: string[];
    entitlements: string[];
    transports: string[];
    surfaceRequirements: Record<string, string[]>;
  };
  readiness: { profile_id: string; provider_type: string; transport: string; entitlement_type: string; state: string; agent_capable: boolean }[];
  currentVersion: { versionNumber: number; changeKind: string; policySha256: string; effectiveAtIso: string | null } | null;
  versions: VersionSummary[];
  drift: boolean;
  guardrails: {
    cloudKillSwitchEnabled: boolean;
    monthlyBudgetUsd: number;
    budgetCutoffThreshold: number;
    phiDetectionEnabled: boolean;
    phiBlockOnDetection: boolean;
  };
  pendingChanges: PendingChange[];
  canManage: boolean;
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

function BooleanBadge({ value, yes, no }: { value: boolean; yes: string; no: string }) {
  return value ? (
    <span className="inline-flex items-center gap-1 text-healthcare-success dark:text-healthcare-success-dark">
      <ShieldCheck className="h-3.5 w-3.5" aria-hidden="true" />
      {yes}
    </span>
  ) : (
    <span className="inline-flex items-center gap-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
      <ShieldAlert className="h-3.5 w-3.5" aria-hidden="true" />
      {no}
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

export default function AiProviders({
  document: policyDocument,
  catalog,
  readiness,
  currentVersion,
  versions,
  drift,
  guardrails,
  pendingChanges,
  canManage,
}: AiProvidersProps) {
  const [working, setWorking] = useState<PolicyDocument>(policyDocument);
  const [dirty, setDirty] = useState(false);
  const [changeReason, setChangeReason] = useState('');
  const [previewNote, setPreviewNote] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [decisionReasons, setDecisionReasons] = useState<Record<string, string>>({});
  const [rollbackReasons, setRollbackReasons] = useState<Record<number, string>>({});
  const [simulationSurface, setSimulationSurface] = useState(catalog.surfaces[0] ?? 'chat');
  const [simulation, setSimulation] = useState<SimulationResult | null>(null);

  const enabledProfiles = policyDocument.profiles.filter((profile) => profile.is_enabled).length;
  const metrics: AdminMetric[] = [
    { label: 'Provider profiles', value: policyDocument.profiles.length, detail: `${enabledProfiles} enabled` },
    { label: 'Routed surfaces', value: policyDocument.surfaces.length, detail: `${catalog.surfaces.length} defined` },
    { label: 'Policy version', value: currentVersion ? `v${currentVersion.versionNumber}` : 'unversioned' },
    { label: 'Cloud egress', value: guardrails.cloudKillSwitchEnabled ? 'Blocked' : 'Permitted', tone: guardrails.cloudKillSwitchEnabled ? 'default' : 'warning', detail: 'EDDY_ALLOW_CLOUD kill switch' },
    { label: 'Monthly cloud budget', value: `$${guardrails.monthlyBudgetUsd.toLocaleString()}`, detail: `cutoff at ${Math.round(guardrails.budgetCutoffThreshold * 100)}%` },
    { label: 'Projection drift', value: drift ? 'Detected' : 'None', tone: drift ? 'critical' : 'default' },
  ];

  const updateProfile = (profileId: string, patch: Partial<ProviderProfileDoc>) => {
    setWorking((current) => ({
      ...current,
      profiles: current.profiles.map((profile) => (profile.profile_id === profileId ? { ...profile, ...patch } : profile)),
    }));
    setDirty(true);
    setPreviewNote(null);
  };

  const updateSurface = (surface: string, patch: Partial<SurfacePolicyDoc>) => {
    setWorking((current) => ({
      ...current,
      surfaces: current.surfaces.map((policy) => (policy.surface === surface ? { ...policy, ...patch } : policy)),
    }));
    setDirty(true);
    setPreviewNote(null);
  };

  const runPreview = async () => {
    setBusy(true);
    setMessage(null);
    try {
      const response = await axios.post('/admin/ai-providers/preview', { document: working });
      const { changedSections, errors, policySha256 } = response.data as { changedSections: string[]; errors: string[]; policySha256: string };
      setPreviewNote(errors.length > 0
        ? `Validation constraints failed: ${errors.join(', ')}`
        : `Changed sections: ${changedSections.join(', ') || 'none'} — policy hash ${policySha256.slice(0, 16)}… passes validation.`);
    } catch (error) {
      setMessage(failureMessage(error));
    } finally {
      setBusy(false);
    }
  };

  const submitProposal = async () => {
    setBusy(true);
    setMessage(null);
    try {
      await axios.post('/admin/ai-providers/changes', { document: working, change_reason: changeReason });
      setDirty(false);
      setChangeReason('');
      setPreviewNote(null);
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
      await axios.post(`/admin/ai-providers/changes/${change.changeRequestUuid}/decision`, {
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
      await axios.post(`/admin/ai-providers/changes/${change.changeRequestUuid}/apply`, {});
      setMessage('Applied the approved AI provider policy as a new version.');
      router.reload();
    } catch (error) {
      setMessage(failureMessage(error));
    } finally {
      setBusy(false);
    }
  };

  const requestRollback = async (version: VersionSummary) => {
    setBusy(true);
    setMessage(null);
    try {
      await axios.post('/admin/ai-providers/changes', {
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

  const simulate = async () => {
    setBusy(true);
    setMessage(null);
    setSimulation(null);
    try {
      const response = await axios.post<SimulationResult>('/admin/ai-providers/simulate', { surface: simulationSurface });
      setSimulation(response.data);
    } catch (error) {
      setMessage(failureMessage(error));
    } finally {
      setBusy(false);
    }
  };

  return (
    <DashboardLayout>
      <Head title="Eddy AI Providers · Zephyrus" />
      <PageContentLayout
        title="Eddy AI Providers"
        subtitle="Governed Zephyrus/Eddy provider policy: capability, fallback order, cost limits, PHI eligibility, region, and surface routing"
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

          {drift ? (
            <div role="alert" className="flex items-start gap-2 rounded-md border border-healthcare-critical/40 bg-healthcare-critical/10 p-3 text-sm text-healthcare-critical dark:text-healthcare-critical-dark">
              <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
              The runtime provider rows no longer match the last applied policy version. Review the ledger and re-apply a governed version.
            </div>
          ) : null}

          <div className="rounded-md border border-healthcare-info/30 bg-healthcare-info/5 p-3 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Governance boundary</p>
            <p className="mt-1">
              Provider API keys live only in the Eddy service environment and are never stored or shown here. Policy changes require an independent approver;
              PHI detection {guardrails.phiDetectionEnabled ? 'is enabled' : 'is disabled'} and {guardrails.phiBlockOnDetection ? 'hard-blocks' : 'does not block'} cloud egress on detection.
            </p>
          </div>

          <section>
            <AdminSectionHeading
              title="Provider profiles"
              description="What each provider can do, where it runs, what it may cost, and whether patient-level context is ever eligible"
            />
            <div className="overflow-x-auto rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <table className="min-w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark">
                <thead>
                  <tr>
                    <th className={headClass}>Profile</th>
                    <th className={headClass}>Provider / transport</th>
                    <th className={headClass}>Model</th>
                    <th className={headClass}>Entitlement</th>
                    <th className={headClass}>Region</th>
                    <th className={headClass}>Monthly budget</th>
                    <th className={headClass}>PHI eligibility</th>
                    <th className={headClass}>Enabled</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                  {working.profiles.length === 0 ? (
                    <tr><td colSpan={8} className="px-4 py-8 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No provider profiles are configured yet. Provision profiles through deployment seeding, then govern them here.</td></tr>
                  ) : working.profiles.map((profile) => (
                    <tr key={profile.profile_id}>
                      <td className={cellClass}>
                        <span className="tabular-nums">{profile.profile_id}</span>
                        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{profile.display_name}</p>
                      </td>
                      <td className={cellClass}>{profile.provider_type} / {profile.transport}</td>
                      <td className={cellClass}>
                        {canManage ? (
                          <input
                            className={`${inputClass} w-52`}
                            aria-label={`Model for ${profile.profile_id}`}
                            value={profile.model}
                            onChange={(event) => updateProfile(profile.profile_id, { model: event.target.value })}
                          />
                        ) : (
                          <span className="tabular-nums">{profile.model || '—'}</span>
                        )}
                      </td>
                      <td className={cellClass}>{profile.entitlement_type}</td>
                      <td className={cellClass}>
                        {canManage ? (
                          <input
                            className={`${inputClass} w-28`}
                            aria-label={`Region for ${profile.profile_id}`}
                            placeholder="us-east-1"
                            value={profile.region ?? ''}
                            onChange={(event) => updateProfile(profile.profile_id, { region: event.target.value === '' ? null : event.target.value })}
                          />
                        ) : (
                          profile.region ?? 'unpinned'
                        )}
                      </td>
                      <td className={`${cellClass} tabular-nums`}>
                        {canManage ? (
                          <input
                            type="number"
                            className={`${inputClass} w-28 tabular-nums`}
                            aria-label={`Monthly budget USD for ${profile.profile_id}`}
                            value={profile.limits.monthly_budget_usd ?? ''}
                            onChange={(event) => updateProfile(profile.profile_id, {
                              limits: { ...profile.limits, monthly_budget_usd: event.target.value === '' ? null : Number(event.target.value) },
                            })}
                          />
                        ) : (
                          profile.limits.monthly_budget_usd === null ? 'unlimited' : `$${profile.limits.monthly_budget_usd.toLocaleString()}`
                        )}
                      </td>
                      <td className={cellClass}>
                        {canManage ? (
                          <label className="inline-flex items-center gap-1.5 text-sm">
                            <input
                              type="checkbox"
                              checked={profile.safety.patient_level_context_allowed}
                              onChange={(event) => updateProfile(profile.profile_id, { safety: { patient_level_context_allowed: event.target.checked } })}
                            />
                            patient-level allowed
                          </label>
                        ) : (
                          <BooleanBadge value={profile.safety.patient_level_context_allowed} yes="patient-level allowed" no="aggregate only" />
                        )}
                      </td>
                      <td className={cellClass}>
                        {canManage ? (
                          <label className="inline-flex items-center gap-1.5 text-sm">
                            <input
                              type="checkbox"
                              checked={profile.is_enabled}
                              onChange={(event) => updateProfile(profile.profile_id, { is_enabled: event.target.checked })}
                            />
                            enabled
                          </label>
                        ) : (
                          profile.is_enabled ? 'enabled' : 'disabled'
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <section>
            <AdminSectionHeading
              title="Surface routing"
              description="Which profile each Zephyrus surface uses, its fallback order, and its PHI egress posture"
            />
            <div className="overflow-x-auto rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <table className="min-w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark">
                <thead>
                  <tr>
                    <th className={headClass}>Surface</th>
                    <th className={headClass}>Mode</th>
                    <th className={headClass}>Default profile</th>
                    <th className={headClass}>Fallback order</th>
                    <th className={headClass}>Cloud allowed</th>
                    <th className={headClass}>PHI guard</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                  {working.surfaces.length === 0 ? (
                    <tr><td colSpan={6} className="px-4 py-8 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No surface policies are configured yet.</td></tr>
                  ) : working.surfaces.map((surface) => (
                    <tr key={surface.surface}>
                      <td className={`${cellClass} font-medium`}>{surface.surface}</td>
                      <td className={cellClass}>
                        {canManage ? (
                          <select
                            className={inputClass}
                            aria-label={`Provider mode for ${surface.surface}`}
                            value={surface.provider_mode}
                            onChange={(event) => updateSurface(surface.surface, { provider_mode: event.target.value })}
                          >
                            {catalog.modes.map((mode) => <option key={mode} value={mode}>{mode}</option>)}
                          </select>
                        ) : (
                          surface.provider_mode
                        )}
                      </td>
                      <td className={cellClass}>
                        {canManage ? (
                          <select
                            className={inputClass}
                            aria-label={`Default profile for ${surface.surface}`}
                            value={surface.default_profile_id ?? ''}
                            onChange={(event) => updateSurface(surface.surface, { default_profile_id: event.target.value === '' ? null : event.target.value })}
                          >
                            <option value="">None</option>
                            {working.profiles.map((profile) => <option key={profile.profile_id} value={profile.profile_id}>{profile.profile_id}</option>)}
                          </select>
                        ) : (
                          <span className="tabular-nums">{surface.default_profile_id ?? 'None'}</span>
                        )}
                      </td>
                      <td className={`${cellClass} tabular-nums`}>{surface.fallback_profile_ids.join(' → ') || 'none'}</td>
                      <td className={cellClass}>
                        {canManage ? (
                          <label className="inline-flex items-center gap-1.5 text-sm">
                            <input
                              type="checkbox"
                              checked={surface.allow_cloud}
                              onChange={(event) => updateSurface(surface.surface, { allow_cloud: event.target.checked })}
                            />
                            allow cloud
                          </label>
                        ) : (
                          surface.allow_cloud ? 'allowed' : 'blocked'
                        )}
                      </td>
                      <td className={cellClass}>
                        <BooleanBadge value={surface.never_send_phi_to_cloud} yes="never send PHI to cloud" no="PHI guard relaxed" />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {canManage ? (
              <div className="mt-3 rounded-md border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                <label className="block text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Change reason (required, 10–500 characters)
                  <textarea className={`${inputClass} mt-1 w-full`} rows={2} value={changeReason} onChange={(event) => setChangeReason(event.target.value)} />
                </label>
                {previewNote ? (
                  <p role="status" className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{previewNote}</p>
                ) : null}
                <div className="mt-2 flex gap-2">
                  <button type="button" className={subtleButtonClass} disabled={busy || !dirty} onClick={() => void runPreview()}>Preview changes</button>
                  <button type="button" className={buttonClass} disabled={busy || !dirty || changeReason.trim().length < 10} onClick={() => void submitProposal()}>
                    Submit for approval
                  </button>
                  <button type="button" className={subtleButtonClass} disabled={busy || !dirty} onClick={() => { setWorking(policyDocument); setDirty(false); setPreviewNote(null); }}>
                    Discard edits
                  </button>
                </div>
              </div>
            ) : null}
          </section>

          <section>
            <AdminSectionHeading
              title="Dry-run route simulation"
              description="Resolves which provider/model the effective policy would route a surface to, and why. No prompt or patient content is sent or stored."
            />
            <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <div className="flex flex-wrap items-center gap-2">
                <label className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark" htmlFor="simulate-surface">Surface</label>
                <select id="simulate-surface" className={inputClass} value={simulationSurface} onChange={(event) => setSimulationSurface(event.target.value)}>
                  {catalog.surfaces.map((surface) => <option key={surface} value={surface}>{surface}</option>)}
                </select>
                <button type="button" className={buttonClass} disabled={busy} onClick={() => void simulate()}>
                  <span className="inline-flex items-center gap-1.5">
                    <CircuitBoard className="h-4 w-4" aria-hidden="true" />
                    Simulate route
                  </span>
                </button>
              </div>
              {simulation ? (
                <dl className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                  <div>
                    <dt className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Resolved profile</dt>
                    <dd className="mt-1 text-sm tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {simulation.selected_profile ? `${simulation.selected_profile.profile_id} (${simulation.selected_profile.model || 'no model'})` : 'None — routing blocked'}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Why</dt>
                    <dd className="mt-1 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {simulation.reason}{simulation.fallback_used ? ' (fallback used)' : ''}
                      {simulation.blocked_reasons.length > 0 ? ` — blocked: ${simulation.blocked_reasons.join(', ')}` : ''}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Paid provider call</dt>
                    <dd className="mt-1 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{simulation.will_call_paid_provider ? 'Yes — cloud spend applies' : 'No — zero API cost'}</dd>
                  </div>
                  <div>
                    <dt className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">PHI posture</dt>
                    <dd className="mt-1 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {simulation.phi_posture?.never_send_phi_to_cloud ? 'PHI never leaves local' : 'PHI cloud guard relaxed'}
                      {simulation.phi_posture?.cloud_kill_switch_enabled ? ' · cloud kill switch on' : ''}
                    </dd>
                  </div>
                </dl>
              ) : null}
            </div>
          </section>

          <section>
            <AdminSectionHeading
              title="Pending governed changes"
              description="Request → independent decision → execution; the author can never approve their own change"
            />
            <div className="overflow-x-auto rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <table className="min-w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark">
                <thead>
                  <tr>
                    <th className={headClass}>Sections</th>
                    <th className={headClass}>Reason</th>
                    <th className={headClass}>Author</th>
                    <th className={headClass}>State</th>
                    <th className={headClass}><span className="sr-only">Actions</span></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                  {pendingChanges.length === 0 ? (
                    <tr><td colSpan={5} className="px-4 py-8 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No governed AI policy changes are awaiting action.</td></tr>
                  ) : pendingChanges.map((change) => (
                    <tr key={change.changeRequestUuid}>
                      <td className={cellClass}>
                        {change.changedSections.join(', ') || 'policy'}
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
                              aria-label={`Decision reason for change ${change.changeRequestUuid}`}
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

          <section>
            <AdminSectionHeading
              title="Policy version ledger"
              description="Append-only history; rollback requests a NEW version referencing the selected prior one"
            />
            <div className="overflow-x-auto rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <table className="min-w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark">
                <thead>
                  <tr>
                    <th className={headClass}>Version</th>
                    <th className={headClass}>Kind</th>
                    <th className={headClass}>Profiles / surfaces</th>
                    <th className={headClass}>Reason</th>
                    <th className={headClass}>Effective</th>
                    <th className={headClass}><span className="sr-only">Rollback</span></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                  {versions.length === 0 ? (
                    <tr><td colSpan={6} className="px-4 py-8 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No policy versions have been recorded yet.</td></tr>
                  ) : versions.map((version) => (
                    <tr key={version.versionId}>
                      <td className={`${cellClass} tabular-nums`}>v{version.versionNumber}</td>
                      <td className={cellClass}>{version.changeKind.replaceAll('_', ' ')}</td>
                      <td className={`${cellClass} tabular-nums`}>{version.profileCount} / {version.surfaceCount}</td>
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

          <section>
            <AdminSectionHeading title="Provider readiness" description="Secret-safe readiness from the Eddy policy service; live key health belongs to the Eddy service itself" />
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
              {readiness.length === 0 ? (
                <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No provider profiles to report.</p>
              ) : readiness.map((entry) => (
                <div key={entry.profile_id} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                  <p className="text-sm font-semibold tabular-nums text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{entry.profile_id}</p>
                  <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {entry.provider_type} · {entry.transport} · {entry.entitlement_type}
                  </p>
                  <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    state: {entry.state.replaceAll('_', ' ')} · {entry.agent_capable ? 'agent-capable' : 'chat only'}
                  </p>
                </div>
              ))}
            </div>
          </section>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
