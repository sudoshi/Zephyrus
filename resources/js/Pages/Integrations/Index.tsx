import PageContentLayout from '@/Components/Common/PageContentLayout';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import AdminScopeSelector from '@/Components/Admin/AdminScopeSelector';
import { CredentialConfiguration, EndpointConfiguration, SourceConfiguration } from '@/Components/Integrations/ConfigurationForms';
import { CredentialAuthorityConsole, NetworkRouteConfiguration } from '@/Components/Integrations/CredentialNetworkGovernance';
import {
  useIntegrationControlPlane,
  usePreviewIntegrationReplay,
  useRequestIntegrationReplay,
  useQueueEpicFhirPoll,
  useQueueIntegrationHealthCheck,
  useQueueIntegrationReplay,
  useSourceObservability,
  useCollectSourceObservation,
} from '@/features/integrations/hooks';
import { executeCredentialRotation, type CredentialRotationInput, type IntegrationControlPlane, type IntegrationReplayInput, type IntegrationSource } from '@/features/integrations/api';
import type { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
  Activity,
  ArrowRightLeft,
  Cable,
  Database,
  FileWarning,
  HeartPulse,
  History,
  KeyRound,
  Network,
  RefreshCcw,
  Send,
  ShieldCheck,
} from 'lucide-react';
import { useEffect, useState, type ComponentType, type ReactNode } from 'react';
import { formatDurationMinutes } from '@/lib/duration';

type TabId =
  | 'overview'
  | 'observability'
  | 'sources'
  | 'fhir'
  | 'hl7'
  | 'applications'
  | 'mappings'
  | 'runs'
  | 'dead-letters'
  | 'outbound'
  | 'credentials'
  | 'audit';

const tabs: { id: TabId; label: string; icon: ComponentType<{ className?: string }> }[] = [
  { id: 'overview', label: 'Overview', icon: Activity },
  { id: 'observability', label: 'SLOs & Health', icon: HeartPulse },
  { id: 'sources', label: 'Source Systems', icon: Database },
  { id: 'fhir', label: 'FHIR R4 / SMART', icon: HeartPulse },
  { id: 'hl7', label: 'HL7 v2 Interfaces', icon: Cable },
  { id: 'applications', label: 'Transactional Apps', icon: Network },
  { id: 'mappings', label: 'Mappings', icon: ArrowRightLeft },
  { id: 'runs', label: 'Runs & Watermarks', icon: History },
  { id: 'dead-letters', label: 'Dead Letters', icon: FileWarning },
  { id: 'outbound', label: 'Outbound', icon: Send },
  { id: 'credentials', label: 'Credentials', icon: KeyRound },
  { id: 'audit', label: 'Audit', icon: ShieldCheck },
];

function initialTab(): TabId {
  if (typeof window === 'undefined') return 'overview';
  const candidate = new URLSearchParams(window.location.search).get('tab');
  return tabs.some(({ id }) => id === candidate) ? candidate as TabId : 'overview';
}

const statusClasses: Record<string, string> = {
  healthy: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  completed: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  approved: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  active: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  ready: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  validated: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  failed: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  stale: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  open: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  blocked: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  revoked: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  expired: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  maintenance: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  unknown: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  unobserved: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  pending: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  pending_approval: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  not_ready: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  rotating: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  running: 'border-healthcare-info/40 bg-healthcare-info/10 text-healthcare-info dark:text-healthcare-info-dark',
  configured: 'border-healthcare-info/40 bg-healthcare-info/10 text-healthcare-info dark:text-healthcare-info-dark',
  template: 'border-healthcare-border bg-healthcare-hover text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-hover-dark dark:text-healthcare-text-secondary-dark',
  unconfigured: 'border-healthcare-border bg-healthcare-hover text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-hover-dark dark:text-healthcare-text-secondary-dark',
  not_configured: 'border-healthcare-border bg-healthcare-hover text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-hover-dark dark:text-healthcare-text-secondary-dark',
  disabled: 'border-healthcare-border bg-healthcare-hover text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-hover-dark dark:text-healthcare-text-secondary-dark',
};

function humanize(value: string | null | undefined): string {
  if (!value) return 'Not set';
  return value.replaceAll('_', ' ').replaceAll('-', ' ');
}

function formatTime(value: string | null): string {
  return value ? new Date(value).toLocaleString([], { dateStyle: 'medium', timeStyle: 'medium' }) : 'Never';
}

function localDateTime(value: Date): string {
  const offsetMs = value.getTimezoneOffset() * 60_000;
  return new Date(value.getTime() - offsetMs).toISOString().slice(0, 16);
}

function StatusBadge({ value }: { value: string }) {
  return (
    <span className={`inline-flex whitespace-nowrap rounded-md border px-2 py-0.5 text-xs/[16px] font-semibold ${statusClasses[value] ?? statusClasses.template}`}>
      {humanize(value)}
    </span>
  );
}

function Flag({ enabled, label }: { enabled: boolean; label: string }) {
  return (
    <span className={`inline-flex rounded-md border px-2 py-0.5 text-xs/[16px] font-medium ${enabled
      ? 'border-healthcare-success/30 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark'
      : 'border-healthcare-border text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark'}`}>
      {label}: {enabled ? 'Yes' : 'No'}
    </span>
  );
}

function Metric({ label, value, status }: { label: string; value: number; status?: 'critical' | 'warning' }) {
  const valueClass = status === 'critical'
    ? 'text-healthcare-critical dark:text-healthcare-critical-dark'
    : status === 'warning'
      ? 'text-healthcare-warning dark:text-healthcare-warning-dark'
      : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark';
  return (
    <div className="min-w-0 rounded-md border border-healthcare-border bg-healthcare-surface p-3 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="text-xs/[16px] font-semibold uppercase tracking-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</div>
      <div className={`mt-1 text-2xl/[30px] font-semibold tabular-nums ${valueClass}`}>{value}</div>
    </div>
  );
}

function Panel({ title, actions, children }: { title: string; actions?: ReactNode; children: ReactNode }) {
  return (
    <section className="border-t border-healthcare-border pt-4 dark:border-healthcare-border-dark">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-base/[20px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{title}</h2>
        {actions}
      </div>
      {children}
    </section>
  );
}

function ActionButton({ children, disabled = false, onClick }: { children: ReactNode; disabled?: boolean; onClick: () => void }) {
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      className="inline-flex min-h-8 items-center justify-center rounded-md border border-healthcare-border px-2.5 py-1 text-xs/[16px] font-semibold text-healthcare-text-primary transition hover:bg-healthcare-hover disabled:cursor-not-allowed disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
    >
      {children}
    </button>
  );
}

function EmptyRows({ label }: { label: string }) {
  return (
    <div className="rounded-md border border-dashed border-healthcare-border px-4 py-8 text-center text-sm/[18px] text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
      {label}
    </div>
  );
}

function Table({ headings, children }: { headings: string[]; children: ReactNode }) {
  return (
    <div className="overflow-x-auto rounded-md border border-healthcare-border dark:border-healthcare-border-dark">
      <table className="min-w-full divide-y divide-healthcare-border text-left text-sm/[18px] dark:divide-healthcare-border-dark">
        <thead className="bg-healthcare-hover dark:bg-healthcare-hover-dark">
          <tr>{headings.map((heading) => <th key={heading} scope="col" className="whitespace-nowrap px-3 py-2 text-xs/[16px] font-semibold uppercase tracking-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{heading}</th>)}</tr>
        </thead>
        <tbody className="divide-y divide-healthcare-border bg-healthcare-surface dark:divide-healthcare-border-dark dark:bg-healthcare-surface-dark">{children}</tbody>
      </table>
    </div>
  );
}

const cellClass = 'whitespace-nowrap px-3 py-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark';
const primaryCellClass = 'whitespace-nowrap px-3 py-2 font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark';

function SourceTable({ sources }: { sources: IntegrationSource[] }) {
  if (sources.length === 0) return <EmptyRows label="No integration sources configured." />;
  return (
    <Table headings={['Source', 'Interface', 'Environment', 'Health', 'Last observed', 'Messages', 'Dead letters', 'Governance']}>
      {sources.map((source) => (
        <tr key={source.sourceId}>
          <td className={primaryCellClass}>
            <div>{source.sourceName}</div>
            <div className="text-xs/[16px] font-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{source.vendor ?? source.systemClass}</div>
          </td>
          <td className={cellClass}>{humanize(source.interfaceType)}</td>
          <td className={cellClass}>{humanize(source.environment)}</td>
          <td className={cellClass}>
            <StatusBadge value={source.healthStatus} />
            <div className="mt-1 text-xs/[16px]">
              SLO: {humanize(source.observabilityStatus)} · {source.sloSummary.met} met / {source.sloSummary.breached} breached / {source.sloSummary.unknown} unknown
            </div>
            {source.openSloBreaches > 0 ? <div className="text-xs/[16px] text-healthcare-critical dark:text-healthcare-critical-dark">{source.openSloBreaches} open breach{source.openSloBreaches === 1 ? '' : 'es'}</div> : null}
          </td>
          <td className={cellClass}>{formatTime(source.healthObservedAtIso ?? source.lastObservedAtIso)}</td>
          <td className={`${cellClass} tabular-nums`}>{source.counts.inboundMessages}</td>
          <td className={`${cellClass} tabular-nums`}>{source.counts.openDeadLetters}</td>
          <td className={cellClass}>
            <div className="flex flex-wrap gap-1">
              <Flag enabled={source.phiAllowed} label="PHI" />
              <span className="rounded-md border border-healthcare-border px-2 py-0.5 text-xs/[16px] dark:border-healthcare-border-dark">BAA: {humanize(source.baaStatus)}</span>
            </div>
          </td>
        </tr>
      ))}
    </Table>
  );
}

function OverviewPanel({ data }: { data: IntegrationControlPlane }) {
  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        <Metric label="Sources" value={data.counts.sources} />
        <Metric label="Healthy" value={data.counts.healthySources} />
        <Metric label="Degraded" value={data.counts.degradedSources} status={data.counts.degradedSources ? 'warning' : undefined} />
        <Metric label="Stale" value={data.counts.staleSources} status={data.counts.staleSources ? 'critical' : undefined} />
        <Metric label="Open Dead Letters" value={data.counts.openDeadLetters} status={data.counts.openDeadLetters ? 'critical' : undefined} />
        <Metric label="Projection Backlog" value={data.counts.pendingProjectionEvents} status={data.counts.pendingProjectionEvents ? 'warning' : undefined} />
        <Metric label="Open SLO Breaches" value={data.counts.openSloBreaches} status={data.counts.openSloBreaches ? 'critical' : undefined} />
      </div>
      <Panel title="Source Health" actions={<span className="text-xs/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Stale after {formatDurationMinutes(data.freshnessPolicy.staleAfterMinutes)}</span>}>
        <SourceTable sources={data.sources} />
      </Panel>
      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
        <Metric label="Ingest Runs" value={data.counts.ingestRuns} />
        <Metric label="Inbound Messages" value={data.counts.inboundMessages} />
        <Metric label="Canonical Events" value={data.counts.canonicalEvents} />
        <Metric label="Open Projection Errors" value={data.counts.openProjectionErrors} status={data.counts.openProjectionErrors ? 'critical' : undefined} />
        <Metric label="Queued Jobs" value={data.counts.queuedJobs} status={data.counts.queuedJobs > 100 ? 'warning' : undefined} />
        <Metric label="Failed Queue Jobs" value={data.counts.failedQueueJobs} status={data.counts.failedQueueJobs ? 'critical' : undefined} />
      </div>
    </div>
  );
}

export function ObservabilityPanel({
  selectedSourceId,
  canOperateIntegrations,
}: {
  selectedSourceId: number | null;
  canOperateIntegrations: boolean;
}) {
  const observability = useSourceObservability(selectedSourceId);
  const collectObservation = useCollectSourceObservation();
  if (selectedSourceId === null) {
    return <EmptyRows label="Select an exact organization, facility, and source scope to inspect its SLO history." />;
  }
  if (observability.isLoading) {
    return <EmptyRows label="Loading source SLO history…" />;
  }
  if (observability.isError || !observability.data) {
    return <div role="alert" className="rounded-md border border-healthcare-critical/40 bg-healthcare-critical/10 p-4 text-sm text-healthcare-critical dark:text-healthcare-critical-dark">Source observability history is unavailable.</div>;
  }

  const { current, openBreaches, history } = observability.data;
  const queue = current?.queueState ?? {};
  const runtime = current?.runtimeState ?? {};
  const circuit = typeof runtime.circuitBreaker === 'object' && runtime.circuitBreaker !== null
    ? runtime.circuitBreaker as Record<string, unknown>
    : {};
  const rateLimit = typeof runtime.rateLimit === 'object' && runtime.rateLimit !== null
    ? runtime.rateLimit as Record<string, unknown>
    : {};
  const retryBudget = typeof runtime.retryBudget === 'object' && runtime.retryBudget !== null
    ? runtime.retryBudget as Record<string, unknown>
    : {};

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-3xl text-sm/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Observations are append-only and PHI-free. Missing measurements remain unknown; planned maintenance can suppress notification delivery but does not erase a breach.
        </p>
        {canOperateIntegrations ? (
          <ActionButton
            disabled={collectObservation.isPending}
            onClick={() => collectObservation.mutate(selectedSourceId, { onSuccess: () => observability.refetch() })}
          >
            {collectObservation.isPending ? 'Observing…' : 'Observe now'}
          </ActionButton>
        ) : <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">operateIntegrations capability required</span>}
      </div>

      {current ? (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Metric label="Met SLOs" value={current.summary.met ?? 0} />
            <Metric label="Breached SLOs" value={current.summary.breached ?? 0} status={(current.summary.breached ?? 0) > 0 ? 'critical' : undefined} />
            <Metric label="Unknown SLOs" value={current.summary.unknown ?? 0} status={(current.summary.unknown ?? 0) > 0 ? 'warning' : undefined} />
            <Metric label="Open Breaches" value={openBreaches.length} status={openBreaches.length > 0 ? 'critical' : undefined} />
          </div>
          <Panel title="Current Source Truth" actions={<StatusBadge value={current.stale ? 'stale' : current.status} />}>
            <dl className="grid gap-3 rounded-md border border-healthcare-border p-3 text-sm md:grid-cols-2 xl:grid-cols-4 dark:border-healthcare-border-dark">
              <div><dt className="text-xs font-semibold uppercase text-healthcare-text-secondary">Observed</dt><dd>{formatTime(current.observedAtIso)}</dd></div>
              <div><dt className="text-xs font-semibold uppercase text-healthcare-text-secondary">Fresh until</dt><dd>{formatTime(current.freshUntilIso)}</dd></div>
              <div><dt className="text-xs font-semibold uppercase text-healthcare-text-secondary">Protocol</dt><dd>{humanize(current.protocolStatus)}</dd></div>
              <div><dt className="text-xs font-semibold uppercase text-healthcare-text-secondary">Maintenance</dt><dd>{current.maintenanceActive ? 'Active; notification suppressed' : 'Inactive'}</dd></div>
              <div><dt className="text-xs font-semibold uppercase text-healthcare-text-secondary">Backpressure</dt><dd>{humanize(String(queue.backpressureStatus ?? 'unknown'))}</dd></div>
              <div><dt className="text-xs font-semibold uppercase text-healthcare-text-secondary">Source queue depth</dt><dd>{String(queue.sourceActiveRunDepth ?? 'Unknown')}</dd></div>
              <div><dt className="text-xs font-semibold uppercase text-healthcare-text-secondary">Circuit breaker</dt><dd>{humanize(String(circuit.state ?? 'unknown'))}</dd></div>
              <div><dt className="text-xs font-semibold uppercase text-healthcare-text-secondary">Rate limit</dt><dd>{humanize(String(rateLimit.state ?? 'unknown'))}</dd></div>
              <div><dt className="text-xs font-semibold uppercase text-healthcare-text-secondary">Retry budget</dt><dd>{humanize(String(retryBudget.state ?? 'unknown'))}</dd></div>
              <div className="md:col-span-2"><dt className="text-xs font-semibold uppercase text-healthcare-text-secondary">Evidence fingerprint</dt><dd className="break-all font-mono text-xs">{current.evidenceSha256}</dd></div>
            </dl>
          </Panel>
        </>
      ) : <EmptyRows label="No source health observation has been recorded yet. The scheduled collector runs every minute." />}

      <Panel title="Open SLO Breaches">
        {openBreaches.length === 0 ? <EmptyRows label="No open SLO breach is recorded for this source." /> : (
          <Table headings={['Metric', 'Status', 'Notification', 'Opened', 'Last observed']}>
            {openBreaches.map((breach) => (
              <tr key={breach.breachUuid}>
                <td className={primaryCellClass}>{humanize(breach.metricKey)}</td>
                <td className={cellClass}><StatusBadge value={breach.status} /></td>
                <td className={cellClass}>{breach.notificationSuppressed ? 'Suppressed by maintenance' : 'Eligible for alerting'}</td>
                <td className={cellClass}>{formatTime(breach.openedAtIso)}</td>
                <td className={cellClass}>{formatTime(breach.lastObservedAtIso)}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>

      <Panel title="Append-only Observation History">
        {history.length === 0 ? <EmptyRows label="No observation history is available." /> : (
          <Table headings={['Observation', 'Status', 'Protocol', 'SLOs', 'Origin', 'Observed', 'Fresh until']}>
            {history.map((observation) => (
              <tr key={observation.observationUuid}>
                <td className={`${primaryCellClass} tabular-nums`}>#{observation.observationId}</td>
                <td className={cellClass}><StatusBadge value={observation.status} /></td>
                <td className={cellClass}>{humanize(observation.protocolStatus)}</td>
                <td className={cellClass}>{observation.summary.met ?? 0} met · {observation.summary.breached ?? 0} breached · {observation.summary.unknown ?? 0} unknown</td>
                <td className={cellClass}>{humanize(observation.origin)}</td>
                <td className={cellClass}>{formatTime(observation.observedAtIso)}</td>
                <td className={cellClass}>{formatTime(observation.freshUntilIso)}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </div>
  );
}

function SourcesPanel({ data, selectedSourceId, hasFacilityScope }: { data: IntegrationControlPlane; selectedSourceId: number | null; hasFacilityScope: boolean }) {
  return (
    <div className="space-y-5">
      <Panel title="Source Administration"><SourceConfiguration data={data} selectedSourceId={selectedSourceId} hasFacilityScope={hasFacilityScope} /></Panel>
      <Panel title="Configured Sources"><SourceTable sources={data.sources} /></Panel>
      <Panel title="Endpoint Administration"><EndpointConfiguration data={data} selectedSourceId={selectedSourceId} /></Panel>
      <Panel title="Endpoint Security Posture">
        {data.endpoints.length === 0 ? <EmptyRows label="No endpoints configured." /> : (
          <Table headings={['Source', 'Endpoint', 'Authentication', 'TLS', 'URL', 'State']}>
            {data.endpoints.map((endpoint) => (
              <tr key={endpoint.endpointId}>
                <td className={primaryCellClass}>{endpoint.sourceName}</td>
                <td className={cellClass}>{humanize(endpoint.endpointType)}</td>
                <td className={cellClass}>{humanize(endpoint.authType)}</td>
                <td className={cellClass}>{humanize(endpoint.tlsMode)}</td>
                <td className={cellClass}>{endpoint.urlConfigured ? 'Configured' : 'Missing'}</td>
                <td className={cellClass}><StatusBadge value={endpoint.isActive ? 'active' : 'disabled'} /></td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </div>
  );
}

function FhirPanel({ data, selectedSourceId }: { data: IntegrationControlPlane; selectedSourceId: number | null }) {
  const smartCredentials = data.credentials.filter((credential) => credential.credentialType === 'smart_backend_services');
  const healthCheck = useQueueIntegrationHealthCheck();
  const poll = useQueueEpicFhirPoll();
  return (
    <div className="space-y-5">
      <Panel title="FHIR R4 Connections">
        {data.fhirConnections.length === 0 ? <EmptyRows label="No FHIR R4 connections configured." /> : (
          <Table headings={['Source', 'Connection', 'FHIR Version', 'Status', 'Protocol Health', 'Capability Check', 'Resources', 'Controls']}>
            {data.fhirConnections.map((connection) => (
              <tr key={connection.connectionId}>
                <td className={primaryCellClass}>{connection.sourceName}</td>
                <td className={cellClass}>{connection.connectionKey}</td>
                <td className={cellClass}>{connection.fhirVersion ?? 'Unknown'}</td>
                <td className={cellClass}><StatusBadge value={connection.status} /></td>
                <td className={cellClass}>
                  <StatusBadge value={connection.healthStatus} />
                  {connection.healthErrorCode && <div className="mt-1 text-xs/[16px]">{humanize(connection.healthErrorCode)}</div>}
                </td>
                <td className={cellClass}>{formatTime(connection.capabilityCheckedAtIso)}</td>
                <td className={`${cellClass} tabular-nums`}>{connection.supportedResourceCount}</td>
                <td className={cellClass}>
                  <div className="flex min-w-max flex-wrap gap-1.5">
                    <ActionButton disabled={healthCheck.isPending || selectedSourceId !== connection.sourceId} onClick={() => healthCheck.mutate(connection.sourceId)}>Check</ActionButton>
                    <ActionButton disabled={poll.isPending || selectedSourceId !== connection.sourceId || !['ready', 'active'].includes(connection.status)} onClick={() => poll.mutate({ sourceId: connection.sourceId, resourceType: 'Encounter' })}>Poll Encounter</ActionButton>
                    <ActionButton disabled={poll.isPending || selectedSourceId !== connection.sourceId || !['ready', 'active'].includes(connection.status)} onClick={() => poll.mutate({ sourceId: connection.sourceId, resourceType: 'Location' })}>Poll Location</ActionButton>
                  </div>
                </td>
              </tr>
            ))}
          </Table>
        )}
        {(healthCheck.isSuccess || poll.isSuccess) && <p role="status" className="mt-2 text-xs/[16px] text-healthcare-success dark:text-healthcare-success-dark">The integration run was queued on the supervised worker.</p>}
        {(healthCheck.isError || poll.isError) && <p role="alert" className="mt-2 text-xs/[16px] text-healthcare-critical dark:text-healthcare-critical-dark">The run could not be queued. Verify activation and protocol health.</p>}
      </Panel>
      <Panel title="SMART Backend Services">
        {smartCredentials.length === 0 ? <EmptyRows label="No SMART Backend Services credentials configured." /> : (
          <Table headings={['Source', 'Credential', 'Status', 'Client ID', 'Token Endpoint', 'Key Reference', 'Rotation']}>
            {smartCredentials.map((credential) => (
              <tr key={credential.credentialId}>
                <td className={primaryCellClass}>{credential.sourceName}</td>
                <td className={cellClass}>{credential.credentialKey}</td>
                <td className={cellClass}><StatusBadge value={credential.status} /></td>
                <td className={cellClass}>{credential.clientIdConfigured ? 'Configured' : 'Missing'}</td>
                <td className={cellClass}>{credential.tokenEndpointConfigured ? 'Configured' : 'Missing'}</td>
                <td className={cellClass}>{credential.secretReferenceConfigured ? 'Configured' : 'Missing'}</td>
                <td className={cellClass}>{formatTime(credential.rotatesAtIso)}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </div>
  );
}

function Hl7Panel({ data, selectedSourceId }: { data: IntegrationControlPlane; selectedSourceId: number | null }) {
  const hl7Sources = data.sources.filter((source) => source.interfaceType.toLowerCase().includes('hl7'));
  const healthCheck = useQueueIntegrationHealthCheck();
  return (
    <div className="space-y-5">
      <Panel title="Interface Engine Boundary">
        {data.hl7Interfaces.length === 0 ? <EmptyRows label="No HL7 v2 interface engine boundary configured." /> : (
          <Table headings={['Engine', 'Type', 'Environment', 'Status']}>
            {data.hl7Interfaces.map((item) => (
              <tr key={item.interfaceEngineId}>
                <td className={primaryCellClass}>{item.label}</td>
                <td className={cellClass}>{humanize(item.engineType)}</td>
                <td className={cellClass}>{humanize(item.environment)}</td>
                <td className={cellClass}><StatusBadge value={item.status} /></td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      <Panel title="HL7 v2 Sources"><SourceTable sources={hl7Sources} /></Panel>
      <Panel title="Ingress Health Controls">
        {hl7Sources.length === 0 ? <EmptyRows label="No HL7 v2 source is configured for a protocol check." /> : (
          <div className="space-y-2">
            {hl7Sources.map((source) => (
              <div key={source.sourceId} className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                <div>
                  <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{source.sourceName}</div>
                  <div className="text-xs/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Protocol: {humanize(source.protocolHealthStatus)} · checked {formatTime(source.protocolHealthCheckedAtIso)}</div>
                </div>
                <ActionButton disabled={healthCheck.isPending || selectedSourceId !== source.sourceId} onClick={() => healthCheck.mutate(source.sourceId)}>Check boundary</ActionButton>
              </div>
            ))}
          </div>
        )}
      </Panel>
    </div>
  );
}

function ApplicationsPanel({ data }: { data: IntegrationControlPlane }) {
  return (
    <div className="space-y-5">
      <Panel title="Transactional Coverage">
        <Table headings={['Priority', 'Application Family', 'Protocols', 'Operational Value']}>
          {data.connectorFamilies.map((family) => (
            <tr key={family.family}>
              <td className={`${cellClass} tabular-nums`}>P{family.priority}</td>
              <td className={primaryCellClass}>{family.family}</td>
              <td className={cellClass}>{family.protocols.join(', ')}</td>
              <td className={`${cellClass} whitespace-normal`}>{family.operationalValue}</td>
            </tr>
          ))}
        </Table>
      </Panel>
      <Panel title="Connector Templates">
        {data.templates.playbooks.length === 0 ? <EmptyRows label="No connector templates loaded." /> : (
          <Table headings={['Vendor', 'System Class', 'Status', 'Capabilities']}>
            {data.templates.playbooks.map((template) => (
              <tr key={template.vendorKey}>
                <td className={primaryCellClass}>{template.label}</td>
                <td className={cellClass}>{humanize(template.systemClass)}</td>
                <td className={cellClass}><StatusBadge value={template.status} /></td>
                <td className={cellClass}>{Object.keys(template.capabilities).map(humanize).join(', ') || 'None'}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </div>
  );
}

function MappingsPanel({ data }: { data: IntegrationControlPlane }) {
  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-3">
        <Metric label="Terminology Maps" value={data.counts.terminologyMaps} />
        <Metric label="Approved" value={data.mappings.approved} />
        <Metric label="Pending Review" value={data.mappings.pendingReview} status={data.mappings.pendingReview ? 'warning' : undefined} />
      </div>
      <Panel title="Mapping Inventory">
        {data.mappings.byType.length === 0 ? <EmptyRows label="No terminology mappings configured." /> : (
          <Table headings={['Map Type', 'Mappings']}>
            {data.mappings.byType.map((mapping) => (
              <tr key={mapping.mapType}>
                <td className={primaryCellClass}>{humanize(mapping.mapType)}</td>
                <td className={`${cellClass} tabular-nums`}>{mapping.count}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </div>
  );
}

function RunsPanel({ data }: { data: IntegrationControlPlane }) {
  return (
    <div className="space-y-5">
      <Panel title="Recent Runs">
        {data.runs.length === 0 ? <EmptyRows label="No ingest runs recorded." /> : (
          <Table headings={['Source', 'Connector', 'Type', 'Status', 'Started', 'Received', 'Succeeded', 'Failed']}>
            {data.runs.map((run) => (
              <tr key={run.runId}>
                <td className={primaryCellClass}>{run.sourceName}</td>
                <td className={cellClass}>{run.connectorKey}</td>
                <td className={cellClass}>{humanize(run.runType)}</td>
                <td className={cellClass}><StatusBadge value={run.status} /></td>
                <td className={cellClass}>{formatTime(run.startedAtIso)}</td>
                <td className={`${cellClass} tabular-nums`}>{run.messagesReceived}</td>
                <td className={`${cellClass} tabular-nums`}>{run.messagesSucceeded}</td>
                <td className={`${cellClass} tabular-nums`}>{run.messagesFailed}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      <Panel title="Watermarks">
        {data.watermarks.length === 0 ? <EmptyRows label="No connector watermarks recorded." /> : (
          <Table headings={['Source', 'Connector', 'Scope', 'Kind', 'Cursor', 'Last Success']}>
            {data.watermarks.map((watermark) => (
              <tr key={watermark.watermarkId}>
                <td className={primaryCellClass}>{watermark.sourceName}</td>
                <td className={cellClass}>{watermark.connectorKey}</td>
                <td className={cellClass}>{watermark.scopeKeyConfigured ? `${watermark.scopeType}: scoped` : watermark.scopeType}</td>
                <td className={cellClass}>{humanize(watermark.watermarkKind)}</td>
                <td className={cellClass}>{watermark.cursorStored ? 'Stored' : 'Missing'}</td>
                <td className={cellClass}>{formatTime(watermark.lastSuccessAtIso)}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </div>
  );
}

function DeadLettersPanel({ data, selectedSourceId }: { data: IntegrationControlPlane; selectedSourceId: number | null }) {
  const preview = usePreviewIntegrationReplay();
  const requestReplay = useRequestIntegrationReplay();
  const replay = useQueueIntegrationReplay();
  const [sourceId, setSourceId] = useState(selectedSourceId?.toString() ?? '');
  const [from, setFrom] = useState(() => localDateTime(new Date(Date.now() - 24 * 60 * 60 * 1000)));
  const [to, setTo] = useState(() => localDateTime(new Date()));
  const [reason, setReason] = useState('');
  const [changeRequestUuid, setChangeRequestUuid] = useState('');
  const [previewedInput, setPreviewedInput] = useState<IntegrationReplayInput | null>(null);
  const replayInput = (): IntegrationReplayInput => ({
    source_id: Number(sourceId),
    from: new Date(from).toISOString(),
    to: new Date(to).toISOString(),
    limit: 500,
  });
  const resetReplayScope = () => {
    setPreviewedInput(null);
    preview.reset();
    replay.reset();
  };
  useEffect(() => {
    setSourceId(selectedSourceId?.toString() ?? '');
    setPreviewedInput(null);
    setChangeRequestUuid('');
    preview.reset();
    requestReplay.reset();
    replay.reset();
  }, [selectedSourceId]);
  const selectedSources = selectedSourceId ? data.sources.filter((source) => source.sourceId === selectedSourceId) : [];
  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-3">
        <Metric label="Open Dead Letters" value={data.counts.openDeadLetters} status={data.counts.openDeadLetters ? 'critical' : undefined} />
        <Metric label="Projection Errors" value={data.counts.openProjectionErrors} status={data.counts.openProjectionErrors ? 'critical' : undefined} />
        <Metric label="Replay Jobs" value={data.counts.replayJobs} />
      </div>
      <Panel title="Dead Letter Queue">
        {data.deadLetters.length === 0 ? <EmptyRows label="No dead letters recorded." /> : (
          <Table headings={['ID', 'Source', 'Stage', 'Reason', 'Status', 'Created', 'Replayed']}>
            {data.deadLetters.map((item) => (
              <tr key={item.deadLetterId}>
                <td className={`${primaryCellClass} tabular-nums`}>#{item.deadLetterId}</td>
                <td className={cellClass}>{item.sourceName ?? 'Unknown source'}</td>
                <td className={cellClass}>{humanize(item.failureStage)}</td>
                <td className={cellClass}>{humanize(item.reasonCode)}</td>
                <td className={cellClass}><StatusBadge value={item.status} /></td>
                <td className={cellClass}>{formatTime(item.createdAtIso)}</td>
                <td className={cellClass}>{formatTime(item.replayedAtIso)}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      <Panel title="Canonical Projection Replay">
        <div className="grid gap-3 rounded-md border border-healthcare-border p-3 md:grid-cols-4 dark:border-healthcare-border-dark">
          <label className="text-xs/[16px] font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Source
            <select value={sourceId} onChange={(event) => { setSourceId(event.target.value); resetReplayScope(); }} className="mt-1 block min-h-9 w-full rounded-md border border-healthcare-border bg-healthcare-surface px-2 text-sm/[18px] text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark">
              <option value="">Select an active source scope</option>
              {selectedSources.map((source) => <option key={source.sourceId} value={source.sourceId}>{source.sourceName}</option>)}
            </select>
          </label>
          <label className="text-xs/[16px] font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            From
            <input type="datetime-local" value={from} onChange={(event) => { setFrom(event.target.value); resetReplayScope(); }} className="mt-1 block min-h-9 w-full rounded-md border border-healthcare-border bg-healthcare-surface px-2 text-sm/[18px] text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark" />
          </label>
          <label className="text-xs/[16px] font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            To
            <input type="datetime-local" value={to} onChange={(event) => { setTo(event.target.value); resetReplayScope(); }} className="mt-1 block min-h-9 w-full rounded-md border border-healthcare-border bg-healthcare-surface px-2 text-sm/[18px] text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark" />
          </label>
          <div className="flex items-end gap-2">
            <ActionButton disabled={preview.isPending || !sourceId || !from || !to} onClick={() => { const input = replayInput(); preview.mutate(input, { onSuccess: () => setPreviewedInput(input) }); }}>Preview</ActionButton>
          </div>
          <label className="text-xs/[16px] font-semibold text-healthcare-text-secondary md:col-span-3 dark:text-healthcare-text-secondary-dark">
            Governed reason
            <input value={reason} onChange={(event) => setReason(event.target.value)} minLength={10} maxLength={500} placeholder="10–500 characters; no PHI or credentials" className="mt-1 block min-h-9 w-full rounded-md border border-healthcare-border bg-healthcare-surface px-2 text-sm/[18px] text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark" />
          </label>
          <div className="flex items-end">
            <ActionButton disabled={requestReplay.isPending || !previewedInput || !preview.data || preview.data.eligibleEvents === 0 || reason.trim().length < 10} onClick={() => requestReplay.mutate({ input: previewedInput!, reason: reason.trim() }, { onSuccess: (result) => setChangeRequestUuid(result.changeRequestUuid) })}>Request approval</ActionButton>
          </div>
          <label className="text-xs/[16px] font-semibold text-healthcare-text-secondary md:col-span-3 dark:text-healthcare-text-secondary-dark">
            Approved change request UUID
            <input value={changeRequestUuid} onChange={(event) => setChangeRequestUuid(event.target.value.trim())} placeholder="Paste the independently approved request UUID" className="mt-1 block min-h-9 w-full rounded-md border border-healthcare-border bg-healthcare-surface px-2 font-mono text-sm/[18px] text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark" />
          </label>
          <div className="flex items-end">
            <ActionButton disabled={replay.isPending || replay.isSuccess || !previewedInput || !preview.data || preview.data.eligibleEvents === 0 || !changeRequestUuid} onClick={() => replay.mutate({ input: previewedInput!, changeRequestUuid, idempotencyKey: globalThis.crypto?.randomUUID?.() ?? `replay-${Date.now()}` })}>Queue approved replay</ActionButton>
          </div>
        </div>
        <p className="mt-2 text-xs/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Only pending or failed RTDC canonical projections are eligible. Preview is read-only; already-projected events are excluded.</p>
        {preview.data && <p role="status" className="mt-2 text-sm/[18px] text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{preview.data.eligibleEvents} eligible of {preview.data.totalMatchingEvents} matching events{preview.data.truncated ? ' (bounded by the replay limit)' : ''}.</p>}
        {requestReplay.isSuccess && <p role="status" className="mt-2 text-sm/[18px] text-healthcare-warning dark:text-healthcare-warning-dark">Approval request {requestReplay.data.changeRequestUuid} is awaiting an independent decision.</p>}
        {replay.isSuccess && <p role="status" className="mt-2 text-sm/[18px] text-healthcare-success dark:text-healthcare-success-dark">Replay #{replay.data.replayJobId} was queued with an idempotency key.</p>}
        {(preview.isError || requestReplay.isError || replay.isError) && <p role="alert" className="mt-2 text-sm/[18px] text-healthcare-critical dark:text-healthcare-critical-dark">The replay workflow was rejected. Check scope, step-up, independent approval, and the seven-day window.</p>}
      </Panel>
      <Panel title="Replay History">
        {data.replayJobs.length === 0 ? <EmptyRows label="No replay jobs recorded." /> : (
          <Table headings={['ID', 'Type', 'Status', 'Replayed', 'Failed', 'Created']}>
            {data.replayJobs.map((job) => (
              <tr key={job.replayJobId}>
                <td className={`${primaryCellClass} tabular-nums`}>#{job.replayJobId}</td>
                <td className={cellClass}>{humanize(job.replayType)}</td>
                <td className={cellClass}><StatusBadge value={job.status} /></td>
                <td className={`${cellClass} tabular-nums`}>{job.eventsReplayed}</td>
                <td className={`${cellClass} tabular-nums`}>{job.eventsFailed}</td>
                <td className={cellClass}>{formatTime(job.createdAtIso)}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </div>
  );
}

function OutboundPanel({ data }: { data: IntegrationControlPlane }) {
  return (
    <Panel title="Approval-Gated Writeback">
      {data.writebackDrafts.length === 0 ? <EmptyRows label="No writeback drafts recorded." /> : (
        <Table headings={['ID', 'Target', 'Resource', 'Draft Type', 'Status', 'Approval', 'Created', 'Sent']}>
          {data.writebackDrafts.map((draft) => (
            <tr key={draft.writebackDraftId}>
              <td className={`${primaryCellClass} tabular-nums`}>#{draft.writebackDraftId}</td>
              <td className={cellClass}>{draft.targetSystem}</td>
              <td className={cellClass}>{draft.resourceType}</td>
              <td className={cellClass}>{humanize(draft.draftType)}</td>
              <td className={cellClass}><StatusBadge value={draft.status} /></td>
              <td className={cellClass}>{draft.approvalId ? `#${draft.approvalId}` : 'Missing'}</td>
              <td className={cellClass}>{formatTime(draft.createdAtIso)}</td>
              <td className={cellClass}>{formatTime(draft.sentAtIso)}</td>
            </tr>
          ))}
        </Table>
      )}
    </Panel>
  );
}

function CredentialsPanel({ data, selectedSourceId }: { data: IntegrationControlPlane; selectedSourceId: number | null }) {
  return (
    <div className="space-y-5">
      <Panel title="Reference Administration"><CredentialConfiguration data={data} selectedSourceId={selectedSourceId} /></Panel>
      <Panel title="Provider, Rotation, and Certificate Authority"><CredentialAuthorityConsole data={data} selectedSourceId={selectedSourceId} /></Panel>
      <Panel title="Outbound Network Authority"><NetworkRouteConfiguration data={data} selectedSourceId={selectedSourceId} /></Panel>
      <Panel title="Credential and Certificate References">
      {data.credentials.length === 0 ? <EmptyRows label="No credential references configured." /> : (
        <Table headings={['Source', 'Credential', 'Type', 'State', 'Validation', 'Provider', 'Certificate', 'Rotation', 'Expiry']}>
          {data.credentials.map((credential) => (
            <tr key={credential.credentialId}>
              <td className={primaryCellClass}>{credential.sourceName}</td>
              <td className={cellClass}>{credential.credentialKey}</td>
              <td className={cellClass}>{humanize(credential.credentialType)}</td>
              <td className={cellClass}><StatusBadge value={credential.status} /></td>
              <td className={cellClass}><StatusBadge value={credential.validationStatus} /></td>
              <td className={cellClass}>{credential.secretProviderScheme ?? credential.certificateProviderScheme ?? 'Missing'}</td>
              <td className={cellClass}>{credential.certificateReferenceConfigured ? `${credential.certificateChainLength ?? 'Unobserved'} cert(s)` : 'Not configured'}</td>
              <td className={cellClass}>{humanize(credential.rotationState)}</td>
              <td className={cellClass}>{formatTime(credential.expiresAtIso ?? credential.certificateExpiresAtIso)}</td>
            </tr>
          ))}
        </Table>
      )}
      </Panel>
    </div>
  );
}

export function GovernedDecisionControls({
  change,
  currentUserId,
  scopeMatches,
  onRefresh,
}: {
  change: IntegrationControlPlane['governedChanges'][number];
  currentUserId: number | null;
  scopeMatches: boolean;
  onRefresh: () => Promise<void>;
}) {
  const [decision, setDecision] = useState<'approved' | 'rejected'>('approved');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!scopeMatches) {
    return <p className="text-xs/[16px] text-healthcare-warning dark:text-healthcare-warning-dark">Select the governed request&apos;s exact organization, facility, and source boundary before deciding it.</p>;
  }

  if (currentUserId === change.authorUserId) {
    return <p className="text-xs/[16px] text-healthcare-warning dark:text-healthcare-warning-dark">Independent approver required; the author cannot decide this request.</p>;
  }

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      await axios.post(`/api/admin/integrations/governed-changes/${change.changeRequestUuid}/decision`, {
        decision,
        reason: reason.trim(),
      });
      setReason('');
      await onRefresh();
    } catch (caught) {
      if (axios.isAxiosError(caught) && caught.response?.status === 428) {
        const reauthenticationUrl = caught.response.data?.error?.reauthentication_url;
        if (typeof reauthenticationUrl === 'string') {
          router.visit(reauthenticationUrl);
          return;
        }
      }
      setError(
        axios.isAxiosError(caught) && typeof caught.response?.data?.error?.message === 'string'
          ? caught.response.data.error.message
          : 'The governed decision was not recorded. Verify step-up, scope, and segregation of duties.',
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mt-3 grid gap-2 md:grid-cols-[10rem_1fr_auto] md:items-end">
      <label className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Decision
        <select value={decision} onChange={(event) => setDecision(event.target.value as 'approved' | 'rejected')} className="mt-1 block w-full rounded-md border-healthcare-border bg-healthcare-surface text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark">
          <option value="approved">Approve</option>
          <option value="rejected">Reject</option>
        </select>
      </label>
      <label className="text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        Independent rationale
        <input value={reason} onChange={(event) => setReason(event.target.value)} minLength={10} maxLength={500} placeholder="10–500 characters; no PHI or credentials" className="mt-1 block w-full rounded-md border-healthcare-border bg-healthcare-surface text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark" />
      </label>
      <button type="button" onClick={submit} disabled={busy || reason.trim().length < 10} className="rounded-md bg-healthcare-primary px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">
        {busy ? 'Recording…' : 'Record decision'}
      </button>
      {error ? <p role="alert" className="text-xs text-healthcare-critical md:col-span-3 dark:text-healthcare-critical-dark">{error}</p> : null}
    </div>
  );
}

export function GovernedExecutionControl({
  change,
  scopeMatches,
  onRefresh,
}: {
  change: IntegrationControlPlane['governedChanges'][number];
  scopeMatches: boolean;
  onRefresh: () => Promise<void>;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [rotation, setRotation] = useState({
    secretRef: '', certificateRef: '', jwksUri: '', validFrom: '', expiresAt: '', rotatesAt: '', overlapEndsAt: '',
  });
  const isRotation = change.actionType === 'rotate_integration_credential';
  const [rotationSourceId, rotationCredentialId] = isRotation
    ? change.subjectId.split(':').map((value) => Number(value))
    : [0, 0];
  const endpoint = change.actionType === 'activate_production_source'
    ? 'execute-source-activation'
    : change.actionType === 'schedule_production_source_activation'
      ? 'execute-source-activation-schedule'
    : change.actionType === 'apply_source_configuration'
      ? 'execute-source-configuration'
      : isRotation
        ? 'execute-credential-rotation'
        : null;
  if (change.status !== 'approved' || endpoint === null) return <span className="text-xs text-healthcare-text-secondary">Not applicable</span>;
  if (!scopeMatches) return <span className="text-xs text-healthcare-warning">Select exact scope</span>;

  const execute = async () => {
    setBusy(true);
    setError(null);
    try {
      if (isRotation) {
        const input: CredentialRotationInput = {
          ...(rotation.secretRef ? { secret_ref: rotation.secretRef } : {}),
          ...(rotation.certificateRef ? { certificate_ref: rotation.certificateRef } : {}),
          ...(rotation.jwksUri ? { jwks_uri: rotation.jwksUri } : {}),
          ...(rotation.validFrom ? { valid_from: rotation.validFrom } : {}),
          ...(rotation.expiresAt ? { expires_at: rotation.expiresAt } : {}),
          ...(rotation.rotatesAt ? { rotates_at: rotation.rotatesAt } : {}),
          ...(rotation.overlapEndsAt ? { rotation_overlap_ends_at: rotation.overlapEndsAt } : {}),
        };
        await executeCredentialRotation(change.changeRequestUuid, rotationSourceId, rotationCredentialId, input);
      } else {
        await axios.post(`/api/admin/integrations/governed-changes/${change.changeRequestUuid}/${endpoint}`);
      }
      await onRefresh();
    } catch (caught) {
      if (axios.isAxiosError(caught) && caught.response?.status === 428) {
        const url = caught.response.data?.error?.reauthentication_url;
        if (typeof url === 'string') {
          router.visit(url);
          return;
        }
      }
      setError(axios.isAxiosError(caught) && typeof caught.response?.data?.error?.message === 'string'
        ? caught.response.data.error.message
        : 'The exact approved version could not be executed.');
    } finally {
      setBusy(false);
    }
  };

  if (isRotation) {
    const hasTarget = [rotation.secretRef, rotation.certificateRef, rotation.jwksUri, rotation.validFrom, rotation.expiresAt, rotation.rotatesAt, rotation.overlapEndsAt].some(Boolean);
    return <div className="grid min-w-72 gap-1">
      <input aria-label="Approved secret reference" value={rotation.secretRef} onChange={(event) => setRotation({ ...rotation, secretRef: event.target.value })} placeholder="Exact approved secret reference" className="rounded border border-healthcare-border bg-healthcare-surface px-2 py-1 text-xs dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark" />
      <input aria-label="Approved certificate reference" value={rotation.certificateRef} onChange={(event) => setRotation({ ...rotation, certificateRef: event.target.value })} placeholder="Exact approved certificate reference" className="rounded border border-healthcare-border bg-healthcare-surface px-2 py-1 text-xs dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark" />
      <input aria-label="Approved JWKS URI" value={rotation.jwksUri} onChange={(event) => setRotation({ ...rotation, jwksUri: event.target.value })} placeholder="Exact approved JWKS URI" className="rounded border border-healthcare-border bg-healthcare-surface px-2 py-1 text-xs dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark" />
      <div className="grid grid-cols-2 gap-1">
        <label className="text-xs text-healthcare-text-secondary">Valid from<input type="datetime-local" value={rotation.validFrom} onChange={(event) => setRotation({ ...rotation, validFrom: event.target.value })} className="block w-full rounded border border-healthcare-border bg-healthcare-surface px-1 py-1 text-xs dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark" /></label>
        <label className="text-xs text-healthcare-text-secondary">Expires at<input type="datetime-local" value={rotation.expiresAt} onChange={(event) => setRotation({ ...rotation, expiresAt: event.target.value })} className="block w-full rounded border border-healthcare-border bg-healthcare-surface px-1 py-1 text-xs dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark" /></label>
        <label className="text-xs text-healthcare-text-secondary">Rotates at<input type="datetime-local" value={rotation.rotatesAt} onChange={(event) => setRotation({ ...rotation, rotatesAt: event.target.value })} className="block w-full rounded border border-healthcare-border bg-healthcare-surface px-1 py-1 text-xs dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark" /></label>
        <label className="text-xs text-healthcare-text-secondary">Overlap ends<input type="datetime-local" value={rotation.overlapEndsAt} onChange={(event) => setRotation({ ...rotation, overlapEndsAt: event.target.value })} className="block w-full rounded border border-healthcare-border bg-healthcare-surface px-1 py-1 text-xs dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark" /></label>
      </div>
      <button type="button" onClick={execute} disabled={busy || !hasTarget || rotationSourceId < 1 || rotationCredentialId < 1} className="rounded-md bg-healthcare-primary px-2 py-1 text-xs font-semibold text-white disabled:opacity-50">
        {busy ? 'Executing…' : 'Execute exact approved rotation'}
      </button>
      <p className="max-w-xs text-xs text-healthcare-text-secondary">Re-enter only the fields in the approved request. A mismatch fails closed.</p>
      {error ? <p role="alert" className="max-w-xs text-xs text-healthcare-critical">{error}</p> : null}
    </div>;
  }

  return <div>
    <button type="button" onClick={execute} disabled={busy} className="rounded-md bg-healthcare-primary px-2 py-1 text-xs font-semibold text-white disabled:opacity-50">
      {busy ? 'Executing…' : 'Execute approved change'}
    </button>
    {error ? <p role="alert" className="mt-1 max-w-xs text-xs text-healthcare-critical">{error}</p> : null}
  </div>;
}

function AuditPanel({
  data,
  canApprove,
  currentUserId,
  selectedOrganizationId,
  selectedFacilityId,
  selectedSourceId,
  onRefresh,
}: {
  data: IntegrationControlPlane;
  canApprove: boolean;
  currentUserId: number | null;
  selectedOrganizationId: number | null;
  selectedFacilityId: number | null;
  selectedSourceId: number | null;
  onRefresh: () => Promise<void>;
}) {
  const pending = data.governedChanges.filter((change) => change.status === 'pending');
  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-4">
        <Metric label="Provenance Records" value={data.audit.provenanceRecords} />
        <Metric label="Identity Links" value={data.audit.identityLinks} />
        <Metric label="Patient Merge Events" value={data.audit.patientMergeEvents} />
        <Metric label="Pending Governed Changes" value={data.counts.pendingGovernedChanges} status={data.counts.pendingGovernedChanges > 0 ? 'warning' : undefined} />
      </div>
      <Panel title="Pending Governed Approvals">
        {pending.length === 0 ? <EmptyRows label="No governed integration changes are awaiting a decision." /> : (
          <div id="governed-approvals" className="space-y-3">
            {pending.map((change) => (
              <article key={change.changeRequestUuid} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <p className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{humanize(change.actionType)}</p>
                    <p className="mt-0.5 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{humanize(change.subjectType)} / {change.subjectId} · author User #{change.authorUserId}</p>
                    <p className="mt-0.5 font-mono text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{change.changeRequestUuid}</p>
                  </div>
                  <div className="text-right"><StatusBadge value={change.status} /><p className="mt-1 text-xs text-healthcare-text-secondary">Expires {formatTime(change.expiresAtIso)}</p></div>
                </div>
                {canApprove ? <GovernedDecisionControls
                  change={change}
                  currentUserId={currentUserId}
                  scopeMatches={(change.organizationId !== null || change.facilityId !== null)
                    && (change.organizationId === null || change.organizationId === selectedOrganizationId)
                    && (change.facilityId === null || change.facilityId === selectedFacilityId)
                    && (change.sourceId === null || change.sourceId === selectedSourceId)}
                  onRefresh={onRefresh}
                /> : <p className="mt-3 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">approveIntegrationChanges capability required to decide.</p>}
              </article>
            ))}
          </div>
        )}
      </Panel>
      <Panel title="Governed Change Ledger">
        {data.governedChanges.length === 0 ? <EmptyRows label="No governed integration changes recorded." /> : (
          <Table headings={['Request', 'Action', 'Subject', 'Author', 'Status', 'Requested', 'Expires / decided', 'Execution']}>
            {data.governedChanges.map((change) => (
              <tr key={change.changeRequestUuid}>
                <td className={`${primaryCellClass} font-mono text-xs`}>{change.changeRequestUuid}</td>
                <td className={cellClass}>{humanize(change.actionType)}</td>
                <td className={cellClass}>{humanize(change.subjectType)} / {change.subjectId}</td>
                <td className={cellClass}>User #{change.authorUserId}</td>
                <td className={cellClass}><StatusBadge value={change.status} /></td>
                <td className={cellClass}>{formatTime(change.requestedAtIso)}</td>
                <td className={cellClass}>{formatTime(change.decidedAtIso ?? change.expiresAtIso)}</td>
                <td className={cellClass}><GovernedExecutionControl
                  change={change}
                  scopeMatches={(change.organizationId !== null || change.facilityId !== null)
                    && (change.organizationId === null || change.organizationId === selectedOrganizationId)
                    && (change.facilityId === null || change.facilityId === selectedFacilityId)
                    && (change.sourceId === null || change.sourceId === selectedSourceId)}
                  onRefresh={onRefresh}
                /></td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      <Panel title="Configuration Changes">
        {data.configurationAudits.length === 0 ? <EmptyRows label="No configuration changes recorded." /> : (
          <Table headings={['ID', 'Action', 'Entity', 'Key', 'Actor', 'Correlation', 'Created']}>
            {data.configurationAudits.map((audit) => (
              <tr key={audit.auditId}>
                <td className={`${primaryCellClass} tabular-nums`}>#{audit.auditId}</td>
                <td className={cellClass}><StatusBadge value={audit.action} /></td>
                <td className={cellClass}>{humanize(audit.entityType)}{audit.entityId ? ` #${audit.entityId}` : ''}</td>
                <td className={cellClass}>{audit.entityKey ?? 'Not set'}</td>
                <td className={cellClass}>{audit.actorUserId ? `User #${audit.actorUserId}` : 'System'}</td>
                <td className={`${cellClass} font-mono text-xs`}>{audit.correlationId}</td>
                <td className={cellClass}>{formatTime(audit.createdAtIso)}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
      <Panel title="Projection Errors">
        {data.projectionErrors.length === 0 ? <EmptyRows label="No projection errors recorded." /> : (
          <Table headings={['ID', 'Projector', 'Error Code', 'Status', 'Created']}>
            {data.projectionErrors.map((error) => (
              <tr key={error.projectionErrorId}>
                <td className={`${primaryCellClass} tabular-nums`}>#{error.projectionErrorId}</td>
                <td className={cellClass}>{error.projectorKey}</td>
                <td className={cellClass}>{humanize(error.errorCode)}</td>
                <td className={cellClass}><StatusBadge value={error.status} /></td>
                <td className={cellClass}>{formatTime(error.createdAtIso)}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </div>
  );
}

function ActivePanel({ tab, data, canApprove, canOperateIntegrations, currentUserId, selectedOrganizationId, selectedFacilityId, selectedSourceId, hasFacilityScope, onRefresh }: { tab: TabId; data: IntegrationControlPlane; canApprove: boolean; canOperateIntegrations: boolean; currentUserId: number | null; selectedOrganizationId: number | null; selectedFacilityId: number | null; selectedSourceId: number | null; hasFacilityScope: boolean; onRefresh: () => Promise<void> }) {
  switch (tab) {
    case 'observability': return <ObservabilityPanel selectedSourceId={selectedSourceId} canOperateIntegrations={canOperateIntegrations} />;
    case 'sources': return <SourcesPanel data={data} selectedSourceId={selectedSourceId} hasFacilityScope={hasFacilityScope} />;
    case 'fhir': return <FhirPanel data={data} selectedSourceId={selectedSourceId} />;
    case 'hl7': return <Hl7Panel data={data} selectedSourceId={selectedSourceId} />;
    case 'applications': return <ApplicationsPanel data={data} />;
    case 'mappings': return <MappingsPanel data={data} />;
    case 'runs': return <RunsPanel data={data} />;
    case 'dead-letters': return <DeadLettersPanel data={data} selectedSourceId={selectedSourceId} />;
    case 'outbound': return <OutboundPanel data={data} />;
    case 'credentials': return <CredentialsPanel data={data} selectedSourceId={selectedSourceId} />;
    case 'audit': return <AuditPanel data={data} canApprove={canApprove} currentUserId={currentUserId} selectedOrganizationId={selectedOrganizationId} selectedFacilityId={selectedFacilityId} selectedSourceId={selectedSourceId} onRefresh={onRefresh} />;
    default: return <OverviewPanel data={data} />;
  }
}

export default function IntegrationsIndex() {
  const [activeTab, setActiveTab] = useState<TabId>(initialTab);
  const controlPlane = useIntegrationControlPlane();
  const page = usePage<PageProps>();
  const canApprove = page.props.auth.can?.approve_integration_changes === true;
  const canOperateIntegrations = page.props.auth.can?.operate_integrations === true;
  const currentUserId = page.props.auth.user?.id ?? null;
  const selectedOrganizationId = page.props.adminScope?.current?.organization.id ?? null;
  const selectedFacilityId = page.props.adminScope?.current?.facility?.id ?? null;
  const selectedSourceId = page.props.adminScope?.current?.source?.id ?? null;

  const selectTab = (tab: TabId) => {
    setActiveTab(tab);
    if (typeof window !== 'undefined') {
      const url = new URL(window.location.href);
      url.searchParams.set('tab', tab);
      window.history.replaceState({}, '', url);
    }
  };

  const headerControls = (
    <div className="flex flex-wrap items-center justify-end gap-2">
      <AdminScopeSelector />
      <button
        type="button"
        title="Refresh integration status"
        aria-label="Refresh integration status"
        onClick={() => controlPlane.refetch()}
        disabled={controlPlane.isFetching}
        className="inline-flex size-9 items-center justify-center rounded-md border border-healthcare-border text-healthcare-text-primary transition hover:bg-healthcare-hover disabled:cursor-not-allowed disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
      >
        <RefreshCcw className={`size-4 ${controlPlane.isFetching ? 'animate-spin' : ''}`} aria-hidden="true" />
      </button>
    </div>
  );

  return (
    <DashboardLayout>
      <Head title="Integrations" />
      <PageContentLayout
        title="Integrations"
        subtitle="Enterprise interfaces, message flow, source health, credentials, and governed writeback"
        headerContent={headerControls}
      >
        {controlPlane.isLoading ? (
          <div className="rounded-md border border-healthcare-border p-8 text-center text-sm/[18px] text-healthcare-text-secondary dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark">
            Loading integration control plane...
          </div>
        ) : controlPlane.isError || !controlPlane.data ? (
          <div role="alert" className="rounded-md border border-healthcare-critical/40 bg-healthcare-critical/10 p-4 text-healthcare-critical dark:text-healthcare-critical-dark">
            <div className="font-semibold">Integration status is unavailable.</div>
            <button type="button" onClick={() => controlPlane.refetch()} className="mt-3 inline-flex items-center gap-2 rounded-md border border-healthcare-critical/40 px-3 py-1.5 text-sm/[18px] font-semibold">
              <RefreshCcw className="size-4" aria-hidden="true" /> Retry
            </button>
          </div>
        ) : (
          <div className="space-y-5">
            <div className={`rounded-md border px-3 py-2 text-xs/[16px] ${selectedSourceId
              ? 'border-healthcare-success/30 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark'
              : 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark'}`}>
              Read summaries are capability-wide. Mutations are constrained to the explicitly selected organization, facility, and source; global roles do not bypass this selection.
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-healthcare-border pb-3 dark:border-healthcare-border-dark">
              <div className="flex flex-wrap items-center gap-2">
                <StatusBadge value={controlPlane.data.status} />
                <span className="text-xs/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Observed {formatTime(controlPlane.data.generatedAtIso)}
                </span>
              </div>
              <span className="text-xs/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {controlPlane.data.counts.activeSources} active of {controlPlane.data.counts.sources} sources
              </span>
            </div>

            <div role="tablist" aria-label="Integration administration" className="flex flex-wrap gap-1 border-b border-healthcare-border pb-2 dark:border-healthcare-border-dark">
              {tabs.map(({ id, label, icon: Icon }) => (
                <button
                  key={id}
                  type="button"
                  role="tab"
                  aria-selected={activeTab === id}
                  onClick={() => selectTab(id)}
                  className={`inline-flex min-h-9 items-center gap-2 rounded-md px-3 py-1.5 text-sm/[18px] font-medium transition ${activeTab === id
                    ? 'bg-healthcare-primary text-white'
                    : 'text-healthcare-text-secondary hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark'}`}
                >
                  <Icon className="size-4" aria-hidden="true" />
                  {label}
                </button>
              ))}
            </div>

            <div role="tabpanel"><ActivePanel tab={activeTab} data={controlPlane.data} canApprove={canApprove} canOperateIntegrations={canOperateIntegrations} currentUserId={currentUserId} selectedOrganizationId={selectedOrganizationId} selectedFacilityId={selectedFacilityId} selectedSourceId={selectedSourceId} hasFacilityScope={selectedFacilityId !== null} onRefresh={async () => { await controlPlane.refetch(); }} /></div>
          </div>
        )}
      </PageContentLayout>
    </DashboardLayout>
  );
}
