import PageContentLayout from '@/Components/Common/PageContentLayout';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import { CredentialConfiguration, EndpointConfiguration, SourceConfiguration } from '@/Components/Integrations/ConfigurationForms';
import {
  useIntegrationControlPlane,
  usePreviewIntegrationReplay,
  useQueueEpicFhirPoll,
  useQueueIntegrationHealthCheck,
  useQueueIntegrationReplay,
} from '@/features/integrations/hooks';
import type { IntegrationControlPlane, IntegrationReplayInput, IntegrationSource } from '@/features/integrations/api';
import { Head } from '@inertiajs/react';
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
import { useState, type ComponentType, type ReactNode } from 'react';
import { formatDurationMinutes } from '@/lib/duration';

type TabId =
  | 'overview'
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

const statusClasses: Record<string, string> = {
  healthy: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  completed: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  approved: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  active: 'border-healthcare-success/40 bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark',
  failed: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  stale: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  open: 'border-healthcare-critical/40 bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark',
  degraded: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  unobserved: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  pending: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
  pending_approval: 'border-healthcare-warning/40 bg-healthcare-warning/10 text-healthcare-warning dark:text-healthcare-warning-dark',
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
          <td className={cellClass}><StatusBadge value={source.healthStatus} /></td>
          <td className={cellClass}>{formatTime(source.lastObservedAtIso)}</td>
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

function SourcesPanel({ data }: { data: IntegrationControlPlane }) {
  return (
    <div className="space-y-5">
      <Panel title="Source Administration"><SourceConfiguration data={data} /></Panel>
      <Panel title="Configured Sources"><SourceTable sources={data.sources} /></Panel>
      <Panel title="Endpoint Administration"><EndpointConfiguration data={data} /></Panel>
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

function FhirPanel({ data }: { data: IntegrationControlPlane }) {
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
                    <ActionButton disabled={healthCheck.isPending} onClick={() => healthCheck.mutate(connection.sourceId)}>Check</ActionButton>
                    <ActionButton disabled={poll.isPending || !['ready', 'active'].includes(connection.status)} onClick={() => poll.mutate({ sourceId: connection.sourceId, resourceType: 'Encounter' })}>Poll Encounter</ActionButton>
                    <ActionButton disabled={poll.isPending || !['ready', 'active'].includes(connection.status)} onClick={() => poll.mutate({ sourceId: connection.sourceId, resourceType: 'Location' })}>Poll Location</ActionButton>
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

function Hl7Panel({ data }: { data: IntegrationControlPlane }) {
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
                <ActionButton disabled={healthCheck.isPending} onClick={() => healthCheck.mutate(source.sourceId)}>Check boundary</ActionButton>
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

function DeadLettersPanel({ data }: { data: IntegrationControlPlane }) {
  const preview = usePreviewIntegrationReplay();
  const replay = useQueueIntegrationReplay();
  const [sourceId, setSourceId] = useState('');
  const [from, setFrom] = useState(() => localDateTime(new Date(Date.now() - 24 * 60 * 60 * 1000)));
  const [to, setTo] = useState(() => localDateTime(new Date()));
  const [previewedInput, setPreviewedInput] = useState<IntegrationReplayInput | null>(null);
  const replayInput = (): IntegrationReplayInput => ({
    source_id: sourceId ? Number(sourceId) : null,
    from: new Date(from).toISOString(),
    to: new Date(to).toISOString(),
    limit: 500,
  });
  const resetReplayScope = () => {
    setPreviewedInput(null);
    preview.reset();
    replay.reset();
  };
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
              <option value="">All sources</option>
              {data.sources.map((source) => <option key={source.sourceId} value={source.sourceId}>{source.sourceName}</option>)}
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
            <ActionButton disabled={preview.isPending || !from || !to} onClick={() => { const input = replayInput(); preview.mutate(input, { onSuccess: () => setPreviewedInput(input) }); }}>Preview</ActionButton>
            <ActionButton disabled={replay.isPending || replay.isSuccess || !previewedInput || !preview.data || preview.data.eligibleEvents === 0} onClick={() => replay.mutate({ input: previewedInput!, idempotencyKey: globalThis.crypto?.randomUUID?.() ?? `replay-${Date.now()}` })}>Queue replay</ActionButton>
          </div>
        </div>
        <p className="mt-2 text-xs/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Only pending or failed RTDC canonical projections are eligible. Preview is read-only; already-projected events are excluded.</p>
        {preview.data && <p role="status" className="mt-2 text-sm/[18px] text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{preview.data.eligibleEvents} eligible of {preview.data.totalMatchingEvents} matching events{preview.data.truncated ? ' (bounded by the replay limit)' : ''}.</p>}
        {replay.isSuccess && <p role="status" className="mt-2 text-sm/[18px] text-healthcare-success dark:text-healthcare-success-dark">Replay #{replay.data.replayJobId} was queued with an idempotency key.</p>}
        {(preview.isError || replay.isError) && <p role="alert" className="mt-2 text-sm/[18px] text-healthcare-critical dark:text-healthcare-critical-dark">The replay request was rejected. Check the seven-day window and source selection.</p>}
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

function CredentialsPanel({ data }: { data: IntegrationControlPlane }) {
  return (
    <div className="space-y-5">
      <Panel title="Reference Administration"><CredentialConfiguration data={data} /></Panel>
      <Panel title="Credential and Certificate References">
      {data.credentials.length === 0 ? <EmptyRows label="No credential references configured." /> : (
        <Table headings={['Source', 'Credential', 'Type', 'Status', 'Secret Ref', 'Certificate Ref', 'JWKS', 'Rotation']}>
          {data.credentials.map((credential) => (
            <tr key={credential.credentialId}>
              <td className={primaryCellClass}>{credential.sourceName}</td>
              <td className={cellClass}>{credential.credentialKey}</td>
              <td className={cellClass}>{humanize(credential.credentialType)}</td>
              <td className={cellClass}><StatusBadge value={credential.status} /></td>
              <td className={cellClass}>{credential.secretReferenceConfigured ? 'Configured' : 'Missing'}</td>
              <td className={cellClass}>{credential.certificateReferenceConfigured ? 'Configured' : 'Missing'}</td>
              <td className={cellClass}>{credential.jwksConfigured ? 'Configured' : 'Missing'}</td>
              <td className={cellClass}>{formatTime(credential.rotatesAtIso)}</td>
            </tr>
          ))}
        </Table>
      )}
      </Panel>
    </div>
  );
}

function AuditPanel({ data }: { data: IntegrationControlPlane }) {
  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-3">
        <Metric label="Provenance Records" value={data.audit.provenanceRecords} />
        <Metric label="Identity Links" value={data.audit.identityLinks} />
        <Metric label="Patient Merge Events" value={data.audit.patientMergeEvents} />
      </div>
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

function ActivePanel({ tab, data }: { tab: TabId; data: IntegrationControlPlane }) {
  switch (tab) {
    case 'sources': return <SourcesPanel data={data} />;
    case 'fhir': return <FhirPanel data={data} />;
    case 'hl7': return <Hl7Panel data={data} />;
    case 'applications': return <ApplicationsPanel data={data} />;
    case 'mappings': return <MappingsPanel data={data} />;
    case 'runs': return <RunsPanel data={data} />;
    case 'dead-letters': return <DeadLettersPanel data={data} />;
    case 'outbound': return <OutboundPanel data={data} />;
    case 'credentials': return <CredentialsPanel data={data} />;
    case 'audit': return <AuditPanel data={data} />;
    default: return <OverviewPanel data={data} />;
  }
}

export default function IntegrationsIndex() {
  const [activeTab, setActiveTab] = useState<TabId>('overview');
  const controlPlane = useIntegrationControlPlane();

  const refreshButton = (
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
  );

  return (
    <DashboardLayout>
      <Head title="Integrations" />
      <PageContentLayout
        title="Integrations"
        subtitle="Enterprise interfaces, message flow, source health, credentials, and governed writeback"
        headerContent={refreshButton}
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
                  onClick={() => setActiveTab(id)}
                  className={`inline-flex min-h-9 items-center gap-2 rounded-md px-3 py-1.5 text-sm/[18px] font-medium transition ${activeTab === id
                    ? 'bg-healthcare-primary text-white'
                    : 'text-healthcare-text-secondary hover:bg-healthcare-hover dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark'}`}
                >
                  <Icon className="size-4" aria-hidden="true" />
                  {label}
                </button>
              ))}
            </div>

            <div role="tabpanel"><ActivePanel tab={activeTab} data={controlPlane.data} /></div>
          </div>
        )}
      </PageContentLayout>
    </DashboardLayout>
  );
}
