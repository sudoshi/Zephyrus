import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import {
  Activity,
  AlertTriangle,
  ArrowLeft,
  ArrowRight,
  CheckCircle2,
  CircleDashed,
  Clock3,
  RefreshCw,
  ShieldAlert,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import {
  AdminMetricStrip,
  AdminSectionHeading,
  type AdminMetric,
} from '@/Pages/Admin/components/AdminPrimitives';

type HealthStatus = 'healthy' | 'warning' | 'critical' | 'unknown' | 'disabled';

interface HealthObservation {
  key: string;
  label: string;
  category: string;
  status: HealthStatus;
  recordedStatus: HealthStatus | null;
  summary: string;
  errorCode: string | null;
  required: boolean;
  owner: string;
  runbookRef: string;
  runbookUrl: string | null;
  observedAt: string | null;
  freshUntil: string | null;
  durationMs: number | null;
  origin: 'scheduled' | 'manual' | null;
  stale: boolean;
  details: Record<string, unknown>;
  href: string;
}

interface HealthSnapshot {
  generatedAt: string;
  batchUuid: string | null;
  correlationId: string | null;
  batchObservationCount: number | null;
  overallStatus: 'healthy' | 'degraded' | 'critical' | 'unknown';
  counts: Record<HealthStatus, number> & { requiredAttention: number };
  lastScheduledAt: string | null;
  observations: HealthObservation[];
  selectedComponent: HealthObservation | null;
  contract: {
    freshForSeconds: number;
    statuses: HealthStatus[];
    appendOnly: boolean;
    externalCallsAllowed: boolean;
  };
}

interface SystemHealthProps {
  snapshot: HealthSnapshot;
  canRunDiagnostics: boolean;
}

type HealthFilter = 'all' | 'attention' | HealthStatus;

function initialFilter(): HealthFilter {
  if (typeof window === 'undefined') return 'all';
  const value = new URLSearchParams(window.location.search).get('status');
  return ['all', 'attention', 'healthy', 'warning', 'critical', 'unknown', 'disabled'].includes(value ?? '')
    ? value as HealthFilter
    : 'all';
}

const statusClasses: Record<HealthStatus | 'degraded', string> = {
  healthy: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  warning: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  critical: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  unknown: 'border-healthcare-border bg-healthcare-surface-secondary text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-surface-hover-dark dark:text-healthcare-text-secondary-dark',
  disabled: 'border-healthcare-border bg-healthcare-surface-secondary text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-surface-hover-dark dark:text-healthcare-text-secondary-dark',
};

function StatusBadge({ status }: { status: HealthStatus | 'degraded' }) {
  return (
    <span className={`inline-flex rounded-md border px-2 py-0.5 text-xs font-semibold capitalize ${statusClasses[status]}`}>
      {status}
    </span>
  );
}

function formatTime(value: string | null): string {
  return value
    ? new Date(value).toLocaleString([], { dateStyle: 'medium', timeStyle: 'medium' })
    : 'Never';
}

function formatDetail(value: unknown): string {
  if (value === null || value === undefined) return 'Not observed';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  if (typeof value === 'number') return value.toLocaleString();
  if (Array.isArray(value)) return value.join(', ') || 'None';
  return String(value);
}

export default function SystemHealth({ snapshot: initialSnapshot, canRunDiagnostics }: SystemHealthProps) {
  const [snapshot, setSnapshot] = useState(initialSnapshot);
  const [running, setRunning] = useState(false);
  const [diagnosticError, setDiagnosticError] = useState<string | null>(null);
  const [filter, setFilter] = useState<HealthFilter>(initialFilter);

  const metrics: AdminMetric[] = [
    { label: 'Overall readiness', value: snapshot.overallStatus, tone: snapshot.overallStatus === 'critical' ? 'critical' : snapshot.overallStatus === 'healthy' ? 'default' : 'warning' },
    { label: 'Healthy', value: snapshot.counts.healthy },
    { label: 'Warning', value: snapshot.counts.warning, tone: snapshot.counts.warning > 0 ? 'warning' : 'default' },
    { label: 'Critical', value: snapshot.counts.critical, tone: snapshot.counts.critical > 0 ? 'critical' : 'default' },
    { label: 'Unknown', value: snapshot.counts.unknown, tone: snapshot.counts.unknown > 0 ? 'warning' : 'default' },
    { label: 'Disabled', value: snapshot.counts.disabled },
    { label: 'Required attention', value: snapshot.counts.requiredAttention, tone: snapshot.counts.requiredAttention > 0 ? 'critical' : 'default' },
  ];

  const runDiagnostics = async () => {
    setRunning(true);
    setDiagnosticError(null);
    try {
      const response = await axios.post<HealthSnapshot>('/admin/system-health/diagnostics');
      setSnapshot(response.data);
    } catch (error) {
      setDiagnosticError(
        axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
          ? error.response.data.message
          : 'The bounded diagnostic did not complete. Review the audit trail and application logs.',
      );
    } finally {
      setRunning(false);
    }
  };

  const selected = snapshot.selectedComponent;
  const visibleObservations = useMemo(() => snapshot.observations.filter((observation) => {
    if (filter === 'all') return true;
    if (filter === 'attention') return observation.required && observation.status !== 'healthy';
    return observation.status === filter;
  }), [filter, snapshot.observations]);

  const selectFilter = (value: HealthFilter) => {
    setFilter(value);
    if (typeof window !== 'undefined') {
      const url = new URL(window.location.href);
      if (value === 'all') url.searchParams.delete('status');
      else url.searchParams.set('status', value);
      window.history.replaceState({}, '', url);
    }
  };

  return (
    <DashboardLayout>
      <Head title={selected ? `${selected.label} — System Health` : 'System Health'} />
      <PageContentLayout
        title={selected ? selected.label : 'System Health'}
        subtitle="Append-only, freshness-aware operational evidence; unknown is never presented as healthy"
        headerContent={null}
      >
        <div className="space-y-5">
          {selected ? (
            <Link href="/admin/system-health" className="inline-flex items-center gap-1 text-sm font-medium text-healthcare-info dark:text-healthcare-info-dark">
              <ArrowLeft className="h-4 w-4" aria-hidden="true" />
              All system health
            </Link>
          ) : null}

          <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-healthcare-border bg-healthcare-surface p-3 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <div className="flex min-w-0 items-start gap-3">
              <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-healthcare-info/10 text-healthcare-info dark:text-healthcare-info-dark">
                <Activity className="h-5 w-5" aria-hidden="true" />
              </span>
              <div>
                <div className="flex flex-wrap items-center gap-2">
                  <p className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Platform readiness</p>
                  <StatusBadge status={snapshot.overallStatus} />
                </div>
                <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Last scheduler evidence: {formatTime(snapshot.lastScheduledAt)}. Observations expire after {Math.round(snapshot.contract.freshForSeconds / 60)} minutes.
                </p>
              </div>
            </div>
            {canRunDiagnostics ? (
              <button
                type="button"
                onClick={runDiagnostics}
                disabled={running}
                className="inline-flex items-center gap-2 rounded-md bg-healthcare-info px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60"
              >
                <RefreshCw className={`h-4 w-4 ${running ? 'animate-spin' : ''}`} aria-hidden="true" />
                {running ? 'Running bounded diagnostics…' : 'Run bounded diagnostics'}
              </button>
            ) : (
              <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">runDiagnostics capability required</span>
            )}
          </div>

          {diagnosticError ? (
            <div role="alert" className="flex items-start gap-2 rounded-md border border-healthcare-critical/40 bg-healthcare-critical/10 p-3 text-sm text-healthcare-critical dark:text-healthcare-critical-dark">
              <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
              {diagnosticError}
            </div>
          ) : null}

          <AdminMetricStrip metrics={metrics} />

          <div className="rounded-md border border-healthcare-info/30 bg-healthcare-info/5 p-3 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Diagnostic boundary</p>
            <p className="mt-1">Checks are PHI-free and bounded. They do not call an EHR, read backup contents, expose filesystem paths, test with credentials, advance cursors, replay messages, or mutate healthcare data. External dependencies remain unknown until explicit heartbeat evidence exists.</p>
          </div>

          {selected ? (
            <section>
              <AdminSectionHeading title="Component evidence" description="Latest effective observation and sanitized diagnostic facts" />
              <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                <div className="flex flex-wrap items-center gap-2">
                  <StatusBadge status={selected.status} />
                  {selected.required ? <span className="text-xs font-semibold uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Required</span> : <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Optional</span>}
                  {selected.stale ? <span className="text-xs font-semibold text-healthcare-warning dark:text-healthcare-warning-dark">Expired evidence</span> : null}
                </div>
                <p className="mt-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{selected.summary}</p>
                <dl className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                  <div><dt className="text-xs font-medium text-healthcare-text-secondary">Owner</dt><dd className="mt-1 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{selected.owner}</dd></div>
                  <div><dt className="text-xs font-medium text-healthcare-text-secondary">Observed</dt><dd className="mt-1 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{formatTime(selected.observedAt)}</dd></div>
                  <div><dt className="text-xs font-medium text-healthcare-text-secondary">Origin / duration</dt><dd className="mt-1 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{selected.origin ?? 'None'} / {selected.durationMs === null ? 'Not observed' : `${selected.durationMs} ms`}</dd></div>
                  <div><dt className="text-xs font-medium text-healthcare-text-secondary">Runbook</dt><dd className="mt-1 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{selected.runbookUrl ? <a className="text-healthcare-info" href={selected.runbookUrl}>{selected.runbookRef}</a> : selected.runbookRef}</dd></div>
                </dl>
                {selected.errorCode ? <p className="mt-3 font-mono text-xs text-healthcare-text-secondary">Error code: {selected.errorCode}</p> : null}
                <dl className="mt-4 grid gap-2 sm:grid-cols-2">
                  {Object.entries(selected.details).map(([key, value]) => (
                    <div key={key} className="rounded-md bg-healthcare-surface-secondary px-3 py-2 dark:bg-healthcare-surface-hover-dark">
                      <dt className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{key.replace(/([A-Z])/g, ' $1').replaceAll('_', ' ')}</dt>
                      <dd className="mt-0.5 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{formatDetail(value)}</dd>
                    </div>
                  ))}
                </dl>
              </div>
            </section>
          ) : (
            <section>
              <AdminSectionHeading title="Observed components" description="Required and optional dependencies with freshness, ownership, and drill-through" />
              <div className="mb-2 flex flex-wrap gap-1" role="group" aria-label="Filter health components">
                {(['all', 'attention', 'critical', 'warning', 'unknown', 'healthy', 'disabled'] as HealthFilter[]).map((value) => (
                  <button
                    key={value}
                    type="button"
                    aria-pressed={filter === value}
                    onClick={() => selectFilter(value)}
                    className={`rounded-md px-2.5 py-1 text-xs font-medium capitalize ${filter === value ? 'bg-healthcare-primary text-white' : 'bg-healthcare-surface-secondary text-healthcare-text-secondary dark:bg-healthcare-surface-hover-dark dark:text-healthcare-text-secondary-dark'}`}
                  >
                    {value}
                  </button>
                ))}
              </div>
              <div className="overflow-x-auto rounded-md border border-healthcare-border bg-healthcare-surface shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
                <table className="min-w-full divide-y divide-healthcare-border text-sm dark:divide-healthcare-border-dark">
                  <thead className="bg-healthcare-surface-secondary dark:bg-healthcare-surface-hover-dark">
                    <tr>
                      {['Component', 'Effective state', 'Evidence', 'Owner', 'Observed', ''].map((label) => (
                        <th key={label} scope="col" className="whitespace-nowrap px-3 py-2 text-left text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                    {visibleObservations.length === 0 ? (
                      <tr><td colSpan={6} className="px-4 py-8 text-center text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No components match this filter.</td></tr>
                    ) : visibleObservations.map((observation) => (
                      <tr key={observation.key}>
                        <td className="min-w-52 px-3 py-2">
                          <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{observation.label}</p>
                          <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{observation.category} · {observation.required ? 'Required' : 'Optional'}</p>
                        </td>
                        <td className="whitespace-nowrap px-3 py-2"><StatusBadge status={observation.status} /></td>
                        <td className="min-w-72 px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          {observation.summary}
                          {observation.errorCode ? <span className="mt-0.5 block font-mono text-xs">{observation.errorCode}</span> : null}
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{observation.owner}</td>
                        <td className="whitespace-nowrap px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{formatTime(observation.observedAt)}</td>
                        <td className="px-3 py-2 text-right"><Link href={observation.href} aria-label={`View ${observation.label}`} className="inline-flex rounded-md p-1.5 text-healthcare-info hover:bg-healthcare-hover dark:text-healthcare-info-dark dark:hover:bg-healthcare-hover-dark"><ArrowRight className="h-4 w-4" aria-hidden="true" /></Link></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          )}

          <section>
            <AdminSectionHeading title="Status semantics" description="Operational meaning is explicit and stable" />
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
              {[
                ['healthy', CheckCircle2, 'Fresh evidence satisfies policy.'],
                ['warning', AlertTriangle, 'Usable, but operator attention is due.'],
                ['critical', ShieldAlert, 'Required behavior or policy failed.'],
                ['unknown', CircleDashed, 'No current evidence; never assumed healthy.'],
                ['disabled', Clock3, 'Intentionally inactive by deployment policy.'],
              ].map(([status, Icon, detail]) => (
                <div key={status as string} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                  <Icon className="h-4 w-4 text-healthcare-text-secondary" aria-hidden="true" />
                  <p className="mt-2 text-sm font-semibold capitalize text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{status as string}</p>
                  <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{detail as string}</p>
                </div>
              ))}
            </div>
          </section>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}
