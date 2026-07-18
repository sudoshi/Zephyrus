import { router } from '@inertiajs/react';
import axios from 'axios';
import { ArchiveRestore, LockKeyhole, ShieldCheck, Trash2, UnlockKeyhole, X } from 'lucide-react';
import { useState, type FormEvent } from 'react';

export interface ClinicalPayloadObjectItem {
 id: number;
 uuid: string;
 kind: string;
 classification: string;
 status: string;
 legalHold: boolean;
 retentionPolicy: string;
 retainUntil: string;
 createdAt: string;
 lastVerifiedAt: string | null;
 deletionBlockers: string[];
}

export interface ClinicalPayloadQuarantineItem {
 id: number;
 uuid: string;
 objectId: number;
 objectUuid: string;
 objectStatus: string;
 legalHold: boolean;
 category: string;
 reasonCode: string;
 detectedBy: string;
 openedAt: string;
 deletionBlockers: string[];
}

export interface ClinicalPayloadGovernedChange {
 uuid: string;
 action: string;
 subjectType: string;
 subjectId: string;
 status: string;
 operation: string;
 objectId: number | null;
 quarantineId: number | null;
 authorUserId: number;
 decidedByUserId: number | null;
 requestedAt: string;
 expiresAt: string;
 decidedAt: string | null;
 executedAt: string | null;
}

type RequestKind = 'apply_hold' | 'release_hold' | 'purge_object' | 'recover_integrity' | 'release_quarantine' | 'purge_quarantine';

interface PendingRequest {
 kind: RequestKind;
 targetId: number;
 targetUuid: string;
}

const secondaryButton = 'inline-flex min-h-9 items-center gap-1.5 rounded-md border border-healthcare-border px-2.5 py-1.5 text-xs font-semibold text-healthcare-text-primary hover:bg-healthcare-hover disabled:cursor-not-allowed disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark';
const destructiveButton = 'inline-flex min-h-9 items-center gap-1.5 rounded-md border border-healthcare-critical/40 px-2.5 py-1.5 text-xs font-semibold text-healthcare-critical hover:bg-healthcare-critical/5 disabled:cursor-not-allowed disabled:opacity-50';
const inputClass = 'w-full rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark';

function time(value: string | null): string {
 return value ? new Date(value).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : 'Not recorded';
}

function readable(value: string): string {
 return value.replaceAll('_', ' ');
}

function requestLabel(kind: RequestKind): string {
 return {
 apply_hold: 'Apply legal hold',
 release_hold: 'Release legal hold',
 purge_object: 'Request exceptional purge',
 recover_integrity: 'Verify restored object',
 release_quarantine: 'Request quarantine release',
 purge_quarantine: 'Request terminal purge',
 }[kind];
}

function apiError(error: unknown): string {
 return axios.isAxiosError(error) && typeof error.response?.data?.error?.message === 'string'
 ? error.response.data.error.message
 : 'The governed clinical-payload operation was not recorded.';
}

function redirectForStepUp(error: unknown): boolean {
 if (!axios.isAxiosError(error) || error.response?.status !== 428) return false;
 const url = error.response.data?.error?.reauthentication_url;
 if (typeof url !== 'string') return false;
 router.visit(url);
 return true;
}

export default function ClinicalPayloadGovernance({
 sourceId,
 actionable,
 objects,
 quarantines,
 changes,
 currentUserId,
 canManage,
 canOperate,
 canApprove,
}: {
 sourceId: number | null;
 actionable: boolean;
 objects: ClinicalPayloadObjectItem[];
 quarantines: ClinicalPayloadQuarantineItem[];
 changes: ClinicalPayloadGovernedChange[];
 currentUserId: number | null;
 canManage: boolean;
 canOperate: boolean;
 canApprove: boolean;
}) {
 const [pending, setPending] = useState<PendingRequest | null>(null);
 const [reason, setReason] = useState('');
 const [holdReasonCode, setHoldReasonCode] = useState('legal_or_investigation_hold');
 const [decisionReasons, setDecisionReasons] = useState<Record<string, string>>({});
 const [busy, setBusy] = useState<string | null>(null);
 const [error, setError] = useState<string | null>(null);
 const refresh = () => router.reload({ only: ['snapshot'] });

 const openRequest = (kind: RequestKind, targetId: number, targetUuid: string) => {
 setPending({ kind, targetId, targetUuid });
 setReason('');
 setError(null);
 };

 const submitRequest = async (event: FormEvent) => {
 event.preventDefault();
 if (!pending || sourceId === null) return;
 const isQuarantine = pending.kind.endsWith('quarantine');
 const target = isQuarantine ? `payload-quarantines/${pending.targetId}` : `payload-objects/${pending.targetId}`;
 const suffix = pending.kind === 'apply_hold' || pending.kind === 'release_hold'
 ? 'hold-requests'
 : pending.kind === 'purge_object' || pending.kind === 'purge_quarantine'
 ? 'purge-requests'
 : pending.kind === 'recover_integrity'
 ? 'integrity-recovery-requests'
 : 'release-requests';
 setBusy('request');
 setError(null);
 try {
 await axios.post(`/api/admin/integrations/sources/${sourceId}/${target}/${suffix}`, {
 reason: reason.trim(),
 ...(pending.kind === 'apply_hold' || pending.kind === 'release_hold' ? {
 operation: pending.kind === 'apply_hold' ? 'apply' : 'release',
 hold_reason_code: holdReasonCode.trim(),
 } : {}),
 });
 setPending(null);
 refresh();
 } catch (caught) {
 if (!redirectForStepUp(caught)) setError(apiError(caught));
 } finally {
 setBusy(null);
 }
 };

 const decide = async (change: ClinicalPayloadGovernedChange, decision: 'approved' | 'rejected') => {
 const rationale = (decisionReasons[change.uuid] ?? '').trim();
 if (rationale.length < 10) return;
 setBusy(`decision:${change.uuid}`);
 setError(null);
 try {
 await axios.post(`/api/admin/integrations/governed-changes/${change.uuid}/decision`, { decision, reason: rationale });
 refresh();
 } catch (caught) {
 if (!redirectForStepUp(caught)) setError(apiError(caught));
 } finally {
 setBusy(null);
 }
 };

 const execute = async (change: ClinicalPayloadGovernedChange) => {
 if (sourceId === null) return;
 const endpoint = change.action === 'release_quarantined_payload'
 ? `payload-quarantines/${change.quarantineId}/execute-release`
 : change.action === 'purge_quarantined_payload'
 ? `payload-quarantines/${change.quarantineId}/execute-purge`
 : change.action === 'purge_clinical_payload'
 ? `payload-objects/${change.objectId}/execute-purge`
 : change.action === 'recover_clinical_payload_integrity'
 ? `payload-objects/${change.objectId}/execute-integrity-recovery`
 : `payload-objects/${change.objectId}/execute-hold`;
 setBusy(`execute:${change.uuid}`);
 setError(null);
 try {
 await axios.post(`/api/admin/integrations/governed-changes/${change.uuid}/sources/${sourceId}/${endpoint}`);
 refresh();
 } catch (caught) {
 if (!redirectForStepUp(caught)) setError(apiError(caught));
 } finally {
 setBusy(null);
 }
 };

 if (!actionable || sourceId === null) {
 return <div className="rounded-md border border-dashed border-healthcare-border p-5 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark dark:border-healthcare-border-dark">
 Select one exact integration source to view opaque object authorities and governed lifecycle controls. Organization, facility, and capability-wide aggregates remain read-only.
 </div>;
 }

 return <div className="space-y-4">
 {error ? <p role="alert" className="rounded-md border border-healthcare-critical/30 bg-healthcare-critical/5 p-3 text-sm text-healthcare-critical">{error}</p> : null}

 <div className="overflow-x-auto rounded-md border border-healthcare-border dark:border-healthcare-border-dark">
 <table className="min-w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark">
 <thead className="bg-healthcare-surface-secondary text-left text-xs uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark dark:bg-healthcare-surface-hover-dark"><tr><th className="px-3 py-2">Opaque object</th><th className="px-3 py-2">Lifecycle</th><th className="px-3 py-2">Retention</th><th className="px-3 py-2">Controls</th></tr></thead>
 <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
 {objects.map((object) => <tr key={object.uuid}>
 <td className="px-3 py-3"><div className="font-mono text-xs font-semibold">#{object.id} · {object.uuid.slice(0, 13)}…</div><div className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{readable(object.kind)} · {readable(object.classification)}</div></td>
 <td className="px-3 py-3"><div className="font-semibold">{readable(object.status)}</div><div className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{object.legalHold ? 'Legal hold active' : 'No legal hold'} · verified {time(object.lastVerifiedAt)}</div></td>
 <td className="px-3 py-3"><div>{time(object.retainUntil)}</div><div className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{object.deletionBlockers.length ? `Blocked: ${object.deletionBlockers.map(readable).join(', ')}` : 'No deletion dependencies'}</div></td>
 <td className="px-3 py-3"><div className="flex min-w-60 flex-wrap gap-1">
 {canManage && !object.legalHold && !['deletion_pending', 'deleted'].includes(object.status) ? <button type="button" className={secondaryButton} onClick={() => openRequest('apply_hold', object.id, object.uuid)}><LockKeyhole className="size-3.5" /> Apply hold</button> : null}
 {canManage && object.legalHold ? <button type="button" className={secondaryButton} onClick={() => openRequest('release_hold', object.id, object.uuid)}><UnlockKeyhole className="size-3.5" /> Release hold</button> : null}
 {canManage && object.status === 'integrity_failed' ? <button type="button" className={secondaryButton} onClick={() => openRequest('recover_integrity', object.id, object.uuid)}><ArchiveRestore className="size-3.5" /> Verify restored object</button> : null}
 {canManage && object.status !== 'quarantined' && !object.legalHold && object.deletionBlockers.length === 0 ? <button type="button" className={destructiveButton} onClick={() => openRequest('purge_object', object.id, object.uuid)}><Trash2 className="size-3.5" /> Exceptional purge</button> : null}
 </div></td>
 </tr>)}
 {objects.length === 0 ? <tr><td colSpan={4} className="px-3 py-5 text-center text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No non-deleted payload authorities in this source.</td></tr> : null}
 </tbody>
 </table>
 </div>

 <div className="space-y-2">
 <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Open quarantine authorities</h3>
 {quarantines.map((quarantine) => <div key={quarantine.uuid} className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
 <div><div className="font-mono text-xs font-semibold">Quarantine #{quarantine.id} · object #{quarantine.objectId}</div><div className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{readable(quarantine.category)} · {quarantine.reasonCode} · opened {time(quarantine.openedAt)}</div><div className="mt-1 text-xs text-healthcare-text-secondary">{quarantine.legalHold ? 'Legal hold blocks purge' : quarantine.deletionBlockers.length ? `Dependencies: ${quarantine.deletionBlockers.map(readable).join(', ')}` : 'No deletion dependencies'}</div></div>
 <div className="flex flex-wrap gap-1">
 {canOperate ? <button type="button" className={secondaryButton} onClick={() => openRequest('release_quarantine', quarantine.id, quarantine.uuid)}><ShieldCheck className="size-3.5" /> Release quarantine</button> : null}
 {canManage && !quarantine.legalHold && quarantine.deletionBlockers.length === 0 ? <button type="button" className={destructiveButton} onClick={() => openRequest('purge_quarantine', quarantine.id, quarantine.uuid)}><Trash2 className="size-3.5" /> Terminal purge</button> : null}
 </div>
 </div>)}
 {quarantines.length === 0 ? <p className="rounded-md border border-dashed border-healthcare-border p-4 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark dark:border-healthcare-border-dark">No open quarantine authority in this source.</p> : null}
 </div>

 {pending ? <form onSubmit={submitRequest} className="space-y-3 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/5 p-4">
 <div className="flex items-start justify-between gap-2"><div><h3 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{requestLabel(pending.kind)}</h3><p className="mt-1 font-mono text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Exact target #{pending.targetId} · {pending.targetUuid}</p></div><button type="button" className={secondaryButton} aria-label="Close governed request" onClick={() => setPending(null)}><X className="size-4" /></button></div>
 {(pending.kind === 'apply_hold' || pending.kind === 'release_hold') ? <label className="block text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Hold reason code<input required pattern="[a-z][a-z0-9._-]{2,119}" value={holdReasonCode} onChange={(event) => setHoldReasonCode(event.target.value)} className={`mt-1 ${inputClass}`} /></label> : null}
 <label className="block text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Governed rationale<textarea required minLength={10} maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} className={`mt-1 min-h-20 ${inputClass}`} placeholder="10–500 characters; do not enter PHI, clinical content, keys, paths, or credentials" /></label>
 <button disabled={busy === 'request' || reason.trim().length < 10} className="rounded-md bg-healthcare-primary px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">{busy === 'request' ? 'Requesting…' : 'Request independent approval'}</button>
 </form> : null}

 <div className="space-y-2">
 <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Governed lifecycle ledger</h3>
 {changes.map((change) => {
 const rationale = decisionReasons[change.uuid] ?? '';
 const canExecute = ['approved', 'execution_failed'].includes(change.status)
 && (change.action === 'release_quarantined_payload' ? canOperate : canManage);
 return <div key={change.uuid} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
 <div className="flex flex-wrap items-start justify-between gap-2"><div><div className="font-mono text-xs font-semibold">{change.uuid}</div><div className="mt-1 text-sm">{readable(change.action)} · <span className="font-semibold">{readable(change.status)}</span></div><div className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Requested {time(change.requestedAt)} · expires {time(change.expiresAt)} · opaque subject {change.subjectId.slice(0, 13)}…</div></div>
 {canExecute ? <button type="button" className={change.action.includes('purge') ? destructiveButton : secondaryButton} disabled={busy === `execute:${change.uuid}`} onClick={() => execute(change)}>{busy === `execute:${change.uuid}` ? 'Executing…' : change.status === 'execution_failed' ? 'Retry approved execution' : 'Execute approved change'}</button> : null}
 </div>
 {change.status === 'pending_approval' && canApprove ? currentUserId === change.authorUserId
 ? <p className="mt-3 text-xs text-healthcare-warning">Independent approver required; the author cannot decide this request.</p>
 : <div className="mt-3 grid gap-2 sm:grid-cols-[1fr_auto_auto] sm:items-end"><label className="text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Independent rationale<input minLength={10} maxLength={500} value={rationale} onChange={(event) => setDecisionReasons({ ...decisionReasons, [change.uuid]: event.target.value })} className={`mt-1 ${inputClass}`} placeholder="10–500 characters; no PHI" /></label><button type="button" className={secondaryButton} disabled={busy === `decision:${change.uuid}` || rationale.trim().length < 10} aria-label={`Approve ${readable(change.action)} request ${change.uuid}`} onClick={() => decide(change, 'approved')}>Approve</button><button type="button" className={destructiveButton} disabled={busy === `decision:${change.uuid}` || rationale.trim().length < 10} aria-label={`Reject ${readable(change.action)} request ${change.uuid}`} onClick={() => decide(change, 'rejected')}>Reject</button></div>
 : null}
 </div>;
 })}
 {changes.length === 0 ? <p className="rounded-md border border-dashed border-healthcare-border p-4 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark dark:border-healthcare-border-dark">No clinical-payload governed change is recorded for this exact source.</p> : null}
 </div>
 </div>;
}
