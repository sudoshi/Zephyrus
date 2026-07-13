import type { CredentialRotationInput, IntegrationControlPlane, NetworkRouteInput } from '@/features/integrations/api';
import {
  useCreateNetworkRoute,
  useCredentialVersions,
  useNetworkRoutes,
  useRetireNetworkRoute,
  useRequestCredentialRotation,
  useValidateCredential,
  useValidateNetworkRoute,
} from '@/features/integrations/hooks';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { KeyRound, Network, RefreshCcw, ShieldCheck, Trash2, X } from 'lucide-react';
import { useState, type FormEvent } from 'react';

const inputClass = 'w-full rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark';
const labelClass = 'space-y-1 text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark';
const secondaryButton = 'inline-flex min-h-9 items-center gap-2 rounded-md border border-healthcare-border px-3 py-1.5 text-sm font-semibold text-healthcare-text-primary hover:bg-healthcare-hover disabled:cursor-not-allowed disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark';
const primaryButton = 'inline-flex min-h-9 items-center gap-2 rounded-md bg-healthcare-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-healthcare-primary/90 disabled:cursor-not-allowed disabled:opacity-50';

function errorMessage(error: unknown): string | null {
  if (!axios.isAxiosError(error)) return error ? 'Governance request failed.' : null;
  const errors = error.response?.data?.errors as Record<string, string[]> | undefined;
  return errors ? Object.values(errors).flat()[0] : error.response?.data?.error?.message ?? error.response?.data?.message ?? 'Governance request failed.';
}

function timestamp(value: string | null): string {
  return value ? new Date(value).toLocaleString() : 'Not bounded';
}

export function CredentialAuthorityConsole({
  data,
  selectedSourceId,
}: {
  data: IntegrationControlPlane;
  selectedSourceId: number | null;
}) {
  const validate = useValidateCredential();
  const requestRotation = useRequestCredentialRotation();
  const [expandedCredentialId, setExpandedCredentialId] = useState<number | null>(null);
  const [rotationCredentialId, setRotationCredentialId] = useState<number | null>(null);
  const [rotationForm, setRotationForm] = useState({
    secretRef: '', certificateRef: '', jwksUri: '', validFrom: '', expiresAt: '', rotatesAt: '',
    overlapEndsAt: '', reason: 'Rotate this credential through independent approval.',
  });
  const versions = useCredentialVersions(selectedSourceId, expandedCredentialId);
  const credentials = data.credentials.filter((credential) =>
    selectedSourceId === null || credential.sourceId === selectedSourceId,
  );
  const error = errorMessage(validate.error ?? versions.error ?? requestRotation.error);
  const rotationCredential = credentials.find((credential) => credential.sourceCredentialId === rotationCredentialId);
  const submitRotation = async (event: FormEvent) => {
    event.preventDefault();
    if (!rotationCredential || rotationCredential.sourceCredentialId === null) return;
    const input: CredentialRotationInput = {
      ...(rotationForm.secretRef ? { secret_ref: rotationForm.secretRef } : {}),
      ...(rotationForm.certificateRef ? { certificate_ref: rotationForm.certificateRef } : {}),
      ...(rotationForm.jwksUri ? { jwks_uri: rotationForm.jwksUri } : {}),
      ...(rotationForm.validFrom ? { valid_from: rotationForm.validFrom } : {}),
      ...(rotationForm.expiresAt ? { expires_at: rotationForm.expiresAt } : {}),
      ...(rotationForm.rotatesAt ? { rotates_at: rotationForm.rotatesAt } : {}),
      ...(rotationForm.overlapEndsAt ? { rotation_overlap_ends_at: rotationForm.overlapEndsAt } : {}),
    };
    try {
      await requestRotation.mutateAsync({
        sourceId: rotationCredential.sourceId,
        credentialId: rotationCredential.sourceCredentialId,
        input,
        reason: rotationForm.reason,
      });
    } catch (caught) {
      if (axios.isAxiosError(caught) && caught.response?.status === 428) {
        const url = caught.response.data?.error?.reauthentication_url;
        if (typeof url === 'string') router.visit(url);
      }
    }
  };

  return <div className="space-y-4">
    <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
      {data.secretProviders.map((provider) => <div key={provider.scheme} className="rounded-md border border-healthcare-border px-3 py-2 dark:border-healthcare-border-dark">
        <div className="font-mono text-xs font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{provider.scheme}://</div>
        <div className={`mt-1 text-xs font-medium ${provider.enabled ? 'text-healthcare-success dark:text-healthcare-success-dark' : 'text-healthcare-warning dark:text-healthcare-warning-dark'}`}>
          {provider.enabled ? 'Provider configured' : 'Bootstrap configuration required'}
        </div>
      </div>)}
    </div>
    <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
      Provider references are identifiers only. Secret values are resolved at validation or runtime and are never returned by this console.
    </p>
    {error ? <p role="alert" className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark">{error}</p> : null}
    <div className="divide-y divide-healthcare-border rounded-md border border-healthcare-border dark:divide-healthcare-border-dark dark:border-healthcare-border-dark">
      {credentials.map((credential) => <div key={credential.credentialId} className="px-3 py-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {credential.sourceName} · {credential.credentialKey}
            </div>
            <div className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {credential.credentialType.replaceAll('_', ' ')} · {credential.credentialState} · authority version {credential.credentialVersionNumber ?? 'unversioned'}
            </div>
            <div className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Provider {credential.secretProviderScheme ?? credential.certificateProviderScheme ?? 'not set'}
              {credential.providerVersion ? ` · provider version ${credential.providerVersion}` : ''}
              {` · validation ${credential.validationStatus} · rotation ${credential.rotationState}`}
            </div>
            <div className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Credential expiry {timestamp(credential.expiresAtIso)}
              {credential.certificateExpiresAtIso ? ` · certificate expiry ${timestamp(credential.certificateExpiresAtIso)}` : ''}
              {credential.certificateChainLength !== null ? ` · certificate chain ${credential.certificateChainLength}` : ''}
              {credential.providerLeaseExpiresAtIso ? ` · lease expiry ${timestamp(credential.providerLeaseExpiresAtIso)}` : ''}
              {credential.validationErrorCode ? ` · ${credential.validationErrorCode}` : ''}
            </div>
          </div>
          {credential.sourceCredentialId !== null ? <div className="flex flex-wrap gap-1">
            <button type="button" className={secondaryButton} disabled={validate.isPending || selectedSourceId !== credential.sourceId} onClick={() => validate.mutate({
              sourceId: credential.sourceId,
              credentialId: credential.sourceCredentialId!,
            })}>
              <RefreshCcw className="size-4" /> Validate
            </button>
            <button type="button" className={secondaryButton} disabled={selectedSourceId !== credential.sourceId} onClick={() => setExpandedCredentialId(
              expandedCredentialId === credential.sourceCredentialId ? null : credential.sourceCredentialId,
            )}>
              <KeyRound className="size-4" /> Versions
            </button>
            <button type="button" className={secondaryButton} disabled={selectedSourceId !== credential.sourceId || ['revoked', 'expired'].includes(credential.credentialState)} onClick={() => setRotationCredentialId(credential.sourceCredentialId)}>
              <ShieldCheck className="size-4" /> Request rotation
            </button>
          </div> : <span className="text-xs text-healthcare-warning dark:text-healthcare-warning-dark">Legacy projection; governed authority shown in its linked source credential row.</span>}
        </div>
      </div>)}
      {credentials.length === 0 ? <div className="p-4 text-sm text-healthcare-text-secondary">No credential authorities in the selected scope.</div> : null}
    </div>
    {expandedCredentialId !== null ? <div className="rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
      <div className="flex items-center justify-between gap-2">
        <h4 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Immutable credential authority versions</h4>
        <button type="button" aria-label="Close credential versions" className={secondaryButton} onClick={() => setExpandedCredentialId(null)}><X className="size-4" /></button>
      </div>
      {versions.isLoading ? <p className="mt-2 text-xs text-healthcare-text-secondary">Loading versions…</p> : <div className="mt-2 space-y-2">
        {(versions.data ?? []).map((version) => <div key={version.credentialVersionId} className="rounded border border-healthcare-border px-3 py-2 text-xs dark:border-healthcare-border-dark">
          <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Version {version.versionNumber} · {version.credentialState}</div>
          <div className="mt-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {version.secretProviderScheme ?? version.certificateProviderScheme ?? 'No provider'} · valid {timestamp(version.validFromIso)} · expires {timestamp(version.expiresAtIso)} · rotates {timestamp(version.rotatesAtIso)}
          </div>
          <div className="mt-1 font-mono text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">authority {version.authoritySha256.slice(0, 20)}…</div>
        </div>)}
      </div>}
    </div> : null}
    {rotationCredential ? <form onSubmit={submitRotation} className="grid gap-3 rounded-md border border-healthcare-border p-3 sm:grid-cols-2 lg:grid-cols-4 dark:border-healthcare-border-dark">
      <div className="flex items-center justify-between gap-2 sm:col-span-2 lg:col-span-4">
        <div><h4 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Request independently approved rotation</h4><p className="text-xs text-healthcare-text-secondary">{rotationCredential.sourceName} · {rotationCredential.credentialKey}. Re-enter the exact same target fields when executing after approval.</p></div>
        <button type="button" aria-label="Close rotation request" className={secondaryButton} onClick={() => setRotationCredentialId(null)}><X className="size-4" /></button>
      </div>
      <label className={`${labelClass} sm:col-span-2`}>New secret reference<input value={rotationForm.secretRef} onChange={(event) => setRotationForm({ ...rotationForm, secretRef: event.target.value })} className={inputClass} placeholder="vault://mount/path#field" /></label>
      <label className={`${labelClass} sm:col-span-2`}>New certificate reference<input value={rotationForm.certificateRef} onChange={(event) => setRotationForm({ ...rotationForm, certificateRef: event.target.value })} className={inputClass} placeholder="vault://mount/path#certificate" /></label>
      <label className={`${labelClass} sm:col-span-2`}>New JWKS URI<input value={rotationForm.jwksUri} onChange={(event) => setRotationForm({ ...rotationForm, jwksUri: event.target.value })} className={inputClass} /></label>
      <label className={labelClass}>Valid from<input type="datetime-local" value={rotationForm.validFrom} onChange={(event) => setRotationForm({ ...rotationForm, validFrom: event.target.value })} className={inputClass} /></label>
      <label className={labelClass}>Expires at<input type="datetime-local" value={rotationForm.expiresAt} onChange={(event) => setRotationForm({ ...rotationForm, expiresAt: event.target.value })} className={inputClass} /></label>
      <label className={labelClass}>Rotation deadline<input type="datetime-local" value={rotationForm.rotatesAt} onChange={(event) => setRotationForm({ ...rotationForm, rotatesAt: event.target.value })} className={inputClass} /></label>
      <label className={labelClass}>Overlap ends at<input type="datetime-local" value={rotationForm.overlapEndsAt} onChange={(event) => setRotationForm({ ...rotationForm, overlapEndsAt: event.target.value })} className={inputClass} /></label>
      <label className={`${labelClass} sm:col-span-2 lg:col-span-3`}>Governed reason<input required minLength={10} maxLength={500} value={rotationForm.reason} onChange={(event) => setRotationForm({ ...rotationForm, reason: event.target.value })} className={inputClass} placeholder="10–500 characters; no PHI or credential values" /></label>
      <div className="flex items-end"><button className={primaryButton} disabled={requestRotation.isPending || ![rotationForm.secretRef, rotationForm.certificateRef, rotationForm.jwksUri, rotationForm.validFrom, rotationForm.expiresAt, rotationForm.rotatesAt, rotationForm.overlapEndsAt].some(Boolean)}>{requestRotation.isPending ? 'Requesting…' : 'Request independent approval'}</button></div>
      {requestRotation.data ? <p role="status" className="text-xs text-healthcare-success sm:col-span-2 lg:col-span-4">Rotation request {requestRotation.data.changeRequestUuid} is awaiting an independent decision.</p> : null}
    </form> : null}
  </div>;
}

const emptyRouteForm = {
  routeKey: '', endpointId: '', transport: 'public_internet', hostname: '', port: '443', proxyUrl: '',
  dnsPolicy: 'public_only', cidrs: '', egressPolicyKey: 'integration-https-egress', mtlsRequired: false,
  clientCredentialId: '', serverName: '', reason: 'Authorize this exact endpoint through controlled egress.',
};

export function NetworkRouteConfiguration({
  data,
  selectedSourceId,
}: {
  data: IntegrationControlPlane;
  selectedSourceId: number | null;
}) {
  const routesQuery = useNetworkRoutes(selectedSourceId);
  const create = useCreateNetworkRoute();
  const validate = useValidateNetworkRoute();
  const retire = useRetireNetworkRoute();
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(emptyRouteForm);
  const endpoints = data.endpoints.filter((endpoint) => endpoint.sourceId === selectedSourceId);
  const credentials = data.credentials.filter((credential) =>
    credential.sourceId === selectedSourceId
      && credential.sourceCredentialId !== null
      && ['active', 'rotating'].includes(credential.credentialState),
  );
  const routes = routesQuery.data ?? data.networkRoutes.filter((route) => route.sourceId === selectedSourceId);
  const error = errorMessage(routesQuery.error ?? create.error ?? validate.error ?? retire.error);

  const selectEndpoint = (endpointId: string) => {
    const endpoint = endpoints.find((candidate) => candidate.endpointId === Number(endpointId));
    const url = endpoint?.urlOrigin ? new URL(endpoint.urlOrigin) : null;
    setForm({
      ...form,
      endpointId,
      hostname: url?.hostname ?? '',
      port: url?.port || '443',
      serverName: url?.hostname ?? '',
    });
  };
  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (selectedSourceId === null) return;
    const input: NetworkRouteInput = {
      route_key: form.routeKey,
      source_endpoint_id: Number(form.endpointId),
      transport: form.transport as NetworkRouteInput['transport'],
      hostname: form.hostname,
      port: Number(form.port),
      proxy_url: form.proxyUrl || null,
      dns_policy: form.dnsPolicy as NetworkRouteInput['dns_policy'],
      allowed_ip_cidrs: form.cidrs.split(/[\n,]/).map((value) => value.trim()).filter(Boolean),
      egress_policy_key: form.egressPolicyKey,
      mtls_required: form.mtlsRequired,
      client_credential_id: form.mtlsRequired ? Number(form.clientCredentialId) : null,
      server_name: form.serverName || form.hostname,
      change_reason: form.reason,
    };
    create.mutate({ sourceId: selectedSourceId, input }, { onSuccess: () => {
      setOpen(false);
      setForm(emptyRouteForm);
      routesQuery.refetch();
    }});
  };

  return <div className="space-y-3">
    <div className="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h3 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Governed outbound network routes</h3>
        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">DNS is re-resolved and pinned at connection time; only address fingerprints are retained.</p>
      </div>
      <button type="button" className={secondaryButton} disabled={selectedSourceId === null || endpoints.length === 0} onClick={() => setOpen(true)}><Network className="size-4" /> Add route</button>
    </div>
    {open ? <form onSubmit={submit} className="grid gap-3 rounded-md border border-healthcare-border p-3 sm:grid-cols-2 lg:grid-cols-4 dark:border-healthcare-border-dark">
      <label className={labelClass}>Route key<input required minLength={3} value={form.routeKey} onChange={(event) => setForm({ ...form, routeKey: event.target.value })} className={inputClass} placeholder="epic-fhir-primary" /></label>
      <label className={labelClass}>Exact endpoint<select required value={form.endpointId} onChange={(event) => selectEndpoint(event.target.value)} className={inputClass}>
        <option value="">Select endpoint</option>
        {endpoints.map((endpoint) => <option key={endpoint.endpointId} value={endpoint.endpointId}>{endpoint.endpointType} · {endpoint.urlOrigin}</option>)}
      </select></label>
      <label className={labelClass}>Transport<select value={form.transport} onChange={(event) => {
        const transport = event.target.value;
        setForm({ ...form, transport, dnsPolicy: transport === 'public_internet' ? 'public_only' : 'private_only' });
      }} className={inputClass}>
        {['public_internet', 'vpn', 'private_link', 'direct_connect', 'interface_engine'].map((value) => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}
      </select></label>
      <label className={labelClass}>DNS policy<select value={form.dnsPolicy} onChange={(event) => setForm({ ...form, dnsPolicy: event.target.value })} className={inputClass}>
        <option value="public_only">public only</option><option value="allowlist">explicit allowlist</option><option value="private_only">private only</option>
      </select></label>
      <label className={labelClass}>Hostname<input required value={form.hostname} onChange={(event) => setForm({ ...form, hostname: event.target.value })} className={inputClass} /></label>
      <label className={labelClass}>Port<input required type="number" min={1} max={65535} value={form.port} onChange={(event) => setForm({ ...form, port: event.target.value })} className={inputClass} /></label>
      <label className={labelClass}>TLS server name<input value={form.serverName} onChange={(event) => setForm({ ...form, serverName: event.target.value })} className={inputClass} /></label>
      <label className={labelClass}>Egress policy key<input required value={form.egressPolicyKey} onChange={(event) => setForm({ ...form, egressPolicyKey: event.target.value })} className={inputClass} /></label>
      <label className={`${labelClass} sm:col-span-2`}>Allowed CIDRs<textarea value={form.cidrs} onChange={(event) => setForm({ ...form, cidrs: event.target.value })} className={inputClass} placeholder="10.40.0.0/16, 2001:db8:40::/48" /></label>
      <label className={`${labelClass} sm:col-span-2`}>HTTPS proxy URL<input value={form.proxyUrl} onChange={(event) => setForm({ ...form, proxyUrl: event.target.value })} className={inputClass} placeholder="https://approved-proxy.example:443" /></label>
      <label className={labelClass}><span>Client authentication</span><span className="flex min-h-10 items-center gap-2"><input type="checkbox" checked={form.mtlsRequired} onChange={(event) => setForm({ ...form, mtlsRequired: event.target.checked })} />Require mTLS</span></label>
      <label className={labelClass}>mTLS credential<select disabled={!form.mtlsRequired} required={form.mtlsRequired} value={form.clientCredentialId} onChange={(event) => setForm({ ...form, clientCredentialId: event.target.value })} className={inputClass}>
        <option value="">Select credential</option>{credentials.map((credential) => <option key={credential.credentialId} value={credential.sourceCredentialId!}>{credential.credentialKey}</option>)}
      </select></label>
      <label className={`${labelClass} sm:col-span-2`}>Change reason<input required minLength={10} maxLength={500} value={form.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} className={inputClass} placeholder="10–500 characters; no PHI or credentials" /></label>
      <div className="flex items-end gap-2 sm:col-span-2"><button className={primaryButton} disabled={create.isPending}><ShieldCheck className="size-4" /> Validate and save route</button><button type="button" className={secondaryButton} onClick={() => setOpen(false)}>Cancel</button></div>
    </form> : null}
    {error ? <p role="alert" className="text-sm text-healthcare-critical dark:text-healthcare-critical-dark">{error}</p> : null}
    <div className="divide-y divide-healthcare-border rounded-md border border-healthcare-border dark:divide-healthcare-border-dark dark:border-healthcare-border-dark">
      {routes.map((route) => <div key={route.networkRouteId} className="flex flex-wrap items-center justify-between gap-2 px-3 py-3">
        <div>
          <div className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{route.routeKey} · {route.hostname}:{route.port}</div>
          <div className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {route.transport.replaceAll('_', ' ')} · {route.dnsPolicy.replaceAll('_', ' ')} · {route.status} · {route.lastAddressCount} resolved address{route.lastAddressCount === 1 ? '' : 'es'}{route.mtlsRequired ? ' · mTLS' : ''}
          </div>
          <div className="mt-1 font-mono text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">policy {route.policySha256?.slice(0, 20) ?? 'unobserved'}…{route.lastErrorCode ? ` · ${route.lastErrorCode}` : ''}</div>
        </div>
        <div className="flex gap-1">
          <button type="button" className={secondaryButton} disabled={validate.isPending} onClick={() => validate.mutate({ sourceId: route.sourceId, routeId: route.networkRouteId })}><RefreshCcw className="size-4" /> Revalidate</button>
          <button type="button" className={secondaryButton} disabled={retire.isPending} onClick={() => {
            const reason = window.prompt('Network route retirement reason (10–500 characters):');
            if (!reason || reason.trim().length < 10) return;
            retire.mutate({ sourceId: route.sourceId, routeId: route.networkRouteId, reason: reason.trim() });
          }}><Trash2 className="size-4" /> Retire</button>
        </div>
      </div>)}
      {routes.length === 0 ? <div className="p-4 text-sm text-healthcare-text-secondary">No governed network routes configured for the selected source.</div> : null}
    </div>
  </div>;
}
