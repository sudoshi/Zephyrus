import { Head, Link, usePage } from '@inertiajs/react';
import {
  AlertTriangle,
  ArchiveRestore,
  ArrowLeft,
  ArrowRight,
  CheckCircle2,
  Database,
  HardDrive,
  LockKeyhole,
  ShieldAlert,
} from 'lucide-react';
import AdminScopeSelector from '@/Components/Admin/AdminScopeSelector';
import ClinicalPayloadGovernance, {
  type ClinicalPayloadGovernedChange,
  type ClinicalPayloadObjectItem,
  type ClinicalPayloadQuarantineItem,
} from '@/Components/Admin/ClinicalPayloadGovernance';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import { withAdminScope } from '@/lib/adminScope';
import {
  AdminMetricStrip,
  AdminSectionHeading,
  type AdminMetric,
} from '@/Pages/Admin/components/AdminPrimitives';
import type { PageProps } from '@/types';

interface CoverageTarget {
  label: string;
  protected: number;
  legacy: number;
  eligible: number;
  coveragePercent: number;
}

interface BackfillRun {
  runId: number;
  runUuid: string;
  sourceId: number | null;
  mode: string;
  status: string;
  scanned: number;
  protected: number;
  skipped: number;
  failed: number;
  mismatched: number;
  errorCode: string | null;
  startedAt: string | null;
  completedAt: string | null;
}

interface DataProtectionSnapshot {
  generatedAt: string;
  scope: {
    mode: string;
    organizationId: number | null;
    facilityId: number | null;
    sourceId: number | null;
    label: string;
  };
  provider: {
    status: 'ready' | 'not_ready';
    errorCode: string | null;
    disk: string | null;
    driver: string | null;
    cipher: string | null;
    compression: string | null;
    keyProviderScheme: string | null;
    keyProviderVersion: string | null;
    keyReferenceConfigured: boolean;
    providerReachable: boolean;
  };
  coverage: {
    protected: number;
    legacy: number;
    eligible: number;
    coveragePercent: number;
    targets: CoverageTarget[];
  };
  objects: {
    total: number;
    ready: number;
    integrityFailed: number;
    legalHolds: number;
    items: ClinicalPayloadObjectItem[];
  };
  backfill: {
    pending: number;
    failed: number;
    mismatched: number;
    latestRuns: BackfillRun[];
  };
  quarantine: {
    open: number;
    oldestOpenedAt: string | null;
    byCategory: Record<string, number>;
    items: ClinicalPayloadQuarantineItem[];
  };
  retention: {
    backlog: number;
    pendingDeletion: number;
    deletionFailures: number;
    deletedTombstones: number;
  };
  integrity: {
    lastVerifiedAt: string | null;
    verifiedLast24Hours: number;
    failures: number;
  };
  partitioning: {
    status: 'ready' | 'blocked';
    partitionedCount: number;
    requiredCount: number;
    partitionedTables: string[];
    remediation: string | null;
  };
  governance: {
    actionable: boolean;
    changes: ClinicalPayloadGovernedChange[];
  };
  links: {
    sourceGovernance: string;
    governedChanges: string;
    systemHealth: string;
  };
}

interface DataProtectionProps {
  snapshot: DataProtectionSnapshot;
}

function formatTime(value: string | null): string {
  return value
    ? new Date(value).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })
    : 'No evidence recorded';
}

function StatusBadge({ ready, label }: { ready: boolean; label: string }) {
  return (
    <span className={`inline-flex rounded-md border px-2 py-0.5 text-xs font-semibold ${ready
      ? 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark'
      : 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark'}`}
    >
      {label}
    </span>
  );
}

export default function DataProtection({ snapshot }: DataProtectionProps) {
  const page = usePage<PageProps>();
  const scopeQuery = page.props.adminScope?.query;
  const scopedHref = (href: string) => withAdminScope(href, scopeQuery);
  const unresolvedBackfill = snapshot.backfill.pending + snapshot.backfill.failed + snapshot.backfill.mismatched;
  const metrics: AdminMetric[] = [
    { label: 'Payload authority', value: snapshot.provider.status === 'ready' ? 'Ready' : 'Blocked', tone: snapshot.provider.status === 'ready' ? 'default' : 'critical' },
    { label: 'Encryption coverage', value: `${snapshot.coverage.coveragePercent}%`, detail: `${snapshot.coverage.protected} of ${snapshot.coverage.eligible} eligible bodies`, tone: snapshot.coverage.legacy > 0 ? 'warning' : 'default' },
    { label: 'Legacy bodies', value: snapshot.coverage.legacy, tone: snapshot.coverage.legacy > 0 ? 'critical' : 'default' },
    { label: 'Open quarantine', value: snapshot.quarantine.open, tone: snapshot.quarantine.open > 0 ? 'critical' : 'default' },
    { label: 'Retention backlog', value: snapshot.retention.backlog, tone: snapshot.retention.backlog > 0 ? 'warning' : 'default' },
    { label: 'Integrity failures', value: snapshot.integrity.failures, tone: snapshot.integrity.failures > 0 ? 'critical' : 'default' },
    { label: 'Legal holds', value: snapshot.objects.legalHolds },
  ];

  const actions = [
    snapshot.provider.status !== 'ready' ? { key: 'provider', title: 'Restore encrypted payload authority', detail: snapshot.provider.errorCode ?? 'Provider readiness is incomplete.', href: snapshot.links.systemHealth } : null,
    snapshot.coverage.legacy > 0 ? { key: 'legacy', title: 'Complete verified payload backfill', detail: `${snapshot.coverage.legacy} plaintext JSONB body or bodies remain in scope.`, href: snapshot.links.governedChanges } : null,
    unresolvedBackfill > 0 ? { key: 'backfill', title: 'Reconcile backfill exceptions', detail: `${unresolvedBackfill} pending, failed, or mismatched item(s) require bounded repair.`, href: snapshot.links.governedChanges } : null,
    snapshot.quarantine.open > 0 ? { key: 'quarantine', title: 'Review quarantined payloads', detail: `${snapshot.quarantine.open} payload(s) are blocked from normalization, replay, and projection.`, href: snapshot.links.governedChanges } : null,
    snapshot.retention.deletionFailures > 0 ? { key: 'deletion', title: 'Repair deletion failures', detail: `${snapshot.retention.deletionFailures} append-only deletion failure event(s) are recorded.`, href: snapshot.links.governedChanges } : null,
    snapshot.partitioning.status !== 'ready' ? { key: 'partitioning', title: 'Approve online partition migration', detail: snapshot.partitioning.remediation ?? 'Partition readiness is incomplete.', href: snapshot.links.governedChanges } : null,
  ].filter((item): item is NonNullable<typeof item> => item !== null);

  return (
    <DashboardLayout>
      <Head title="Data Protection" />
      <PageContentLayout
        title="Data Protection"
        subtitle="Encrypted clinical payload authority, migration coverage, quarantine, retention, and integrity evidence"
        headerContent={<AdminScopeSelector />}
      >
        <div className="space-y-5">
          <Link href="/admin" className="inline-flex items-center gap-1 text-sm font-medium text-healthcare-info dark:text-healthcare-info-dark">
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
            Administration
          </Link>

          <div className="rounded-md border border-healthcare-info/30 bg-healthcare-info/5 p-3 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Minimum-necessary boundary: {snapshot.scope.label}</p>
            <p className="mt-1">This is a {snapshot.scope.mode.replace('_', ' ')} aggregate. It contains opaque object and receipt counts, provider version metadata, stable error codes, and lifecycle evidence only—never decrypted bodies, patient identifiers, tokens, object paths, wrapped keys, or key references.</p>
          </div>

          <AdminMetricStrip metrics={metrics} />

          <section>
            <AdminSectionHeading title="Authority and key posture" description="Fail-closed storage and cryptographic authority used by every protected writer and reader" />
            <div className="grid gap-3 lg:grid-cols-3">
              <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark lg:col-span-2">
                <div className="flex items-start gap-3">
                  <span className="flex h-9 w-9 items-center justify-center rounded-md bg-healthcare-info/10 text-healthcare-info"><LockKeyhole className="h-5 w-5" aria-hidden="true" /></span>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">ClinicalPayloadStore</p>
                      <StatusBadge ready={snapshot.provider.status === 'ready'} label={snapshot.provider.status === 'ready' ? 'Ready' : 'Not ready'} />
                    </div>
                    <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                      <div><dt className="text-xs uppercase text-healthcare-text-secondary">Cipher</dt><dd className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{snapshot.provider.cipher ?? 'Unavailable'}</dd></div>
                      <div><dt className="text-xs uppercase text-healthcare-text-secondary">Compression</dt><dd className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{snapshot.provider.compression ?? 'Unavailable'}</dd></div>
                      <div><dt className="text-xs uppercase text-healthcare-text-secondary">Provider</dt><dd className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{snapshot.provider.keyProviderScheme ?? 'Unavailable'}</dd></div>
                      <div><dt className="text-xs uppercase text-healthcare-text-secondary">Immutable key version</dt><dd className="break-all font-mono text-xs text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{snapshot.provider.keyProviderVersion ?? 'Unavailable'}</dd></div>
                      <div><dt className="text-xs uppercase text-healthcare-text-secondary">Storage driver</dt><dd className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{snapshot.provider.driver ?? 'Unavailable'}</dd></div>
                      <div><dt className="text-xs uppercase text-healthcare-text-secondary">Readiness code</dt><dd className="font-mono text-xs text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{snapshot.provider.errorCode ?? 'none'}</dd></div>
                    </dl>
                  </div>
                </div>
              </div>
              <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                <div className="flex items-center gap-2"><ArchiveRestore className="h-5 w-5 text-healthcare-info" aria-hidden="true" /><p className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Restore evidence</p></div>
                <p className="mt-3 text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{snapshot.integrity.verifiedLast24Hours}</p>
                <p className="text-sm text-healthcare-text-secondary">verified in the last 24 hours</p>
                <p className="mt-3 text-xs text-healthcare-text-secondary">Latest: {formatTime(snapshot.integrity.lastVerifiedAt)}</p>
              </div>
            </div>
          </section>

          <section>
            <AdminSectionHeading title="Encryption and backfill coverage" description="Legacy columns are temporary inputs only; protected rows retain an opaque pointer and clear the ordinary JSONB body" />
            <div className="overflow-hidden rounded-md border border-healthcare-border bg-healthcare-surface dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark">
                  <thead className="bg-healthcare-surface-secondary text-left text-xs uppercase text-healthcare-text-secondary dark:bg-healthcare-surface-hover-dark"><tr><th className="px-3 py-2">Authority</th><th className="px-3 py-2 text-right">Protected</th><th className="px-3 py-2 text-right">Legacy</th><th className="px-3 py-2 text-right">Coverage</th></tr></thead>
                  <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                    {snapshot.coverage.targets.map((target) => (
                      <tr key={target.label}><td className="px-3 py-2 font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{target.label}</td><td className="px-3 py-2 text-right tabular-nums">{target.protected}</td><td className={`px-3 py-2 text-right tabular-nums ${target.legacy > 0 ? 'font-semibold text-healthcare-critical' : ''}`}>{target.legacy}</td><td className="px-3 py-2 text-right tabular-nums">{target.coveragePercent}%</td></tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </section>

          <section>
            <AdminSectionHeading title="Operator action queue" description="Governed repairs and unresolved controls; links lead to authoritative operational surfaces" />
            {actions.length === 0 ? (
              <div className="rounded-md border border-healthcare-success/30 bg-healthcare-success/5 p-4 text-sm text-healthcare-text-secondary"><div className="flex items-center gap-2 font-medium text-healthcare-success"><CheckCircle2 className="h-4 w-4" aria-hidden="true" />No data-protection exceptions in scope</div></div>
            ) : (
              <div className="divide-y divide-healthcare-border overflow-hidden rounded-md border border-healthcare-border bg-healthcare-surface dark:divide-healthcare-border-dark dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                {actions.map((action) => (
                  <Link key={action.key} href={scopedHref(action.href)} className="flex items-start gap-3 px-3 py-3 hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark">
                    <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0 text-healthcare-critical" aria-hidden="true" />
                    <span className="min-w-0 flex-1"><span className="block font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{action.title}</span><span className="block text-sm text-healthcare-text-secondary">{action.detail}</span></span>
                    <ArrowRight className="mt-1 h-4 w-4 text-healthcare-text-secondary" aria-hidden="true" />
                  </Link>
                ))}
              </div>
            )}
          </section>

          <div className="grid gap-3 lg:grid-cols-3">
            <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <div className="flex items-center gap-2"><ShieldAlert className="h-5 w-5 text-healthcare-warning" aria-hidden="true" /><p className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Quarantine</p></div>
              <p className="mt-3 text-2xl font-semibold">{snapshot.quarantine.open}</p><p className="text-sm text-healthcare-text-secondary">open isolated objects</p>
              <p className="mt-3 text-xs text-healthcare-text-secondary">Oldest: {formatTime(snapshot.quarantine.oldestOpenedAt)}</p>
              <div className="mt-2 flex flex-wrap gap-1">{Object.entries(snapshot.quarantine.byCategory).map(([category, count]) => <span key={category} className="rounded bg-healthcare-surface-secondary px-2 py-1 text-xs dark:bg-healthcare-surface-hover-dark">{category}: {count}</span>)}</div>
            </section>
            <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <div className="flex items-center gap-2"><HardDrive className="h-5 w-5 text-healthcare-info" aria-hidden="true" /><p className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Retention</p></div>
              <dl className="mt-3 space-y-2 text-sm"><div className="flex justify-between"><dt>Eligible backlog</dt><dd className="font-semibold tabular-nums">{snapshot.retention.backlog}</dd></div><div className="flex justify-between"><dt>Pending deletion</dt><dd className="font-semibold tabular-nums">{snapshot.retention.pendingDeletion}</dd></div><div className="flex justify-between"><dt>Deletion failures</dt><dd className="font-semibold tabular-nums">{snapshot.retention.deletionFailures}</dd></div><div className="flex justify-between"><dt>Retained tombstones</dt><dd className="font-semibold tabular-nums">{snapshot.retention.deletedTombstones}</dd></div></dl>
            </section>
            <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
              <div className="flex items-center gap-2"><Database className="h-5 w-5 text-healthcare-info" aria-hidden="true" /><p className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Partition readiness</p></div>
              <div className="mt-3 flex items-center gap-2"><span className="text-2xl font-semibold">{snapshot.partitioning.partitionedCount}/{snapshot.partitioning.requiredCount}</span><StatusBadge ready={snapshot.partitioning.status === 'ready'} label={snapshot.partitioning.status} /></div>
              <p className="mt-2 text-sm text-healthcare-text-secondary">{snapshot.partitioning.remediation ?? 'Required high-volume authorities are partitioned.'}</p>
            </section>
          </div>

          <section>
            <AdminSectionHeading title="Governed payload lifecycle and recovery" description="Exact-source opaque authorities only; every hold, purge, release, and recovery requires step-up, independent approval, exact contract matching, and append-only execution evidence" />
            <ClinicalPayloadGovernance
              sourceId={snapshot.scope.sourceId}
              actionable={snapshot.governance.actionable}
              objects={snapshot.objects.items}
              quarantines={snapshot.quarantine.items}
              changes={snapshot.governance.changes}
              currentUserId={page.props.auth.user?.id ?? null}
              canManage={page.props.auth.can?.manage_data_stewardship === true}
              canOperate={page.props.auth.can?.operate_integrations === true}
              canApprove={page.props.auth.can?.approve_integration_changes === true}
            />
          </section>

          <section>
            <AdminSectionHeading title="Recent backfill evidence" description="Append-only run summaries; object content and storage paths are never rendered" />
            {snapshot.backfill.latestRuns.length === 0 ? (
              <div className="rounded-md border border-dashed border-healthcare-border p-5 text-center text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark">No inventory or backfill run has been recorded for this boundary.</div>
            ) : (
              <div className="overflow-x-auto rounded-md border border-healthcare-border dark:border-healthcare-border-dark"><table className="min-w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark"><thead className="bg-healthcare-surface-secondary text-left text-xs uppercase text-healthcare-text-secondary dark:bg-healthcare-surface-hover-dark"><tr><th className="px-3 py-2">Run</th><th className="px-3 py-2">Mode</th><th className="px-3 py-2">Status</th><th className="px-3 py-2 text-right">Scanned</th><th className="px-3 py-2 text-right">Protected</th><th className="px-3 py-2 text-right">Exceptions</th><th className="px-3 py-2">Completed</th></tr></thead><tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">{snapshot.backfill.latestRuns.map((run) => <tr key={run.runUuid}><td className="px-3 py-2 font-mono text-xs">#{run.runId}</td><td className="px-3 py-2 capitalize">{run.mode}</td><td className="px-3 py-2"><StatusBadge ready={run.status === 'completed'} label={run.status.replaceAll('_', ' ')} /></td><td className="px-3 py-2 text-right tabular-nums">{run.scanned}</td><td className="px-3 py-2 text-right tabular-nums">{run.protected}</td><td className="px-3 py-2 text-right tabular-nums">{run.failed + run.mismatched}</td><td className="px-3 py-2 text-xs text-healthcare-text-secondary">{formatTime(run.completedAt)}</td></tr>)}</tbody></table></div>
            )}
          </section>

          <div className="flex items-start gap-2 rounded-md border border-healthcare-warning/30 bg-healthcare-warning/5 p-3 text-sm text-healthcare-text-secondary"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-healthcare-warning" aria-hidden="true" /><span>Clinical content inspection remains prohibited. Lifecycle controls appear only for one exact selected source and never bypass legal holds, unresolved dependencies, step-up authentication, independent approval, or immutable contract verification.</span></div>
          <p className="text-right text-xs text-healthcare-text-secondary">Snapshot generated {formatTime(snapshot.generatedAt)}</p>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
