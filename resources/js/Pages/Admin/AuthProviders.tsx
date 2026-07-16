import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { ArrowLeft, CheckCircle2, KeyRound, LockKeyhole, RefreshCw, ShieldCheck, TriangleAlert } from 'lucide-react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { AdminSectionHeading } from '@/Pages/Admin/components/AdminPrimitives';

interface ProviderSettings {
  discovery_url?: string;
  client_id?: string;
  redirect_uri?: string;
  scopes?: string[];
  allowed_groups?: string[];
  admin_groups?: string[];
}

interface AuthProvidersProps {
  local: {
    enabled: boolean;
    registrationEnabled: boolean;
  };
  oidc: {
    providerType: 'oidc';
    stored: {
      exists: boolean;
      enabled: boolean;
      displayName: string;
      settings: ProviderSettings;
    };
    effective: {
      enabled: boolean;
      publiclyAvailable: boolean;
      displayName: string;
      settings: ProviderSettings;
      clientSecretConfigured: boolean;
    };
    networkPolicy: {
      allowedHosts: string[];
      allowedRedirectUris: string[];
      privateNetworksAllowed: boolean;
    };
  };
}

interface OidcDiagnostic {
  status: 'healthy' | 'degraded' | 'failed';
  checkedAt: string;
  reason?: string;
  issuer?: string;
  authorization_endpoint?: string;
  token_endpoint?: string;
  jwks_uri?: string;
  signing_key_count?: number;
  signing_algorithms?: string[];
  latency_ms?: number;
}

interface ProviderForm {
  enabled: boolean;
  displayName: string;
  discoveryUrl: string;
  clientId: string;
  redirectUri: string;
  scopes: string;
  allowedGroups: string;
  adminGroups: string;
  changeReason: 'identity_provider_configuration' | 'identity_provider_emergency_disable';
}

const fieldClass = 'mt-1 w-full rounded-md border border-healthcare-border bg-healthcare-surface px-3 py-2 text-sm text-healthcare-text-primary shadow-sm outline-none transition focus:border-healthcare-info focus:ring-2 focus:ring-healthcare-info/20 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark';

function listValue(stored: string[] | undefined, effective: string[] | undefined): string {
  return (stored ?? effective ?? []).join(', ');
}

function parseList(value: string): string[] {
  return Array.from(new Set(value.split(',').map((item) => item.trim()).filter(Boolean)));
}

export default function AuthProviders({ local, oidc }: AuthProvidersProps) {
  const initialForm = useMemo<ProviderForm>(() => ({
    enabled: oidc.stored.exists ? oidc.stored.enabled : oidc.effective.enabled,
    displayName: oidc.stored.displayName || oidc.effective.displayName,
    discoveryUrl: oidc.stored.settings.discovery_url ?? oidc.effective.settings.discovery_url ?? '',
    clientId: oidc.stored.settings.client_id ?? oidc.effective.settings.client_id ?? '',
    redirectUri: oidc.stored.settings.redirect_uri ?? oidc.effective.settings.redirect_uri ?? '',
    scopes: listValue(oidc.stored.settings.scopes, oidc.effective.settings.scopes),
    allowedGroups: listValue(oidc.stored.settings.allowed_groups, oidc.effective.settings.allowed_groups),
    adminGroups: listValue(oidc.stored.settings.admin_groups, oidc.effective.settings.admin_groups),
    changeReason: 'identity_provider_configuration',
  }), [oidc]);
  const [form, setForm] = useState(initialForm);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [diagnosing, setDiagnosing] = useState(false);
  const [diagnostic, setDiagnostic] = useState<OidcDiagnostic | null>(null);
  const isDirty = JSON.stringify(form) !== JSON.stringify(initialForm);

  useEffect(() => {
    setForm(initialForm);
  }, [initialForm]);

  const update = <K extends keyof ProviderForm>(key: K, value: ProviderForm[K]) => {
    setForm((current) => ({ ...current, [key]: value }));
    setMessage(null);
  };

  const diagnose = async () => {
    setDiagnosing(true);
    setDiagnostic(null);

    try {
      const response = await axios.post<OidcDiagnostic>('/admin/auth-providers/oidc/diagnostics');
      setDiagnostic(response.data);
    } catch (error) {
      if (axios.isAxiosError(error) && error.response?.data) {
        setDiagnostic(error.response.data as OidcDiagnostic);
      } else {
        setDiagnostic({
          status: 'failed',
          reason: 'diagnostic_request_failed',
          checkedAt: new Date().toISOString(),
        });
      }
    } finally {
      setDiagnosing(false);
    }
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setErrors({});
    setMessage(null);

    try {
      await axios.put('/admin/auth-providers/oidc', {
        is_enabled: form.enabled,
        display_name: form.displayName,
        settings: {
          discovery_url: form.discoveryUrl,
          client_id: form.clientId,
          redirect_uri: form.redirectUri,
          scopes: parseList(form.scopes),
          allowed_groups: parseList(form.allowedGroups),
          admin_groups: parseList(form.adminGroups),
        },
        change_reason: form.changeReason,
      });
      setMessage('OIDC provider settings saved. Effective availability has been refreshed.');
      router.reload({ only: ['oidc'] });
    } catch (error) {
      if (axios.isAxiosError(error) && error.response?.status === 428) {
        window.location.assign(error.response.data?.error?.reauthentication_url ?? '/confirm-password');
      } else if (axios.isAxiosError(error) && error.response?.status === 422) {
        setErrors(error.response.data.errors ?? {});
        setMessage('Review the highlighted configuration values.');
      } else {
        setMessage('The provider configuration could not be saved. No changes were applied.');
      }
    } finally {
      setSaving(false);
    }
  };

  const errorFor = (key: string) => errors[key]?.[0];

  return (
    <DashboardLayout>
      <Head title="Authentication Providers" />
      <PageContentLayout
        title="Authentication Providers"
        subtitle="Govern interactive sign-in methods without exposing deployment secrets"
        headerContent={null}
      >
        <div className="space-y-5">
          <Link href="/admin" className="inline-flex items-center gap-1 text-sm font-medium text-healthcare-info hover:underline dark:text-healthcare-info-dark">
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
            Administration overview
          </Link>

          <section className="grid gap-3 md:grid-cols-3">
            <StatusCard
              title="Local passwords"
              value={local.enabled ? 'Enabled' : 'Disabled'}
              detail={local.registrationEnabled ? 'Self-registration enabled' : 'Self-registration disabled'}
              healthy={!local.registrationEnabled}
            />
            <StatusCard
              title="Enterprise OIDC"
              value={oidc.effective.enabled ? 'Enabled' : 'Disabled'}
              detail={oidc.effective.publiclyAvailable ? 'Available on the login page' : 'Not available on the login page'}
              healthy={oidc.effective.publiclyAvailable}
            />
            <StatusCard
              title="OIDC client secret"
              value={oidc.effective.clientSecretConfigured ? 'Configured' : 'Missing'}
              detail="Deployment-managed; never stored here"
              healthy={oidc.effective.clientSecretConfigured}
            />
          </section>

          <section className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
            <AdminSectionHeading
              title="Enterprise OpenID Connect"
              description="Configure issuer discovery, redirect binding, and group admission. Client secrets remain in the deployment secret store."
            />

            <div className="mb-4 flex gap-3 rounded-md border border-healthcare-info/30 bg-healthcare-info/5 p-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              <LockKeyhole className="mt-0.5 h-4 w-4 shrink-0 text-healthcare-info dark:text-healthcare-info-dark" aria-hidden="true" />
              <p>
                This page intentionally has no client-secret field. Set <code className="font-mono text-xs">OIDC_CLIENT_SECRET</code> through the deployment environment, then return here to verify that it is configured.
              </p>
            </div>

            <div className="mb-4 grid gap-3 rounded-md border border-healthcare-border bg-healthcare-surface-secondary p-3 text-xs dark:border-healthcare-border-dark dark:bg-healthcare-surface-hover-dark md:grid-cols-2">
              <div>
                <p className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Approved OIDC hosts</p>
                <p className="mt-1 break-words font-mono text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {oidc.networkPolicy.allowedHosts.join(', ') || 'None — outbound OIDC is fail-closed'}
                </p>
              </div>
              <div>
                <p className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Approved redirect URIs</p>
                <p className="mt-1 break-words font-mono text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {oidc.networkPolicy.allowedRedirectUris.join(', ') || 'None — interactive OIDC is fail-closed'}
                </p>
              </div>
              <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark md:col-span-2">
                Private network identity providers: <strong>{oidc.networkPolicy.privateNetworksAllowed ? 'explicitly allowed' : 'denied'}</strong>. Change this deployment policy outside the application.
              </p>
            </div>

            <form className="space-y-4" onSubmit={submit}>
              <label className="block text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Change reason
                <select
                  value={form.changeReason}
                  onChange={(event) => update('changeReason', event.target.value as ProviderForm['changeReason'])}
                  className={fieldClass}
                  required
                >
                  <option value="identity_provider_configuration">Approved identity-provider configuration</option>
                  <option value="identity_provider_emergency_disable">Emergency provider disable</option>
                </select>
              </label>
              <label className="flex items-start gap-3 rounded-md border border-healthcare-border p-3 dark:border-healthcare-border-dark">
                <input
                  type="checkbox"
                  checked={form.enabled}
                  onChange={(event) => update('enabled', event.target.checked)}
                  className="mt-0.5 h-4 w-4 rounded border-healthcare-border text-healthcare-info focus:ring-healthcare-info"
                />
                <span>
                  <span className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Enable the stored OIDC provider</span>
                  <span className="block text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Once saved, this governed setting is authoritative. The deployment flag is used only before a provider record exists.
                  </span>
                </span>
              </label>

              <div className="grid gap-4 md:grid-cols-2">
                <Field label="Login button label" error={errorFor('display_name')}>
                  <input value={form.displayName} onChange={(event) => update('displayName', event.target.value)} className={fieldClass} maxLength={80} required />
                </Field>
                <Field label="Client ID" error={errorFor('settings.client_id')}>
                  <input value={form.clientId} onChange={(event) => update('clientId', event.target.value)} className={fieldClass} autoComplete="off" />
                </Field>
                <Field label="OIDC discovery URL" error={errorFor('settings.discovery_url')}>
                  <input type="url" value={form.discoveryUrl} onChange={(event) => update('discoveryUrl', event.target.value)} className={fieldClass} placeholder="https://identity.example/application/o/zephyrus/.well-known/openid-configuration" />
                </Field>
                <Field label="Redirect URI" error={errorFor('settings.redirect_uri')}>
                  <input type="url" value={form.redirectUri} onChange={(event) => update('redirectUri', event.target.value)} className={fieldClass} placeholder="https://zephyrus.example/auth/oidc/callback" />
                </Field>
                <Field label="Scopes" hint="Comma separated" error={errorFor('settings.scopes')}>
                  <input value={form.scopes} onChange={(event) => update('scopes', event.target.value)} className={fieldClass} placeholder="openid, profile, email, groups" />
                </Field>
                <Field label="Allowed groups" hint="Comma separated; exact IdP group names" error={errorFor('settings.allowed_groups')}>
                  <input value={form.allowedGroups} onChange={(event) => update('allowedGroups', event.target.value)} className={fieldClass} />
                </Field>
                <Field label="Administrator groups" hint="Comma separated; subset of admitted groups" error={errorFor('settings.admin_groups')}>
                  <input value={form.adminGroups} onChange={(event) => update('adminGroups', event.target.value)} className={fieldClass} />
                </Field>
              </div>

              {message && (
                <div role="status" className="flex items-start gap-2 rounded-md bg-healthcare-surface-secondary px-3 py-2 text-sm text-healthcare-text-primary dark:bg-healthcare-surface-hover-dark dark:text-healthcare-text-primary-dark">
                  {Object.keys(errors).length > 0 ? <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0 text-healthcare-warning" /> : <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-healthcare-success" />}
                  {message}
                </div>
              )}

              <div className="flex flex-wrap justify-end gap-2">
                <button
                  type="button"
                  onClick={diagnose}
                  disabled={diagnosing || saving || isDirty || !form.discoveryUrl}
                  className="inline-flex items-center gap-2 rounded-md border border-healthcare-border bg-healthcare-surface px-4 py-2 text-sm font-semibold text-healthcare-text-primary shadow-sm hover:bg-healthcare-hover disabled:cursor-not-allowed disabled:opacity-60 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark dark:hover:bg-healthcare-hover-dark"
                  title={isDirty ? 'Save provider settings before running diagnostics' : undefined}
                >
                  <RefreshCw className={`h-4 w-4 ${diagnosing ? 'animate-spin' : ''}`} aria-hidden="true" />
                  {diagnosing ? 'Testing…' : 'Test discovery and JWKS'}
                </button>
                <button type="submit" disabled={saving} className="inline-flex items-center gap-2 rounded-md bg-healthcare-info px-4 py-2 text-sm font-semibold text-white shadow-sm hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60">
                  <ShieldCheck className="h-4 w-4" aria-hidden="true" />
                  {saving ? 'Saving…' : 'Save provider settings'}
                </button>
              </div>
            </form>

            {diagnostic && <DiagnosticResult diagnostic={diagnostic} />}
          </section>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
}

function DiagnosticResult({ diagnostic }: { diagnostic: OidcDiagnostic }) {
  const healthy = diagnostic.status === 'healthy';
  const failed = diagnostic.status === 'failed';

  return (
    <div className={`mt-4 rounded-md border p-3 text-sm ${failed ? 'border-healthcare-danger/40 bg-healthcare-danger/5' : healthy ? 'border-healthcare-success/40 bg-healthcare-success/5' : 'border-healthcare-warning/40 bg-healthcare-warning/5'}`} role="status">
      <div className="flex items-start gap-2">
        {healthy ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-healthcare-success" /> : <TriangleAlert className={`mt-0.5 h-4 w-4 shrink-0 ${failed ? 'text-healthcare-danger' : 'text-healthcare-warning'}`} />}
        <div className="min-w-0 flex-1">
          <p className="font-semibold capitalize text-healthcare-text-primary dark:text-healthcare-text-primary-dark">OIDC diagnostic: {diagnostic.status}</p>
          {diagnostic.reason && <p className="mt-1 font-mono text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{diagnostic.reason}</p>}
          {diagnostic.issuer && (
            <dl className="mt-2 grid gap-x-4 gap-y-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark md:grid-cols-[auto_1fr]">
              <dt className="font-semibold">Issuer</dt><dd className="break-all font-mono">{diagnostic.issuer}</dd>
              <dt className="font-semibold">JWKS</dt><dd className="break-all font-mono">{diagnostic.jwks_uri}</dd>
              <dt className="font-semibold">Signing keys</dt><dd>{diagnostic.signing_key_count ?? 0}{diagnostic.signing_algorithms?.length ? ` (${diagnostic.signing_algorithms.join(', ')})` : ''}</dd>
              <dt className="font-semibold">Latency</dt><dd>{diagnostic.latency_ms ?? 0} ms</dd>
            </dl>
          )}
          <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Checked <time dateTime={diagnostic.checkedAt}>{new Date(diagnostic.checkedAt).toLocaleString()}</time>. No client secret or user token was transmitted.
          </p>
        </div>
      </div>
    </div>
  );
}

function StatusCard({ title, value, detail, healthy }: { title: string; value: string; detail: string; healthy: boolean }) {
  return (
    <div className="rounded-md border border-healthcare-border bg-healthcare-surface p-4 shadow-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{title}</p>
          <p className="mt-1 text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</p>
        </div>
        <span className={`flex h-8 w-8 items-center justify-center rounded-full ${healthy ? 'bg-healthcare-success/10 text-healthcare-success' : 'bg-healthcare-warning/10 text-healthcare-warning'}`}>
          {healthy ? <KeyRound className="h-4 w-4" aria-hidden="true" /> : <TriangleAlert className="h-4 w-4" aria-hidden="true" />}
        </span>
      </div>
      <p className="mt-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{detail}</p>
    </div>
  );
}

function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: React.ReactNode }) {
  return (
    <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
      {label}
      {hint && <span className="ml-1 text-xs font-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">({hint})</span>}
      {children}
      {error && <span className="mt-1 block text-xs text-healthcare-danger">{error}</span>}
    </label>
  );
}
