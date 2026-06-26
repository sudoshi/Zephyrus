import TransportLayout from './TransportLayout';
import {
  useCreateEnterpriseWritebackDraft,
  useDiscoverEnterpriseFhirCapabilities,
  useEnterpriseConnectorSummary,
  useTransportVendors,
} from '@/features/transport/hooks';
import type { CreateEnterpriseWritebackDraftInput } from '@/features/transport/types';
import { CheckCircle2, DatabaseZap, FileCheck2, RefreshCcw, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';

const standards = [
  ['FHIR ServiceRequest', 'Canonical request/order for transport, transfer, referral, and discharge ride needs.'],
  ['FHIR Task', 'Execution state, assignee, progress, and fulfillment linkage back to the request.'],
  ['HL7 ADT', 'Encounter and location movement events such as admissions, transfers, discharges, and cancellations.'],
  ['Direct / C-CDA', 'Fallback transition packets where bidirectional APIs are not available.'],
  ['Vendor REST + Webhooks', 'Ride quote, tender, book, cancel, status, ETA, and handoff completion callbacks.'],
];

const resourceTypes: CreateEnterpriseWritebackDraftInput['resource_type'][] = [
  'Task',
  'ServiceRequest',
  'TransportRequest',
  'EvsRequest',
  'SecureMessage',
];

const clean = (value: string) => value.trim() || undefined;

const payloadEntries = (payload: Record<string, unknown>) =>
  Object.entries(payload).map(([key, value]) => `${key}: ${String(value)}`);

export default function IntegrationSettings() {
  const { data: vendors } = useTransportVendors();
  const summary = useEnterpriseConnectorSummary();
  const discovery = useDiscoverEnterpriseFhirCapabilities();
  const writeback = useCreateEnterpriseWritebackDraft();
  const [discoveryForm, setDiscoveryForm] = useState({
    sourceKey: 'epic.fhir.sandbox',
    vendor: 'Epic',
    fhirVersion: '4.0.1',
    clientId: 'zephyrus-system-client',
  });
  const [writebackForm, setWritebackForm] = useState({
    sourceKey: 'epic.fhir.sandbox',
    vendor: 'Epic',
    targetSystem: 'epic',
    resourceType: 'Task' as CreateEnterpriseWritebackDraftInput['resource_type'],
    description: 'Draft bed placement task',
  });

  const counts = summary.data?.counts;
  const topMetrics = useMemo(() => [
    ['Interface engines', counts?.interfaceEngines ?? 0],
    ['FHIR connections', counts?.fhirConnections ?? 0],
    ['SMART credentials', counts?.smartCredentials ?? 0],
    ['Connector playbooks', counts?.connectorPlaybooks ?? 0],
    ['Coexistence adapters', counts?.coexistenceAdapters ?? 0],
    ['Writeback drafts', counts?.writebackDrafts ?? 0],
  ], [counts]);

  const submitDiscovery = (event: FormEvent) => {
    event.preventDefault();
    discovery.mutate({
      source_key: clean(discoveryForm.sourceKey),
      vendor: clean(discoveryForm.vendor),
      fhir_version: clean(discoveryForm.fhirVersion),
      client_id: clean(discoveryForm.clientId),
    });
  };

  const submitWriteback = (event: FormEvent) => {
    event.preventDefault();
    writeback.mutate({
      source_key: clean(writebackForm.sourceKey),
      vendor: clean(writebackForm.vendor),
      target_system: clean(writebackForm.targetSystem),
      resource_type: writebackForm.resourceType,
      draft_type: `${writebackForm.resourceType.toLowerCase()}_draft`,
      resource_payload: {
        resourceType: writebackForm.resourceType,
        status: 'requested',
        intent: 'order',
        description: writebackForm.description,
      },
    });
  };

  return (
    <TransportLayout
      title="Enterprise Integrations"
      subtitle="Connector control for EHR, patient-flow, transport, EVS, and writeback governance"
      current="/transport/settings/integrations"
    >
      <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="text-[16px]/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Enterprise Connector Control</h2>
            <div className="mt-1 text-[13px]/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Last refreshed {summary.data?.generatedAtIso ? new Date(summary.data.generatedAtIso).toLocaleString() : 'after connector catalog load'}
            </div>
          </div>
          <button
            type="button"
            onClick={() => summary.refetch()}
            className="inline-flex items-center gap-2 rounded-md border border-healthcare-border px-3 py-2 text-[13px]/[18px] font-semibold text-healthcare-text-primary transition hover:bg-healthcare-hover dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
          >
            <RefreshCcw className="size-4" />
            Refresh
          </button>
        </div>
        {summary.isError ? (
          <div className="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-[13px]/[18px] text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-200">
            Enterprise connector summary is unavailable.
          </div>
        ) : null}
        <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
          {topMetrics.map(([label, value]) => (
            <div key={label} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
              <div className="text-[12px]/[16px] font-semibold uppercase tracking-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</div>
              <div className="mt-2 text-[24px]/[30px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</div>
            </div>
          ))}
        </div>
      </section>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="text-[16px]/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Vendor Targets</h2>
          <div className="mt-3 space-y-3">
            {(vendors ?? []).map((vendor) => (
              <div key={vendor.key} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                <div className="flex items-center justify-between gap-3">
                  <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{vendor.name}</div>
                  <span className="rounded bg-amber-100 px-2 py-0.5 text-[12px]/[16px] font-semibold text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                    Planned
                  </span>
                </div>
                <div className="mt-2 flex flex-wrap gap-1">
                  {(vendor.capabilities ?? []).map((capability) => (
                    <span key={capability} className="rounded bg-slate-100 px-2 py-0.5 text-[12px]/[16px] text-slate-800 dark:bg-slate-800 dark:text-slate-100">
                      {capability.replaceAll('_', ' ')}
                    </span>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </section>

        <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="text-[16px]/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Connector Playbooks</h2>
          <div className="mt-3 space-y-3">
            {(summary.data?.playbooks ?? []).map((playbook) => (
              <div key={playbook.vendorKey} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{playbook.label}</div>
                    <div className="mt-1 text-[12px]/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{playbook.systemClass}</div>
                  </div>
                  <span className="rounded bg-emerald-100 px-2 py-0.5 text-[12px]/[16px] font-semibold text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
                    {playbook.status}
                  </span>
                </div>
                <div className="mt-3 flex flex-wrap gap-1">
                  {payloadEntries(playbook.capabilities).map((entry) => (
                    <span key={entry} className="rounded bg-slate-100 px-2 py-0.5 text-[12px]/[16px] text-slate-800 dark:bg-slate-800 dark:text-slate-100">{entry}</span>
                  ))}
                </div>
                <ol className="mt-3 space-y-1 text-[13px]/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {playbook.implementationSteps.map((step) => (
                    <li key={step} className="flex gap-2">
                      <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-300" />
                      <span>{step}</span>
                    </li>
                  ))}
                </ol>
              </div>
            ))}
          </div>
        </section>
      </div>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="text-[16px]/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Coexistence Adapters</h2>
          <div className="mt-3 space-y-3">
            {(summary.data?.coexistenceAdapters ?? []).map((adapter) => (
              <div key={adapter.adapterKey} className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{adapter.label}</div>
                    <div className="mt-1 text-[12px]/[16px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{adapter.vendorKey}</div>
                  </div>
                  <span className="rounded bg-cyan-100 px-2 py-0.5 text-[12px]/[16px] font-semibold text-cyan-800 dark:bg-cyan-950/40 dark:text-cyan-200">
                    {adapter.status}
                  </span>
                </div>
                <div className="mt-3 flex flex-wrap gap-1">
                  {payloadEntries(adapter.coexistence).map((entry) => (
                    <span key={entry} className="rounded bg-slate-100 px-2 py-0.5 text-[12px]/[16px] text-slate-800 dark:bg-slate-800 dark:text-slate-100">{entry}</span>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </section>

        <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <h2 className="text-[16px]/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Standards Backbone</h2>
          <div className="mt-3 divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
            {standards.map(([name, description]) => (
              <div key={name} className="py-3">
                <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{name}</div>
                <div className="mt-1 text-[13px]/[18px] text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{description}</div>
              </div>
            ))}
          </div>
        </section>
      </div>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <div className="flex items-center gap-2">
            <DatabaseZap className="size-5 text-healthcare-primary" />
            <h2 className="text-[16px]/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">FHIR Capability Discovery</h2>
          </div>
          <form className="mt-4 grid gap-3 md:grid-cols-2" onSubmit={submitDiscovery}>
            <label className="space-y-1 text-[12px]/[16px] font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Source key
              <input value={discoveryForm.sourceKey} onChange={(event) => setDiscoveryForm((prev) => ({ ...prev, sourceKey: event.target.value }))} className="w-full rounded-md border border-healthcare-border bg-white px-3 py-2 text-[14px]/[20px] font-normal text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-slate-950 dark:text-healthcare-text-primary-dark" />
            </label>
            <label className="space-y-1 text-[12px]/[16px] font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Vendor
              <input value={discoveryForm.vendor} onChange={(event) => setDiscoveryForm((prev) => ({ ...prev, vendor: event.target.value }))} className="w-full rounded-md border border-healthcare-border bg-white px-3 py-2 text-[14px]/[20px] font-normal text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-slate-950 dark:text-healthcare-text-primary-dark" />
            </label>
            <label className="space-y-1 text-[12px]/[16px] font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              FHIR version
              <input value={discoveryForm.fhirVersion} onChange={(event) => setDiscoveryForm((prev) => ({ ...prev, fhirVersion: event.target.value }))} className="w-full rounded-md border border-healthcare-border bg-white px-3 py-2 text-[14px]/[20px] font-normal text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-slate-950 dark:text-healthcare-text-primary-dark" />
            </label>
            <label className="space-y-1 text-[12px]/[16px] font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Client ID
              <input value={discoveryForm.clientId} onChange={(event) => setDiscoveryForm((prev) => ({ ...prev, clientId: event.target.value }))} className="w-full rounded-md border border-healthcare-border bg-white px-3 py-2 text-[14px]/[20px] font-normal text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-slate-950 dark:text-healthcare-text-primary-dark" />
            </label>
            <div className="md:col-span-2">
              <button type="submit" disabled={discovery.isPending} className="inline-flex items-center gap-2 rounded-md bg-healthcare-primary px-4 py-2 text-[13px]/[18px] font-semibold text-white transition hover:bg-healthcare-primary/90 disabled:cursor-not-allowed disabled:opacity-60">
                <ShieldCheck className="size-4" />
                {discovery.isPending ? 'Discovering' : 'Discover'}
              </button>
            </div>
          </form>
          {discovery.data ? (
            <div className="mt-4 rounded-md border border-emerald-200 bg-emerald-50 p-3 text-[13px]/[18px] text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-100">
              {discovery.data.sourceKey} recorded as {discovery.data.connectionStatus} on FHIR {discovery.data.fhirVersion}; SMART credential is {discovery.data.smartCredentialStatus}.
            </div>
          ) : null}
          {discovery.isError ? (
            <div className="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-[13px]/[18px] text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-200">
              Capability discovery failed validation or the API rejected the request.
            </div>
          ) : null}
        </section>

        <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
          <div className="flex items-center gap-2">
            <FileCheck2 className="size-5 text-healthcare-primary" />
            <h2 className="text-[16px]/[22px] font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Writeback Draft</h2>
          </div>
          <form className="mt-4 grid gap-3 md:grid-cols-2" onSubmit={submitWriteback}>
            <label className="space-y-1 text-[12px]/[16px] font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Source key
              <input value={writebackForm.sourceKey} onChange={(event) => setWritebackForm((prev) => ({ ...prev, sourceKey: event.target.value }))} className="w-full rounded-md border border-healthcare-border bg-white px-3 py-2 text-[14px]/[20px] font-normal text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-slate-950 dark:text-healthcare-text-primary-dark" />
            </label>
            <label className="space-y-1 text-[12px]/[16px] font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Target system
              <input value={writebackForm.targetSystem} onChange={(event) => setWritebackForm((prev) => ({ ...prev, targetSystem: event.target.value }))} className="w-full rounded-md border border-healthcare-border bg-white px-3 py-2 text-[14px]/[20px] font-normal text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-slate-950 dark:text-healthcare-text-primary-dark" />
            </label>
            <label className="space-y-1 text-[12px]/[16px] font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Resource type
              <select value={writebackForm.resourceType} onChange={(event) => setWritebackForm((prev) => ({ ...prev, resourceType: event.target.value as CreateEnterpriseWritebackDraftInput['resource_type'] }))} className="w-full rounded-md border border-healthcare-border bg-white px-3 py-2 text-[14px]/[20px] font-normal text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-slate-950 dark:text-healthcare-text-primary-dark">
                {resourceTypes.map((resourceType) => <option key={resourceType} value={resourceType}>{resourceType}</option>)}
              </select>
            </label>
            <label className="space-y-1 text-[12px]/[16px] font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Vendor
              <input value={writebackForm.vendor} onChange={(event) => setWritebackForm((prev) => ({ ...prev, vendor: event.target.value }))} className="w-full rounded-md border border-healthcare-border bg-white px-3 py-2 text-[14px]/[20px] font-normal text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-slate-950 dark:text-healthcare-text-primary-dark" />
            </label>
            <label className="space-y-1 text-[12px]/[16px] font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark md:col-span-2">
              Description
              <input value={writebackForm.description} onChange={(event) => setWritebackForm((prev) => ({ ...prev, description: event.target.value }))} className="w-full rounded-md border border-healthcare-border bg-white px-3 py-2 text-[14px]/[20px] font-normal text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-slate-950 dark:text-healthcare-text-primary-dark" />
            </label>
            <div className="md:col-span-2">
              <button type="submit" disabled={writeback.isPending} className="inline-flex items-center gap-2 rounded-md bg-healthcare-primary px-4 py-2 text-[13px]/[18px] font-semibold text-white transition hover:bg-healthcare-primary/90 disabled:cursor-not-allowed disabled:opacity-60">
                <FileCheck2 className="size-4" />
                {writeback.isPending ? 'Creating' : 'Create draft'}
              </button>
            </div>
          </form>
          {writeback.data ? (
            <div className="mt-4 rounded-md border border-emerald-200 bg-emerald-50 p-3 text-[13px]/[18px] text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-100">
              Draft {writeback.data.writebackDraftId} is {writeback.data.status}; approval {writeback.data.approvalId} is {writeback.data.approvalStatus}.
            </div>
          ) : null}
          {writeback.isError ? (
            <div className="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-[13px]/[18px] text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-200">
              Writeback draft creation failed validation or the API rejected the request.
            </div>
          ) : null}
        </section>
      </div>
    </TransportLayout>
  );
}
