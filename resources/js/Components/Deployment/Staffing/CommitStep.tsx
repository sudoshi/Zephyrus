import { AlertTriangle } from 'lucide-react';
import { humanize } from '@/Components/Deployment/format';
import { useCommitImport } from '@/features/deployment/staffing/hooks';
import type { CommitResult, StagedItem, StagedPayload } from '@/features/deployment/staffing/types';
import { BTN_GHOST, BTN_PRIMARY } from './controls';

// Client-side projection of what commit will write (mirrors StaffImportStore's server
// rules): a member commits when explicitly accepted/edited, or when it sits in the
// high-confidence auto_approved bucket undecided. Reject/defer/deactivate never commit.
function willCommit(item: StagedItem): boolean {
  const action = item.decision?.action;
  if (action === 'reject' || action === 'defer' || action === 'deactivate') return false;
  if (action === 'edit' || action === 'split') return (item.decision?.assignments?.length ?? 0) > 0;
  if (action === 'accept') return item.proposed.length > 0;
  return item.bucket === 'auto_approved' && item.proposed.length > 0;
}

function membershipCount(item: StagedItem): number {
  if (item.decision?.action === 'edit' || item.decision?.action === 'split') return item.decision.assignments?.length ?? 0;
  return item.proposed.length;
}

function Stat({ label, value, tone = false }: { label: string; value: number; tone?: boolean }) {
  return (
    <div className="rounded-lg border border-healthcare-border bg-healthcare-surface p-3 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className={`text-2xl font-semibold tabular-nums ${tone ? 'text-healthcare-critical dark:text-healthcare-critical-dark' : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'}`}>{value}</div>
      <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</div>
    </div>
  );
}

interface CommitStepProps {
  runId: number;
  staged: StagedPayload;
  onCommitted: (result: CommitResult) => void;
  onBack: () => void;
}

export function CommitStep({ runId, staged, onCommitted, onBack }: CommitStepProps) {
  const commit = useCommitImport();

  const committing = staged.items.filter(willCommit);
  const deactivating = staged.items.filter((i) => i.decision?.action === 'deactivate');
  const assignments = committing.reduce((sum, i) => sum + membershipCount(i), 0);
  const provisioning = committing.filter((i) => i.user_id !== null).length;
  const skippedReview = staged.items.filter(
    (i) => !willCommit(i) && i.bucket !== 'departed' && !['reject', 'defer'].includes(i.decision?.action ?? ''),
  ).length;

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Stat label="Members committing" value={committing.length} />
        <Stat label="Assignments written" value={assignments} />
        <Stat label="Accounts provisioned" value={provisioning} />
        <Stat label="Deactivations" value={deactivating.length} tone={deactivating.length > 0} />
      </div>

      {skippedReview > 0 && (
        <div className="flex items-start gap-2 rounded-md bg-healthcare-warning/10 px-3 py-2 text-xs text-healthcare-warning dark:bg-healthcare-warning-dark/20 dark:text-healthcare-warning-dark">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
          <span>
            <span className="tabular-nums font-medium">{skippedReview}</span> reviewed row{skippedReview === 1 ? '' : 's'} will NOT commit — they still need an explicit Accept or Edit. Go back to resolve them, or commit without them.
          </span>
        </div>
      )}

      {committing.length > 0 && (
        <div className="overflow-x-auto rounded-lg border border-healthcare-border dark:border-healthcare-border-dark">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-healthcare-border text-left text-xs uppercase tracking-wide text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
                <th className="px-3 py-2 font-medium">Person</th>
                <th className="px-3 py-2 font-medium">Primary membership</th>
                <th className="px-3 py-2 font-medium">Memberships</th>
                <th className="px-3 py-2 font-medium">Account</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
              {committing.slice(0, 100).map((item) => {
                const primary = item.decision?.assignments?.find((a) => a.primary) ?? item.decision?.assignments?.[0]
                  ?? item.proposed.find((p) => p.primary) ?? item.proposed[0];
                return (
                  <tr key={item.staff_member_id}>
                    <td className="px-3 py-2 font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{item.display_name ?? item.staff_key}</td>
                    <td className="px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {primary ? `${humanize(primary.service_line_code)} · ${humanize(primary.role_code)}` : '—'}
                    </td>
                    <td className="px-3 py-2 tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{membershipCount(item)}</td>
                    <td className="px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.user_id !== null ? 'Provision' : '—'}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          {committing.length > 100 && (
            <div className="px-3 py-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">+ {committing.length - 100} more</div>
          )}
        </div>
      )}

      {commit.isError && (
        <div className="rounded-md bg-healthcare-critical/10 px-3 py-2 text-xs text-healthcare-critical dark:bg-healthcare-critical-dark/20 dark:text-healthcare-critical-dark">
          Commit failed. This import may already be committed — reload and check.
        </div>
      )}

      <div className="flex items-center justify-between border-t border-healthcare-border pt-4 dark:border-healthcare-border-dark">
        <button type="button" className={BTN_GHOST} onClick={onBack}>← Back</button>
        <button
          type="button"
          className={BTN_PRIMARY}
          disabled={commit.isPending || (committing.length === 0 && deactivating.length === 0)}
          onClick={() => commit.mutate(runId, { onSuccess: onCommitted })}
        >
          {commit.isPending ? 'Committing…' : `Commit ${committing.length} member${committing.length === 1 ? '' : 's'}`}
        </button>
      </div>
    </div>
  );
}
