import { FormEvent, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import {
 AlertTriangle,
 CheckCircle2,
 Download,
 FileCheck2,
 Plus,
 ShieldCheck,
} from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { AdminSectionHeading, OutcomeBadge } from '@/Pages/Admin/components/AdminPrimitives';

interface Reviewer {
 id: number;
 name: string;
 username: string;
 role?: string;
}

interface CampaignSummary {
 campaignUuid: string;
 title: string;
 status: 'open' | 'completed' | 'cancelled';
 dueAt: string;
 openedAt: string;
 completedAt: string | null;
 cancelledAt?: string | null;
 cancellationReason?: string | null;
 itemCount: number;
 decidedCount: number;
 revokeCount: number;
 primaryReviewer: Reviewer;
 alternateReviewer: Reviewer;
 snapshotSha256: string;
 evidenceSha256: string | null;
}

interface EntitlementSnapshot {
 subject: {
 user_id: number;
 username: string;
 display_name: string;
 is_active: boolean;
 is_protected: boolean;
 must_change_password: boolean;
 };
 scalar_role: string;
 spatie_roles: string[];
 direct_permissions: string[];
 effective_roles: string[];
 effective_capabilities: string[];
 explicit_scopes: Array<{
 scope_id: number;
 organization_key: string | null;
 facility_key: string | null;
 valid_until: string | null;
 }>;
 workforce_assignments: Array<{
 staff_assignment_id: number;
 facility_key: string;
 role_code: string;
 }>;
 external_identity_providers: string[];
 active_api_token_count: number;
 last_successful_authentication_at: string | null;
}

interface ReviewItem {
 itemUuid: string;
 subject: Reviewer & { is_active: boolean; is_protected: boolean };
 reviewer: Reviewer;
 snapshot: EntitlementSnapshot;
 snapshotSha256: string;
 riskFlags: string[];
 decision: null | {
 decisionUuid: string;
 value: 'retain' | 'revoke';
 reasonCode: string;
 rationale: string;
 decidedAt: string;
 decidedBy: Reviewer;
 remediated: boolean;
 };
 canDecide: boolean;
}

interface CampaignDetail extends CampaignSummary {
 reviewPeriodStart: string;
 reviewPeriodEnd: string;
 snapshotAt: string;
 items: ReviewItem[];
}

interface AccessReviewsProps {
 campaigns: CampaignSummary[];
 selectedCampaign: CampaignDetail | null;
 reviewers: Reviewer[];
 canManage: boolean;
}

function formatTime(value: string | null): string {
 if (!value) return '—';
 return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function errorMessage(error: unknown): string {
 if (isAxiosError(error)) {
 if (error.response?.status === 428) {
 window.location.href = '/confirm-password';
 return 'Recent authentication is required. Redirecting…';
 }
 const payload = error.response?.data as {
 message?: string;
 error?: { message?: string };
 errors?: Record<string, string[]>;
 } | undefined;
 const validation = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
 return validation ?? payload?.error?.message ?? payload?.message ?? 'The operation could not be completed.';
 }
 return error instanceof Error ? error.message : 'The operation could not be completed.';
}

function DecisionForm({ campaignUuid, item }: { campaignUuid: string; item: ReviewItem }) {
 const [decision, setDecision] = useState<'retain' | 'revoke'>('retain');
 const [reasonCode, setReasonCode] = useState('business_need_confirmed');
 const [rationale, setRationale] = useState('');
 const [busy, setBusy] = useState(false);
 const [error, setError] = useState('');

 const reasons = decision === 'retain'
 ? [
 ['business_need_confirmed', 'Business need confirmed'],
 ['approved_policy_exception', 'Approved policy exception'],
 ]
 : [
 ['role_or_responsibility_changed', 'Role or responsibility changed'],
 ['employment_status_changed', 'Employment status changed'],
 ['duplicate_or_excess_access', 'Duplicate or excess access'],
 ['policy_noncompliance', 'Policy noncompliance'],
 ];

 const changeDecision = (next: 'retain' | 'revoke') => {
 setDecision(next);
 setReasonCode(next === 'retain' ? 'business_need_confirmed' : 'role_or_responsibility_changed');
 };

 const submit = async (event: FormEvent) => {
 event.preventDefault();
 setBusy(true);
 setError('');
 try {
 await axios.post(`/admin/access-reviews/${campaignUuid}/items/${item.itemUuid}/decision`, {
 decision,
 reason_code: reasonCode,
 rationale,
 });
 router.reload({ only: ['campaigns', 'selectedCampaign'] });
 } catch (caught) {
 setError(errorMessage(caught));
 } finally {
 setBusy(false);
 }
 };

 return (
 <form onSubmit={submit} className="mt-3 space-y-2 rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
 <p className="text-xs font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
 Your decision is immutable and requires recent step-up authentication.
 </p>
 <div className="grid gap-2 md:grid-cols-2">
 <label className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
 Decision
 <select
 value={decision}
 onChange={(event) => changeDecision(event.target.value as 'retain' | 'revoke')}
 className="mt-1 w-full rounded-md border-healthcare-border bg-healthcare-surface text-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
 >
 <option value="retain">Retain access</option>
 <option value="revoke">Revoke reviewed access now</option>
 </select>
 </label>
 <label className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
 Reason
 <select
 value={reasonCode}
 onChange={(event) => setReasonCode(event.target.value)}
 className="mt-1 w-full rounded-md border-healthcare-border bg-healthcare-surface text-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
 >
 {reasons.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
 </select>
 </label>
 </div>
 <label className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
 Certification rationale (do not include PHI)
 <textarea
 required
 minLength={12}
 maxLength={1000}
 value={rationale}
 onChange={(event) => setRationale(event.target.value)}
 rows={2}
 className="mt-1 w-full rounded-md border-healthcare-border bg-healthcare-surface text-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
 />
 </label>
 {decision === 'revoke' ? (
 <p className="flex items-start gap-1.5 text-xs text-healthcare-warning dark:text-healthcare-warning-dark">
 <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
 This immediately removes reviewed roles, direct permissions, explicit scopes, sessions, and API tokens. Protected and last-administrator accounts fail closed.
 </p>
 ) : null}
 {error ? <p role="alert" className="text-xs text-healthcare-critical dark:text-healthcare-critical-dark">{error}</p> : null}
 <button
 type="submit"
 disabled={busy || rationale.trim().length < 12}
 className="rounded-md bg-healthcare-info px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
 >
 {busy ? 'Recording…' : 'Record immutable decision'}
 </button>
 </form>
 );
}

function CreateCampaign({ reviewers, onCreated }: { reviewers: Reviewer[]; onCreated: (uuid: string) => void }) {
 const today = new Date();
 const quarterStartMonth = Math.floor(today.getMonth() / 3) * 3;
 const quarterStart = new Date(today.getFullYear(), quarterStartMonth, 1).toISOString().slice(0, 10);
 const quarterEnd = new Date(today.getFullYear(), quarterStartMonth + 3, 0).toISOString().slice(0, 10);
 const defaultDue = new Date(today.getTime() + 30 * 86400000).toISOString().slice(0, 16);
 const [form, setForm] = useState({
 title: `${today.getFullYear()} Q${Math.floor(today.getMonth() / 3) + 1} privileged access review`,
 review_period_start: quarterStart,
 review_period_end: quarterEnd,
 due_at: defaultDue,
 primary_reviewer_user_id: reviewers[0]?.id ?? 0,
 alternate_reviewer_user_id: reviewers[1]?.id ?? 0,
 });
 const [busy, setBusy] = useState(false);
 const [error, setError] = useState('');

 const submit = async (event: FormEvent) => {
 event.preventDefault();
 setBusy(true);
 setError('');
 try {
 const response = await axios.post<{ campaign_uuid: string }>('/admin/access-reviews', form);
 onCreated(response.data.campaign_uuid);
 } catch (caught) {
 setError(errorMessage(caught));
 } finally {
 setBusy(false);
 }
 };

 if (reviewers.length < 2) {
 return (
 <div className="rounded-md border border-healthcare-warning/40 bg-healthcare-warning/5 p-3 text-sm text-healthcare-warning dark:text-healthcare-warning-dark">
 At least two active access-review managers are required for independent certification.
 </div>
 );
 }

 return (
 <form onSubmit={submit} className="grid gap-3 rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark lg:grid-cols-2">
 <label className="text-sm lg:col-span-2">
 Campaign title
 <input required minLength={6} maxLength={160} value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} className="mt-1 w-full rounded-md border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark" />
 </label>
 <label className="text-sm">Period start<input type="date" required value={form.review_period_start} onChange={(event) => setForm({ ...form, review_period_start: event.target.value })} className="mt-1 w-full rounded-md border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark" /></label>
 <label className="text-sm">Period end<input type="date" required value={form.review_period_end} onChange={(event) => setForm({ ...form, review_period_end: event.target.value })} className="mt-1 w-full rounded-md border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark" /></label>
 <label className="text-sm">Due date and time<input type="datetime-local" required value={form.due_at} onChange={(event) => setForm({ ...form, due_at: event.target.value })} className="mt-1 w-full rounded-md border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark" /></label>
 <div />
 <label className="text-sm">
 Primary reviewer
 <select required value={form.primary_reviewer_user_id} onChange={(event) => setForm({ ...form, primary_reviewer_user_id: Number(event.target.value) })} className="mt-1 w-full rounded-md border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
 {reviewers.map((reviewer) => <option key={reviewer.id} value={reviewer.id}>{reviewer.name} ({reviewer.username})</option>)}
 </select>
 </label>
 <label className="text-sm">
 Alternate reviewer
 <select required value={form.alternate_reviewer_user_id} onChange={(event) => setForm({ ...form, alternate_reviewer_user_id: Number(event.target.value) })} className="mt-1 w-full rounded-md border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
 {reviewers.map((reviewer) => <option key={reviewer.id} value={reviewer.id}>{reviewer.name} ({reviewer.username})</option>)}
 </select>
 </label>
 <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark lg:col-span-2">
 Opening the campaign freezes every active privileged user’s role, capability, scope, identity-provider, token, and authentication evidence. The alternate reviews the primary reviewer’s own access.
 </p>
 {error ? <p role="alert" className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark lg:col-span-2">{error}</p> : null}
 <button type="submit" disabled={busy || form.primary_reviewer_user_id === form.alternate_reviewer_user_id} className="inline-flex w-fit items-center gap-2 rounded-md bg-healthcare-info px-3 py-2 text-sm font-medium text-white disabled:opacity-50 lg:col-span-2">
 <Plus className="h-4 w-4" aria-hidden="true" />{busy ? 'Freezing access snapshot…' : 'Open quarterly campaign'}
 </button>
 </form>
 );
}

export default function AccessReviews({ campaigns, selectedCampaign, reviewers, canManage }: AccessReviewsProps) {
 const [showCreate, setShowCreate] = useState(false);
 const [busy, setBusy] = useState(false);
 const [error, setError] = useState('');
 const [cancellationReason, setCancellationReason] = useState('');
 const completed = selectedCampaign?.status === 'completed';
 const readyToComplete = useMemo(() => selectedCampaign !== null
 && selectedCampaign.itemCount > 0
 && selectedCampaign.decidedCount === selectedCampaign.itemCount, [selectedCampaign]);

 const selectCampaign = (uuid: string) => router.visit(`/admin/access-reviews?campaign=${encodeURIComponent(uuid)}`, { preserveScroll: true });
 const completeCampaign = async () => {
 if (!selectedCampaign) return;
 setBusy(true);
 setError('');
 try {
 await axios.post(`/admin/access-reviews/${selectedCampaign.campaignUuid}/complete`);
 router.reload({ only: ['campaigns', 'selectedCampaign'] });
 } catch (caught) {
 setError(errorMessage(caught));
 } finally {
 setBusy(false);
 }
 };
 const cancelCampaign = async () => {
 if (!selectedCampaign) return;
 setBusy(true);
 setError('');
 try {
 await axios.post(`/admin/access-reviews/${selectedCampaign.campaignUuid}/cancel`, {
 reason: cancellationReason,
 });
 router.reload({ only: ['campaigns', 'selectedCampaign'] });
 } catch (caught) {
 setError(errorMessage(caught));
 } finally {
 setBusy(false);
 }
 };

 return (
 <DashboardLayout>
 <Head title="Quarterly Access Reviews" />
 <PageContentLayout title="Quarterly Access Reviews" subtitle="Independent certification, immediate remediation, and content-addressed evidence" headerContent={null}>
 <div className="space-y-5">
 <div className="flex flex-wrap items-center justify-between gap-3">
 <p className="max-w-3xl text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
 Review privileged authorization as it existed at campaign opening. Every decision is append-only; self-certification and unremediated revocation are blocked in the database and service layer.
 </p>
 {canManage ? <button type="button" onClick={() => setShowCreate((value) => !value)} className="inline-flex items-center gap-2 rounded-md border border-healthcare-border px-3 py-2 text-sm font-medium dark:border-healthcare-border-dark"><Plus className="h-4 w-4" />New campaign</button> : null}
 </div>

 {showCreate ? <CreateCampaign reviewers={reviewers} onCreated={(uuid) => selectCampaign(uuid)} /> : null}

 <div className="grid gap-5 xl:grid-cols-[20rem_minmax(0,1fr)]">
 <aside>
 <AdminSectionHeading title="Campaigns" description={`${campaigns.length} access review${campaigns.length === 1 ? '' : 's'}`} />
 <div className="space-y-2">
 {campaigns.length === 0 ? <div className="rounded-md border border-dashed border-healthcare-border p-6 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark dark:border-healthcare-border-dark">No campaign has been opened.</div> : campaigns.map((campaign) => (
 <button key={campaign.campaignUuid} type="button" onClick={() => selectCampaign(campaign.campaignUuid)} className={`w-full rounded-md border p-3 text-left ${selectedCampaign?.campaignUuid === campaign.campaignUuid ? 'border-healthcare-info bg-healthcare-info/5' : 'border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark'}`}>
 <span className="flex items-start justify-between gap-2"><span className="text-sm font-medium">{campaign.title}</span><OutcomeBadge outcome={campaign.status} /></span>
 <span className="mt-2 block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{campaign.decidedCount}/{campaign.itemCount} decided · due {formatTime(campaign.dueAt)}</span>
 </button>
 ))}
 </div>
 </aside>

 <main>
 {!selectedCampaign ? (
 <div className="rounded-md border border-dashed border-healthcare-border p-12 text-center dark:border-healthcare-border-dark"><ShieldCheck className="mx-auto h-7 w-7 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" /><p className="mt-2 text-sm">Open a campaign to freeze the current privileged-access population.</p></div>
 ) : (
 <div className="space-y-4">
 <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
 <div className="flex flex-wrap items-start justify-between gap-3">
 <div><h2 className="text-lg font-semibold">{selectedCampaign.title}</h2><p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Period {selectedCampaign.reviewPeriodStart} through {selectedCampaign.reviewPeriodEnd} · snapshot {formatTime(selectedCampaign.snapshotAt)}</p></div>
 <OutcomeBadge outcome={selectedCampaign.status} />
 </div>
 <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
 <div><dt className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Progress</dt><dd className="font-medium">{selectedCampaign.decidedCount}/{selectedCampaign.itemCount}</dd></div>
 <div><dt className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Revoke decisions</dt><dd className="font-medium">{selectedCampaign.revokeCount}</dd></div>
 <div><dt className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Primary reviewer</dt><dd className="font-medium">{selectedCampaign.primaryReviewer.name}</dd></div>
 <div><dt className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Alternate reviewer</dt><dd className="font-medium">{selectedCampaign.alternateReviewer.name}</dd></div>
 </dl>
 <p className="mt-3 break-all tabular-nums text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Snapshot SHA-256: {selectedCampaign.snapshotSha256}</p>
 {completed ? (
 <div className="mt-3 flex flex-wrap items-center gap-2">
 <a href={`/admin/access-reviews/${selectedCampaign.campaignUuid}/evidence.json`} className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-2.5 py-1.5 text-sm dark:border-healthcare-border-dark"><Download className="h-4 w-4" />JSON evidence</a>
 <a href={`/admin/access-reviews/${selectedCampaign.campaignUuid}/evidence.csv`} className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-2.5 py-1.5 text-sm dark:border-healthcare-border-dark"><Download className="h-4 w-4" />CSV evidence</a>
 <span className="break-all tabular-nums text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Canonical evidence SHA-256: {selectedCampaign.evidenceSha256}</span>
 </div>
 ) : selectedCampaign.status === 'open' && canManage ? (
 <div className="mt-3 space-y-3">
 <button type="button" onClick={completeCampaign} disabled={!readyToComplete || busy} className="inline-flex items-center gap-2 rounded-md bg-healthcare-success px-3 py-2 text-sm font-medium text-white disabled:opacity-50"><FileCheck2 className="h-4 w-4" />{busy ? 'Sealing evidence…' : 'Complete and seal evidence'}</button>
 {!readyToComplete ? <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">All items must be decided and every revocation remediated before completion.</p> : null}
 <div className="max-w-2xl border-t border-healthcare-border pt-3 dark:border-healthcare-border-dark">
 <label className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
 Cancellation reason (only when the campaign cannot be completed)
 <input value={cancellationReason} onChange={(event) => setCancellationReason(event.target.value)} maxLength={500} className="mt-1 w-full rounded-md border-healthcare-border bg-healthcare-surface text-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark" />
 </label>
 <button type="button" onClick={cancelCampaign} disabled={busy || cancellationReason.trim().length < 12} className="mt-2 rounded-md border border-healthcare-critical px-3 py-1.5 text-sm font-medium text-healthcare-critical disabled:opacity-50 dark:text-healthcare-critical-dark">Cancel campaign</button>
 <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Cancellation is step-up protected, audited, and immutable. It releases the quarter so a replacement campaign can be opened.</p>
 </div>
 </div>
 ) : selectedCampaign.status === 'cancelled' ? <p className="mt-3 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Cancelled {formatTime(selectedCampaign.cancelledAt ?? null)} — {selectedCampaign.cancellationReason}</p> : null}
 {error ? <p role="alert" className="mt-2 text-sm text-healthcare-critical dark:text-healthcare-critical-dark">{error}</p> : null}
 </section>

 <section>
 <AdminSectionHeading title="Frozen entitlement population" description="Source roles, direct permissions, effective capabilities, scopes, identity providers, and token state" />
 <div className="space-y-3">
 {selectedCampaign.items.map((item) => (
 <article key={item.itemUuid} className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
 <div className="flex flex-wrap items-start justify-between gap-3">
 <div><h3 className="font-medium">{item.subject.name} <span className="font-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">({item.subject.username})</span></h3><p className="text-xs text-healthcare-text-secondary">Assigned reviewer: {item.reviewer.name} · snapshot role: {item.snapshot.scalar_role}</p></div>
 {item.decision ? <span className="inline-flex items-center gap-1"><CheckCircle2 className="h-4 w-4 text-healthcare-success" /><OutcomeBadge outcome={item.decision.value} /></span> : <OutcomeBadge outcome="pending" />}
 </div>
 {item.riskFlags.length > 0 ? <div className="mt-2 flex flex-wrap gap-1">{item.riskFlags.map((flag) => <span key={flag} className="rounded bg-healthcare-warning/10 px-1.5 py-0.5 text-xs text-healthcare-warning dark:text-healthcare-warning-dark">{flag.replaceAll('_', ' ')}</span>)}</div> : null}
 <div className="mt-3 grid gap-2 text-xs md:grid-cols-3">
 <div><span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Roles</span><p>{item.snapshot.effective_roles.join(', ') || 'None'}</p></div>
 <div><span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Capabilities</span><p>{item.snapshot.effective_capabilities.length}</p></div>
 <div><span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Explicit scopes</span><p>{item.snapshot.explicit_scopes.length}</p></div>
 </div>
 <details className="mt-2 text-xs"><summary className="cursor-pointer font-medium text-healthcare-info">Inspect frozen entitlement evidence</summary><pre className="mt-2 max-h-80 overflow-auto rounded bg-healthcare-surface-secondary p-3 text-xs dark:bg-healthcare-surface-hover-dark">{JSON.stringify(item.snapshot, null, 2)}</pre><p className="mt-1 break-all tabular-nums text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">SHA-256: {item.snapshotSha256}</p></details>
 {item.decision ? <div className="mt-3 rounded-md bg-healthcare-surface-secondary p-3 text-sm dark:bg-healthcare-surface-hover-dark"><p><strong>{item.decision.value === 'retain' ? 'Retained' : 'Revoked'}</strong> by {item.decision.decidedBy.name} at {formatTime(item.decision.decidedAt)}</p><p className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.decision.reasonCode.replaceAll('_', ' ')} — {item.decision.rationale}</p>{item.decision.value === 'revoke' ? <p className="mt-1 text-xs">Remediation evidence: {item.decision.remediated ? 'recorded' : 'missing'}</p> : null}</div> : item.canDecide ? <DecisionForm campaignUuid={selectedCampaign.campaignUuid} item={item} /> : <p className="mt-3 text-xs text-healthcare-text-secondary">Awaiting the independently assigned reviewer.</p>}
 </article>
 ))}
 </div>
 </section>
 </div>
 )}
 </main>
 </div>
 </div>
 </PageContentLayout>
 </DashboardLayout>
 );
}
