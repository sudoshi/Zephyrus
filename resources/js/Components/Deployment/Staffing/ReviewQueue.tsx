import { useState } from 'react';
import {
  Check,
  Clock,
  Link2,
  Pencil,
  RefreshCw,
  ShieldAlert,
  Sparkles,
  UserMinus,
  X,
} from 'lucide-react';
import { humanize } from '@/Components/Deployment/format';
import { useCreateRule, useRecordReview, useReresolveImport } from '@/features/deployment/staffing/hooks';
import type {
  AssignmentDraft,
  Bucket,
  ImportResult,
  ReviewAction,
  StagedItem,
  StagedPayload,
  StaffingReference,
} from '@/features/deployment/staffing/types';
import { AssignmentEditor } from './AssignmentEditor';
import { BUCKET_META, BUCKET_ORDER, BucketPill, confidencePct } from './bucketMeta';
import { BTN_GHOST, BTN_PRIMARY, BTN_SM, INPUT, LABEL, SELECT } from './controls';

const MATCH_FIELDS = ['department', 'specialty', 'job_title', 'job_code', 'cost_center', 'home_unit'];

// Which actions each bucket offers.
const ACTIONS: Record<Bucket, ReviewAction[]> = {
  auto_approved: ['accept', 'edit', 'reject'],
  needs_review: ['accept', 'edit', 'reject', 'defer'],
  conflicts: ['accept', 'edit', 'reject', 'defer'],
  unmatched: ['edit', 'defer'],
  departed: ['deactivate', 'defer'],
};

const DECISION_LABEL: Record<ReviewAction, string> = {
  accept: 'Accepted',
  edit: 'Edited',
  split: 'Edited',
  reject: 'Rejected',
  defer: 'Deferred',
  deactivate: 'Deactivate',
};

function draftFromItem(item: StagedItem): AssignmentDraft[] {
  if (item.proposed.length === 0) {
    return [{ service_line_code: '', role_code: '', unit_hint: '', primary: true }];
  }
  return item.proposed.map((p, i) => ({
    service_line_code: p.service_line_code,
    role_code: p.role_code,
    unit_hint: p.unit_hint ?? '',
    primary: p.primary || (i === 0 && !item.proposed.some((x) => x.primary)),
  }));
}

interface RowProps {
  runId: number;
  item: StagedItem;
  reference: StaffingReference;
  onItemUpdated: (item: StagedItem) => void;
}

function ReviewRow({ runId, item, reference, onItemUpdated }: RowProps) {
  const record = useRecordReview();
  const createRule = useCreateRule();
  const [mode, setMode] = useState<'idle' | 'edit' | 'rule'>('idle');
  const [drafts, setDrafts] = useState<AssignmentDraft[]>(() => draftFromItem(item));
  const [rule, setRule] = useState(() => ({
    match_field: (item.proposed[0]?.evidence?.source_field as string) ?? 'department',
    match_value: (item.proposed[0]?.evidence?.matched_value as string) ?? '',
    target_service_line_code: item.proposed[0]?.service_line_code ?? reference.service_lines[0]?.code ?? '',
    target_role_code: item.proposed[0]?.role_code ?? '',
  }));

  const regulated = item.proposed.some((p) => p.regulated);
  const actions = ACTIONS[item.bucket];

  function act(action: ReviewAction, assignments?: AssignmentDraft[]) {
    record.mutate(
      { runId, staffMemberId: item.staff_member_id, decision: { action, assignments } },
      { onSuccess: (updated) => { onItemUpdated(updated); setMode('idle'); } },
    );
  }

  function saveRule() {
    createRule.mutate(
      {
        staffing_source_id: undefined,
        match_field: rule.match_field,
        match_operator: 'equals',
        match_value: rule.match_value,
        target_service_line_code: rule.target_service_line_code,
        target_role_code: rule.target_role_code,
        confidence: 0.95,
        staff_import_run_id: runId,
        staff_member_id: item.staff_member_id,
      },
      { onSuccess: () => setMode('idle') },
    );
  }

  return (
    <div className="px-3 py-2.5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        {/* Identity + proposals */}
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {item.display_name ?? item.staff_key}
            </span>
            {item.employee_type && (
              <span className="rounded bg-healthcare-background px-1.5 py-0.5 text-xs text-healthcare-text-secondary dark:bg-white/5 dark:text-healthcare-text-secondary-dark">
                {humanize(item.employee_type)}
              </span>
            )}
            {item.user_id !== null ? (
              <span className="inline-flex items-center gap-1 text-xs text-healthcare-info dark:text-healthcare-info-dark">
                <Link2 className="size-3" /> Linked account
              </span>
            ) : null}
            {regulated && (
              <span className="inline-flex items-center gap-1 rounded-full bg-healthcare-warning/10 px-2 py-0.5 text-xs font-medium text-healthcare-warning dark:bg-healthcare-warning-dark/20 dark:text-healthcare-warning-dark">
                <ShieldAlert className="size-3" /> Regulated
              </span>
            )}
            {item.decision && (
              <span className="inline-flex items-center gap-1 rounded-full bg-healthcare-primary/10 px-2 py-0.5 text-xs font-medium text-healthcare-primary dark:text-healthcare-primary-dark">
                {DECISION_LABEL[item.decision.action]}
              </span>
            )}
          </div>

          {item.email && <div className="truncate text-xs text-healthcare-text-secondary tabular-nums dark:text-healthcare-text-secondary-dark">{item.email}</div>}

          {mode === 'edit' ? (
            <div className="mt-2">
              <AssignmentEditor assignments={drafts} onChange={setDrafts} reference={reference} />
              <div className="mt-2 flex items-center gap-2">
                <button type="button" className={BTN_PRIMARY} disabled={record.isPending || drafts.some((d) => !d.service_line_code || !d.role_code)} onClick={() => act('edit', drafts)}>
                  Save assignment
                </button>
                <button type="button" className={BTN_GHOST} onClick={() => setMode('idle')}>Cancel</button>
              </div>
            </div>
          ) : mode === 'rule' ? (
            <div className="mt-2 grid gap-2 rounded-md border border-healthcare-border bg-healthcare-background p-2.5 dark:border-healthcare-border-dark dark:bg-white/5 sm:grid-cols-2">
              <div>
                <label className={LABEL}>When field</label>
                <select className={`${SELECT} mt-1 w-full`} value={rule.match_field} onChange={(e) => setRule((r) => ({ ...r, match_field: e.target.value }))}>
                  {MATCH_FIELDS.map((f) => <option key={f} value={f}>{humanize(f)}</option>)}
                </select>
              </div>
              <div>
                <label className={LABEL}>equals value</label>
                <input className={`${INPUT} mt-1 w-full`} value={rule.match_value} onChange={(e) => setRule((r) => ({ ...r, match_value: e.target.value }))} placeholder="Critical Care" />
              </div>
              <div>
                <label className={LABEL}>Service line</label>
                <select className={`${SELECT} mt-1 w-full`} value={rule.target_service_line_code} onChange={(e) => setRule((r) => ({ ...r, target_service_line_code: e.target.value }))}>
                  {reference.service_lines.map((s) => <option key={s.code} value={s.code}>{s.name}</option>)}
                </select>
              </div>
              <div>
                <label className={LABEL}>Role</label>
                <select className={`${SELECT} mt-1 w-full`} value={rule.target_role_code} onChange={(e) => setRule((r) => ({ ...r, target_role_code: e.target.value }))}>
                  <option value="" disabled>Role…</option>
                  {reference.roles.map((r) => <option key={r.role_code} value={r.role_code}>{r.display_name}</option>)}
                </select>
              </div>
              <div className="flex items-center gap-2 sm:col-span-2">
                <button type="button" className={BTN_PRIMARY} disabled={createRule.isPending || rule.match_value === '' || rule.target_role_code === ''} onClick={saveRule}>
                  Create rule
                </button>
                <button type="button" className={BTN_GHOST} onClick={() => setMode('idle')}>Cancel</button>
                <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Re-resolve to apply it to the queue.</span>
              </div>
            </div>
          ) : item.proposed.length > 0 ? (
            <ul className="mt-1 space-y-0.5">
              {item.proposed.map((p, i) => (
                <li key={i} className="flex flex-wrap items-center gap-x-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{humanize(p.service_line_code)}</span>
                  <span>·</span>
                  <span>{humanize(p.role_code)}</span>
                  {p.primary && <span className="rounded bg-healthcare-primary/10 px-1 text-healthcare-primary dark:text-healthcare-primary-dark">primary</span>}
                  <span className="tabular-nums">{confidencePct(p.confidence)}</span>
                  <span className="text-healthcare-text-secondary/80 dark:text-healthcare-text-secondary-dark/80">via {humanize(p.resolution_source)}</span>
                </li>
              ))}
            </ul>
          ) : (
            <div className="mt-1 text-xs italic text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No proposed membership — assign manually.</div>
          )}

          {item.conflicts.length > 0 && (
            <div className="mt-1 text-xs text-healthcare-critical dark:text-healthcare-critical-dark">
              Conflicts with {item.conflicts.length} existing assignment{item.conflicts.length === 1 ? '' : 's'}.
            </div>
          )}
        </div>

        {/* Actions */}
        {mode === 'idle' && (
          <div className="flex shrink-0 flex-wrap items-center gap-1">
            {actions.includes('accept') && item.proposed.length > 0 && (
              <button type="button" className={`${BTN_SM} text-healthcare-success hover:bg-healthcare-success/10 dark:text-healthcare-success-dark`} disabled={record.isPending} onClick={() => act('accept')}>
                <Check className="size-3.5" /> Accept
              </button>
            )}
            {actions.includes('edit') && (
              <button type="button" className={`${BTN_SM} text-healthcare-text-secondary hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark`} onClick={() => { setDrafts(draftFromItem(item)); setMode('edit'); }}>
                <Pencil className="size-3.5" /> Edit
              </button>
            )}
            {actions.includes('deactivate') && (
              <button type="button" className={`${BTN_SM} text-healthcare-critical hover:bg-healthcare-critical/10 dark:text-healthcare-critical-dark`} disabled={record.isPending} onClick={() => act('deactivate')}>
                <UserMinus className="size-3.5" /> Deactivate
              </button>
            )}
            {actions.includes('reject') && (
              <button type="button" className={`${BTN_SM} text-healthcare-text-secondary hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark`} disabled={record.isPending} onClick={() => act('reject')}>
                <X className="size-3.5" /> Reject
              </button>
            )}
            {actions.includes('defer') && (
              <button type="button" className={`${BTN_SM} text-healthcare-text-secondary hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark`} disabled={record.isPending} onClick={() => act('defer')}>
                <Clock className="size-3.5" /> Defer
              </button>
            )}
            {item.bucket !== 'departed' && (
              <button type="button" className={`${BTN_SM} text-healthcare-primary hover:bg-healthcare-primary/10 dark:text-healthcare-primary-dark`} onClick={() => setMode('rule')}>
                <Sparkles className="size-3.5" /> Rule
              </button>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

interface ReviewQueueProps {
  runId: number;
  staged: StagedPayload;
  reference: StaffingReference;
  onItemUpdated: (item: StagedItem) => void;
  onReresolved: (result: ImportResult) => void;
  onNext: () => void;
  onBack: () => void;
}

export function ReviewQueue({ runId, staged, reference, onItemUpdated, onReresolved, onNext, onBack }: ReviewQueueProps) {
  const reresolve = useReresolveImport();
  const byBucket = (bucket: Bucket) => staged.items.filter((i) => i.bucket === bucket);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-1.5">
          {BUCKET_ORDER.map((bucket) => {
            const n = byBucket(bucket).length;
            return n > 0 ? <BucketPill key={bucket} bucket={bucket} count={n} /> : null;
          })}
        </div>
        <button type="button" className={BTN_GHOST} disabled={reresolve.isPending} onClick={() => reresolve.mutate(runId, { onSuccess: onReresolved })}>
          <RefreshCw className={`size-4 ${reresolve.isPending ? 'animate-spin' : ''}`} /> Re-resolve
        </button>
      </div>

      {BUCKET_ORDER.map((bucket) => {
        const items = byBucket(bucket);
        if (items.length === 0) return null;
        const meta = BUCKET_META[bucket];
        return (
          <section key={bucket} className="overflow-hidden rounded-lg border border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <header className="flex items-center gap-2 border-b border-healthcare-border px-3 py-2 dark:border-healthcare-border-dark">
              <BucketPill bucket={bucket} count={items.length} />
              <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{meta.blurb}</span>
            </header>
            <div className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
              {items.map((item) => (
                <ReviewRow key={item.staff_member_id} runId={runId} item={item} reference={reference} onItemUpdated={onItemUpdated} />
              ))}
            </div>
          </section>
        );
      })}

      <div className="flex items-center justify-between border-t border-healthcare-border pt-4 dark:border-healthcare-border-dark">
        <button type="button" className={BTN_GHOST} onClick={onBack}>← Back</button>
        <button type="button" className={BTN_PRIMARY} onClick={onNext}>Approve &amp; commit →</button>
      </div>
    </div>
  );
}
