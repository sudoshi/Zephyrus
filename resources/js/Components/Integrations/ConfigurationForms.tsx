import type { IntegrationControlPlane, IntegrationSource, IntegrationSourceInput } from '@/features/integrations/api';
import {
  useCreateIntegrationCredential,
  useCreateIntegrationEndpoint,
  useCreateIntegrationSource,
  useDeleteIntegrationCredential,
  useDeleteIntegrationEndpoint,
  useRetireIntegrationSource,
  useUpdateIntegrationEndpoint,
  useUpdateIntegrationCredential,
  useUpdateIntegrationSource,
} from '@/features/integrations/hooks';
import axios from 'axios';
import { Archive, KeyRound, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';

const inputClass = 'w-full rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark';
const labelClass = 'space-y-1 text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark';
const secondaryButton = 'inline-flex min-h-9 items-center gap-2 rounded-md border border-healthcare-border px-3 py-1.5 text-sm font-semibold text-healthcare-text-primary hover:bg-healthcare-hover disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark';
const primaryButton = 'inline-flex min-h-9 items-center gap-2 rounded-md bg-healthcare-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-healthcare-primary/90 disabled:opacity-50';

function errorMessage(error: unknown): string | null {
  if (!axios.isAxiosError(error)) return error ? 'Configuration request failed.' : null;
  const errors = error.response?.data?.errors as Record<string, string[]> | undefined;
  const first = errors ? Object.values(errors).flat()[0] : undefined;
  return first ?? error.response?.data?.message ?? 'Configuration request failed.';
}

const emptySourceForm = {
  sourceKey: '', sourceName: '', vendor: '', systemClass: 'ehr', environment: 'sandbox',
  baseUrl: '', interfaceType: 'fhir_r4', activeStatus: 'testing', contractStatus: 'planning',
  baaStatus: 'planning', goLiveStatus: 'testing', owner: '', cadence: '15', phiAllowed: false,
};

export function SourceConfiguration({ data }: { data: IntegrationControlPlane }) {
  const create = useCreateIntegrationSource();
  const update = useUpdateIntegrationSource();
  const retire = useRetireIntegrationSource();
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<IntegrationSource | null>(null);
  const [form, setForm] = useState(emptySourceForm);

  const edit = (source: IntegrationSource) => {
    setEditing(source);
    setForm({
      sourceKey: source.sourceKey,
      sourceName: source.sourceName,
      vendor: source.vendor ?? '',
      systemClass: source.systemClass,
      environment: source.environment,
      baseUrl: '',
      interfaceType: source.interfaceType,
      activeStatus: source.configuredStatus,
      contractStatus: source.contractStatus,
      baaStatus: source.baaStatus,
      goLiveStatus: source.goLiveStatus,
      owner: source.owner ?? '',
      cadence: source.expectedCadenceMinutes?.toString() ?? '',
      phiAllowed: source.phiAllowed,
    });
    setOpen(true);
  };

  const close = () => { setOpen(false); setEditing(null); setForm(emptySourceForm); create.reset(); update.reset(); };
  const submit = (event: FormEvent) => {
    event.preventDefault();
    const common: Partial<IntegrationSourceInput> = {
      source_name: form.sourceName,
      vendor: form.vendor || null,
      system_class: form.systemClass,
      environment: form.environment,
      interface_type: form.interfaceType,
      active_status: form.activeStatus,
      contract_status: form.contractStatus,
      baa_status: form.baaStatus,
      phi_allowed: form.phiAllowed,
      go_live_status: form.goLiveStatus,
      owner: form.owner || null,
      expected_cadence_minutes: form.cadence ? Number(form.cadence) : null,
      ...(form.baseUrl ? { base_url: form.baseUrl } : {}),
    };
    if (editing) {
      update.mutate({ sourceId: editing.sourceId, input: common }, { onSuccess: close });
    } else {
      create.mutate({
        ...common,
        source_key: form.sourceKey,
        tenant_key: 'default',
        source_name: form.sourceName,
        system_class: form.systemClass,
        environment: form.environment,
        interface_type: form.interfaceType,
        active_status: form.activeStatus,
        contract_status: form.contractStatus,
        baa_status: form.baaStatus,
        go_live_status: form.goLiveStatus,
      } as IntegrationSourceInput, { onSuccess: close });
    }
  };
  const mutationError = errorMessage(create.error ?? update.error ?? retire.error);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Source Configuration</h3>
        <button type="button" className={secondaryButton} onClick={() => setOpen(true)}><Plus className="size-4" /> Add source</button>
      </div>
      {open && (
        <form onSubmit={submit} className="grid gap-3 rounded-md border border-healthcare-border p-3 sm:grid-cols-2 lg:grid-cols-4 dark:border-healthcare-border-dark">
          {!editing && <label className={labelClass}>Source key<input required value={form.sourceKey} onChange={(e) => setForm({ ...form, sourceKey: e.target.value })} className={inputClass} placeholder="epic.fhir.production" /></label>}
          <label className={labelClass}>Name<input required value={form.sourceName} onChange={(e) => setForm({ ...form, sourceName: e.target.value })} className={inputClass} /></label>
          <label className={labelClass}>Vendor<input value={form.vendor} onChange={(e) => setForm({ ...form, vendor: e.target.value })} className={inputClass} /></label>
          <label className={labelClass}>System class<select value={form.systemClass} onChange={(e) => setForm({ ...form, systemClass: e.target.value })} className={inputClass}>{['ehr','bed_flow','workforce','transport','evs','orders_results','perioperative','pharmacy','imaging','ems','facilities','rtls','nurse_call','erp','supply_chain','payer','hie','public_health','other'].map((v) => <option key={v} value={v}>{v.replaceAll('_',' ')}</option>)}</select></label>
          <label className={labelClass}>Interface<select value={form.interfaceType} onChange={(e) => setForm({ ...form, interfaceType: e.target.value })} className={inputClass}>{['fhir_r4','hl7v2','rest_api','webhook','sftp','file','mqtt','dicomweb','x12','ccda','direct','other'].map((v) => <option key={v} value={v}>{v.replaceAll('_',' ')}</option>)}</select></label>
          <label className={labelClass}>Environment<select value={form.environment} onChange={(e) => setForm({ ...form, environment: e.target.value })} className={inputClass}>{['sandbox','test','staging','production'].map((v) => <option key={v}>{v}</option>)}</select></label>
          <label className={`${labelClass} sm:col-span-2`}>HTTPS base URL<input value={form.baseUrl} onChange={(e) => setForm({ ...form, baseUrl: e.target.value })} className={inputClass} placeholder={editing && editing.baseUrlConfigured ? `Leave blank to keep ${editing.baseUrlOrigin}` : 'https://approved-host.example/fhir/r4'} /></label>
          <label className={labelClass}>State<select value={form.activeStatus} onChange={(e) => setForm({ ...form, activeStatus: e.target.value })} className={inputClass}>{['template','inactive','testing','active','degraded','disabled'].map((v) => <option key={v}>{v}</option>)}</select></label>
          <label className={labelClass}>Contract<select value={form.contractStatus} onChange={(e) => setForm({ ...form, contractStatus: e.target.value })} className={inputClass}>{['unknown','planning','review','executed','expired','not_required'].map((v) => <option key={v}>{v}</option>)}</select></label>
          <label className={labelClass}>BAA<select value={form.baaStatus} onChange={(e) => setForm({ ...form, baaStatus: e.target.value })} className={inputClass}>{['unknown','planning','review','executed','expired','not_required'].map((v) => <option key={v}>{v}</option>)}</select></label>
          <label className={labelClass}>Go live<select value={form.goLiveStatus} onChange={(e) => setForm({ ...form, goLiveStatus: e.target.value })} className={inputClass}>{['not_started','planning','testing','ready','live','paused','retired'].map((v) => <option key={v}>{v}</option>)}</select></label>
          <label className={labelClass}>Owner<input value={form.owner} onChange={(e) => setForm({ ...form, owner: e.target.value })} className={inputClass} /></label>
          <label className={labelClass}>Cadence (minutes)<input type="number" min="1" max="10080" value={form.cadence} onChange={(e) => setForm({ ...form, cadence: e.target.value })} className={inputClass} /></label>
          <label className="flex items-center gap-2 self-end py-2 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark"><input type="checkbox" checked={form.phiAllowed} onChange={(e) => setForm({ ...form, phiAllowed: e.target.checked })} /> PHI approved</label>
          <div className="flex items-end gap-2 lg:col-span-4"><button type="submit" disabled={create.isPending || update.isPending} className={primaryButton}>{editing ? 'Save source' : 'Create source'}</button><button type="button" className={secondaryButton} onClick={close}><X className="size-4" /> Cancel</button></div>
        </form>
      )}
      {mutationError && <div role="alert" className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark">{mutationError}</div>}
      <div className="divide-y divide-healthcare-border rounded-md border border-healthcare-border dark:divide-healthcare-border-dark dark:border-healthcare-border-dark">
        {data.sources.map((source) => <div key={source.sourceId} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"><div><div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{source.sourceName}</div><div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{source.sourceKey} · {source.baseUrlOrigin ?? 'No base URL'}</div></div><div className="flex gap-1"><button type="button" title="Edit source" className={secondaryButton} onClick={() => edit(source)}><Pencil className="size-4" /> Edit</button>{source.goLiveStatus !== 'retired' && <button type="button" title="Retire source" className={secondaryButton} disabled={retire.isPending} onClick={() => window.confirm(`Retire ${source.sourceName}?`) && retire.mutate(source.sourceId)}><Archive className="size-4" /> Retire</button>}</div></div>)}
        {data.sources.length === 0 && <div className="p-4 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No sources configured.</div>}
      </div>
    </div>
  );
}

export function EndpointConfiguration({ data }: { data: IntegrationControlPlane }) {
  const create = useCreateIntegrationEndpoint(); const update = useUpdateIntegrationEndpoint(); const remove = useDeleteIntegrationEndpoint();
  const [sourceId, setSourceId] = useState(0); const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ endpointType: 'api_base', url: '', authType: 'oauth2', tlsMode: 'system_ca', owner: '', cadence: '15' });
  useEffect(() => { if (!sourceId && data.sources[0]) setSourceId(data.sources[0].sourceId); }, [data.sources, sourceId]);
  const submit = (event: FormEvent) => { event.preventDefault(); create.mutate({ sourceId, input: { endpoint_type: form.endpointType, url: form.url, auth_type: form.authType, tls_mode: form.tlsMode, is_active: true, owner: form.owner || null, expected_cadence_minutes: form.cadence ? Number(form.cadence) : null } }, { onSuccess: () => { setOpen(false); setForm({ ...form, url: '' }); } }); };
  const error = errorMessage(create.error ?? update.error ?? remove.error);
  return <div className="space-y-3"><div className="flex justify-end"><button type="button" className={secondaryButton} disabled={!data.sources.length} onClick={() => setOpen(true)}><Plus className="size-4" /> Add endpoint</button></div>{open && <form onSubmit={submit} className="grid gap-3 rounded-md border border-healthcare-border p-3 sm:grid-cols-2 lg:grid-cols-4 dark:border-healthcare-border-dark"><label className={labelClass}>Source<select value={sourceId} onChange={(e) => setSourceId(Number(e.target.value))} className={inputClass}>{data.sources.map((s) => <option key={s.sourceId} value={s.sourceId}>{s.sourceName}</option>)}</select></label><label className={labelClass}>Endpoint type<select value={form.endpointType} onChange={(e) => setForm({ ...form, endpointType: e.target.value })} className={inputClass}>{['api_base','fhir_base','smart_discovery','oauth_token','webhook','interface_gateway','dicomweb','bulk_export','other'].map((v) => <option key={v}>{v}</option>)}</select></label><label className={`${labelClass} sm:col-span-2`}>HTTPS URL<input required value={form.url} onChange={(e) => setForm({ ...form, url: e.target.value })} className={inputClass} /></label><label className={labelClass}>Authentication<select value={form.authType} onChange={(e) => setForm({ ...form, authType: e.target.value })} className={inputClass}>{['none','oauth2','smart_backend','mtls','api_key_ref','basic_ref'].map((v) => <option key={v}>{v}</option>)}</select></label><label className={labelClass}>TLS<select value={form.tlsMode} onChange={(e) => setForm({ ...form, tlsMode: e.target.value })} className={inputClass}>{['system_ca','pinned_ca','mtls'].map((v) => <option key={v}>{v}</option>)}</select></label><label className={labelClass}>Owner<input value={form.owner} onChange={(e) => setForm({ ...form, owner: e.target.value })} className={inputClass} /></label><label className={labelClass}>Cadence (minutes)<input type="number" min="1" value={form.cadence} onChange={(e) => setForm({ ...form, cadence: e.target.value })} className={inputClass} /></label><div className="flex gap-2 lg:col-span-4"><button className={primaryButton} disabled={create.isPending}>Create endpoint</button><button type="button" className={secondaryButton} onClick={() => setOpen(false)}>Cancel</button></div></form>}{error && <div role="alert" className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark">{error}</div>}<div className="divide-y divide-healthcare-border rounded-md border border-healthcare-border dark:divide-healthcare-border-dark dark:border-healthcare-border-dark">{data.endpoints.map((endpoint) => <div key={endpoint.endpointId} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"><div><div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{endpoint.sourceName} · {endpoint.endpointType.replaceAll('_',' ')}</div><div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{endpoint.urlOrigin ?? 'No URL'} · {endpoint.isActive ? 'Active' : 'Disabled'}</div></div><div className="flex gap-1"><button type="button" className={secondaryButton} disabled={update.isPending} onClick={() => update.mutate({ sourceId: endpoint.sourceId, endpointId: endpoint.endpointId, input: { is_active: !endpoint.isActive } })}>{endpoint.isActive ? 'Disable' : 'Enable'}</button><button type="button" title="Delete endpoint" aria-label="Delete endpoint" className={secondaryButton} disabled={remove.isPending} onClick={() => window.confirm('Delete this endpoint?') && remove.mutate({ sourceId: endpoint.sourceId, endpointId: endpoint.endpointId })}><Trash2 className="size-4" /></button></div></div>)}{data.endpoints.length === 0 && <div className="p-4 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No endpoints configured.</div>}</div></div>;
}

export function CredentialConfiguration({ data }: { data: IntegrationControlPlane }) {
  const create = useCreateIntegrationCredential(); const update = useUpdateIntegrationCredential(); const remove = useDeleteIntegrationCredential(); const [open, setOpen] = useState(false); const [sourceId, setSourceId] = useState(0);
  const [form, setForm] = useState({ key: '', type: 'smart_backend_services', secretRef: '', certificateRef: '', jwksUri: '', rotatesAt: '', owner: '' });
  useEffect(() => { if (!sourceId && data.sources[0]) setSourceId(data.sources[0].sourceId); }, [data.sources, sourceId]);
  const submit = (event: FormEvent) => { event.preventDefault(); create.mutate({ sourceId, input: { credential_key: form.key, credential_type: form.type, secret_ref: form.secretRef || null, certificate_ref: form.certificateRef || null, jwks_uri: form.jwksUri || null, rotates_at: form.rotatesAt || null, is_active: true, owner: form.owner || null } }, { onSuccess: () => { setOpen(false); setForm({ ...form, key: '', secretRef: '', certificateRef: '', jwksUri: '' }); } }); };
  const error = errorMessage(create.error ?? update.error ?? remove.error);
  return <div className="space-y-3"><div className="flex justify-end"><button type="button" className={secondaryButton} disabled={!data.sources.length} onClick={() => setOpen(true)}><KeyRound className="size-4" /> Add reference</button></div>{open && <form onSubmit={submit} className="grid gap-3 rounded-md border border-healthcare-border p-3 sm:grid-cols-2 lg:grid-cols-4 dark:border-healthcare-border-dark"><label className={labelClass}>Source<select value={sourceId} onChange={(e) => setSourceId(Number(e.target.value))} className={inputClass}>{data.sources.map((s) => <option key={s.sourceId} value={s.sourceId}>{s.sourceName}</option>)}</select></label><label className={labelClass}>Credential key<input required value={form.key} onChange={(e) => setForm({ ...form, key: e.target.value })} className={inputClass} /></label><label className={labelClass}>Type<select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} className={inputClass}>{['smart_backend_services','oauth2_client','mtls','api_key','basic_auth','jwks'].map((v) => <option key={v}>{v.replaceAll('_',' ')}</option>)}</select></label><label className={labelClass}>Rotation date<input type="date" value={form.rotatesAt} onChange={(e) => setForm({ ...form, rotatesAt: e.target.value })} className={inputClass} /></label><label className={`${labelClass} sm:col-span-2`}>Secret manager reference<input value={form.secretRef} onChange={(e) => setForm({ ...form, secretRef: e.target.value })} className={inputClass} placeholder="vault://path/to/secret" /></label><label className={`${labelClass} sm:col-span-2`}>Certificate reference<input value={form.certificateRef} onChange={(e) => setForm({ ...form, certificateRef: e.target.value })} className={inputClass} placeholder="vault://path/to/certificate" /></label><label className={`${labelClass} sm:col-span-2`}>JWKS URI<input value={form.jwksUri} onChange={(e) => setForm({ ...form, jwksUri: e.target.value })} className={inputClass} placeholder="https://approved-host.example/.well-known/jwks.json" /></label><label className={labelClass}>Owner<input value={form.owner} onChange={(e) => setForm({ ...form, owner: e.target.value })} className={inputClass} /></label><div className="flex items-end gap-2"><button className={primaryButton} disabled={create.isPending}>Save reference</button><button type="button" className={secondaryButton} onClick={() => setOpen(false)}>Cancel</button></div></form>}{error && <div role="alert" className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark">{error}</div>}<div className="divide-y divide-healthcare-border rounded-md border border-healthcare-border dark:divide-healthcare-border-dark dark:border-healthcare-border-dark">{data.credentials.map((credential) => <div key={credential.credentialId} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"><div><div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{credential.sourceName} · {credential.credentialKey}</div><div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{credential.credentialType.replaceAll('_',' ')} · {credential.status}</div></div>{credential.sourceCredentialId !== null && <div className="flex gap-1"><button type="button" className={secondaryButton} disabled={update.isPending} onClick={() => update.mutate({ sourceId: credential.sourceId, credentialId: credential.sourceCredentialId!, input: { is_active: credential.status !== 'configured' } })}>{credential.status === 'configured' ? 'Disable' : 'Enable'}</button><button type="button" title="Delete credential reference" aria-label="Delete credential reference" className={secondaryButton} disabled={remove.isPending} onClick={() => window.confirm('Delete this credential reference?') && remove.mutate({ sourceId: credential.sourceId, credentialId: credential.sourceCredentialId! })}><Trash2 className="size-4" /></button></div>}</div>)}{data.credentials.length === 0 && <div className="p-4 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">No credential references configured.</div>}</div></div>;
}
