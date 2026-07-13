import React, { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import Card from '@/Components/Dashboard/Card';
import InputError from '@/Components/InputError';
import type { LifecycleSummary } from '@/Pages/Admin/Users/Index';

interface ExternalIdentity {
    id: number;
    provider: string;
    subject_fingerprint: string;
    provider_email_at_link?: string | null;
    linked_at?: string | null;
    is_active: boolean;
    unlinked_at?: string | null;
    relinked_at?: string | null;
}

interface PurgeRequest {
    uuid: string;
    author_user_id: number;
    author_name?: string | null;
    reason: string;
    requested_at?: string | null;
    expires_at?: string | null;
    status: string;
    decision?: string | null;
    executed?: boolean;
}

interface EditUser {
    id: number;
    name: string;
    email: string | null;
    username: string;
    role: string;
    is_active: boolean;
    is_protected: boolean;
    deactivated_at?: string | null;
    identity_purged_at?: string | null;
    provisioning_state?: string;
    external_identities?: ExternalIdentity[];
    purge_requests?: PurgeRequest[];
}

interface AccessScopeRow {
    id: number;
    organization_id: number | null;
    organization_label: string | null;
    facility_id: number | null;
    facility_label: string | null;
    grant_reason: string;
    granted_by_username: string | null;
    valid_from: string | null;
    valid_until: string | null;
    revoked_at: string | null;
    revocation_reason: string | null;
}

interface ScopeOption {
    id: number;
    key: string;
    label: string;
}

interface AuthorizationSummary {
    effective_roles: string[];
    effective_capabilities: string[];
    direct_capabilities: string[];
    capability_options: string[];
}

interface AuthProp {
    user?: { id: number };
    can?: Record<string, boolean>;
}

interface EditProps {
    auth?: AuthProp;
    user: EditUser;
    lifecycle?: LifecycleSummary;
    authorization?: AuthorizationSummary;
    access_scopes?: AccessScopeRow[];
    scope_options?: { organizations: ScopeOption[]; facilities: ScopeOption[] };
    redaction?: { piiVisible: boolean };
    sso_only?: boolean;
}

const inputClasses = 'mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300';

function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }
    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime()) ? '—' : parsed.toLocaleString();
}

function ExternalIdentityControl({ userId, identity, disabled }: {
    userId: number;
    identity: ExternalIdentity;
    disabled: boolean;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({ reason: '' });
    const operation = identity.is_active ? 'unlink' : 'relink';

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        post(`/users/${userId}/external-identities/${identity.id}/${operation}`, {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <li className="rounded-md border border-healthcare-border p-4 dark:border-healthcare-border-dark">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-medium capitalize text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {identity.provider}
                    </p>
                    <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Subject fingerprint {identity.subject_fingerprint}
                    </p>
                    {identity.provider_email_at_link && (
                        <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Provider email at link: {identity.provider_email_at_link}
                        </p>
                    )}
                </div>
                <span className={`rounded px-2 py-1 text-xs font-semibold ${identity.is_active
                    ? 'bg-healthcare-success/10 text-healthcare-success dark:text-healthcare-success-dark'
                    : 'bg-healthcare-critical/10 text-healthcare-critical dark:text-healthcare-critical-dark'
                }`}>
                    {identity.is_active ? 'Linked' : 'Unlinked'}
                </span>
            </div>
            <form onSubmit={submit} className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-start">
                <div className="flex-1">
                    <label htmlFor={`identity-reason-${identity.id}`} className="sr-only">
                        Reason to {operation} {identity.provider}
                    </label>
                    <input
                        id={`identity-reason-${identity.id}`}
                        type="text"
                        minLength={10}
                        maxLength={500}
                        value={data.reason}
                        onChange={(event) => setData('reason', event.target.value)}
                        placeholder={`Reason to ${operation} this identity`}
                        disabled={disabled || processing}
                        className="block w-full rounded-md border-healthcare-border bg-healthcare-surface text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
                        required
                    />
                    <InputError message={errors.reason || (errors as Record<string, string>).identity} className="mt-1" />
                </div>
                <button
                    type="submit"
                    disabled={disabled || processing || data.reason.trim().length < 10}
                    className="rounded-md border border-healthcare-border px-3 py-2 text-sm font-medium text-healthcare-text-primary disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark"
                >
                    {identity.is_active ? 'Unlink identity' : 'Relink identity'}
                </button>
            </form>
        </li>
    );
}

function PurgeRequestForm({ userId, disabled }: { userId: number; disabled: boolean }) {
    const { data, setData, post, processing, errors, reset } = useForm({ reason: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        post(`/users/${userId}/identity-purge-requests`, {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <form onSubmit={submit} className="mt-4 space-y-2">
            <label htmlFor="purge-request-reason" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                Purge justification
            </label>
            <textarea
                id="purge-request-reason"
                value={data.reason}
                onChange={(event) => setData('reason', event.target.value)}
                minLength={10}
                maxLength={500}
                disabled={disabled || processing}
                className="block w-full rounded-md border-healthcare-border bg-healthcare-surface text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
                required
            />
            <InputError message={errors.reason || (errors as Record<string, string>).user || (errors as Record<string, string>).governance} />
            <button
                type="submit"
                disabled={disabled || processing || data.reason.trim().length < 10}
                className="rounded-md bg-healthcare-critical px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
            >
                Request exceptional identity purge
            </button>
        </form>
    );
}

function PurgeRequestRow({ userId, request, actorId, canApprove, canExecute }: {
    userId: number;
    request: PurgeRequest;
    actorId?: number;
    canApprove: boolean;
    canExecute: boolean;
}) {
    const decisionForm = useForm({ decision: 'approved', reason: '' });
    const executionForm = useForm({});
    const canDecide = request.status === 'pending' && canApprove && Number(request.author_user_id) !== Number(actorId);
    const canRun = request.status === 'approved' && canExecute;

    const decide = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        decisionForm.post(`/admin/identity-purge-requests/${request.uuid}/decision`, { preserveScroll: true });
    };

    const execute = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        executionForm.post(`/users/${userId}/identity-purge-requests/${request.uuid}/execute`);
    };

    return (
        <li className="rounded-md border border-healthcare-border p-4 dark:border-healthcare-border-dark">
            <div className="flex flex-wrap justify-between gap-2">
                <div>
                    <p className="text-xs tabular-nums text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{request.uuid}</p>
                    <p className="mt-1 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{request.reason}</p>
                    <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Requested by {request.author_name || `user ${request.author_user_id}`}
                    </p>
                </div>
                <span className="h-fit rounded bg-healthcare-surface-secondary px-2 py-1 text-xs font-semibold uppercase text-healthcare-text-secondary dark:bg-healthcare-surface-secondary-dark dark:text-healthcare-text-secondary-dark">
                    {request.status}
                </span>
            </div>
            {canDecide && (
                <form onSubmit={decide} className="mt-4 grid gap-2 sm:grid-cols-[10rem_1fr_auto]">
                    <select
                        aria-label="Purge decision"
                        value={decisionForm.data.decision}
                        onChange={(event) => decisionForm.setData('decision', event.target.value)}
                        className="rounded-md border-healthcare-border bg-healthcare-surface text-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
                    >
                        <option value="approved">Approve</option>
                        <option value="rejected">Reject</option>
                    </select>
                    <input
                        aria-label="Purge decision reason"
                        value={decisionForm.data.reason}
                        onChange={(event) => decisionForm.setData('reason', event.target.value)}
                        minLength={10}
                        maxLength={500}
                        placeholder="Independent review reason"
                        className="rounded-md border-healthcare-border bg-healthcare-surface text-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
                        required
                    />
                    <button
                        type="submit"
                        disabled={decisionForm.processing || decisionForm.data.reason.trim().length < 10}
                        className="rounded-md border border-healthcare-border px-3 py-2 text-sm font-medium disabled:opacity-50 dark:border-healthcare-border-dark"
                    >
                        Record decision
                    </button>
                    <InputError message={decisionForm.errors.reason || (decisionForm.errors as Record<string, string>).governance} className="sm:col-span-3" />
                </form>
            )}
            {canRun && (
                <form onSubmit={execute} className="mt-4">
                    <button
                        type="submit"
                        disabled={executionForm.processing}
                        className="rounded-md bg-healthcare-critical px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        Execute approved purge
                    </button>
                    <InputError message={(executionForm.errors as Record<string, string>).user || (executionForm.errors as Record<string, string>).governance} className="mt-1" />
                </form>
            )}
        </li>
    );
}

function LifecyclePanel({ user, lifecycle, canManageIdentity }: {
    user: EditUser;
    lifecycle: LifecycleSummary;
    canManageIdentity: boolean;
}) {
    const revokeForm = useForm({ change_reason: 'credential_hygiene' });

    const revoke = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        revokeForm.post(`/users/${user.id}/revoke-access`, { preserveScroll: true });
    };

    const reconciliationLabels: Record<string, string> = {
        not_applicable: 'Local only (no IdP link)',
        reconciled: 'Groups reconciled at last IdP login',
        awaiting_login: 'Linked; awaiting next IdP login',
        unlinked: 'External identity unlinked',
    };

    const facts: Array<{ label: string; value: string }> = [
        { label: 'Identity source', value: lifecycle.identity_source },
        { label: 'Provisioning state', value: lifecycle.provisioning_state },
        {
            label: 'Group reconciliation',
            value: reconciliationLabels[lifecycle.group_reconciliation_state] ?? lifecycle.group_reconciliation_state,
        },
        {
            label: 'MFA assurance',
            value: lifecycle.mfa_assurance
                ? `IdP MFA verified ${formatDate(lifecycle.mfa_assurance.verified_at)}`
                : 'No IdP MFA evidence recorded',
        },
        { label: 'Last login', value: formatDate(lifecycle.last_login_at) },
        { label: 'Last meaningful activity', value: formatDate(lifecycle.last_meaningful_activity_at) },
        { label: 'Active browser sessions', value: String(lifecycle.active_session_count) },
        { label: 'Active API tokens', value: String(lifecycle.active_token_count) },
    ];

    return (
        <Card className="mt-6">
            <Card.Content>
                <h2 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    Identity lifecycle
                </h2>
                <dl className="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    {facts.map((fact) => (
                        <div key={fact.label}>
                            <dt className="text-xs font-medium uppercase tracking-wider text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                {fact.label}
                            </dt>
                            <dd className="mt-0.5 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark tabular-nums capitalize">
                                {fact.value}
                            </dd>
                        </div>
                    ))}
                </dl>
                {lifecycle.external_subjects.length > 0 && (
                    <p className="mt-3 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        External subjects:{' '}
                        {lifecycle.external_subjects.map((subject) => `${subject.provider} ${subject.subject_fingerprint}${subject.is_active ? '' : ' (unlinked)'}`).join(', ')}
                    </p>
                )}
                {canManageIdentity && !user.is_protected && (
                    <form onSubmit={revoke} className="mt-4 flex flex-wrap items-center gap-2">
                        <label htmlFor="revoke-reason" className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            Revoke sessions and tokens
                        </label>
                        <select
                            id="revoke-reason"
                            value={revokeForm.data.change_reason}
                            onChange={(event) => revokeForm.setData('change_reason', event.target.value)}
                            className="rounded-md border-healthcare-border bg-healthcare-surface text-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
                        >
                            <option value="credential_hygiene">Credential hygiene</option>
                            <option value="suspected_compromise">Suspected compromise</option>
                            <option value="security_incident_response">Security incident response</option>
                        </select>
                        <button
                            type="submit"
                            disabled={revokeForm.processing}
                            className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-3 py-2 text-sm font-medium text-healthcare-text-primary disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark"
                        >
                            <Icon icon="heroicons:no-symbol" className="h-4 w-4" aria-hidden="true" />
                            Revoke access
                        </button>
                        <InputError message={(revokeForm.errors as Record<string, string>).user} className="w-full" />
                    </form>
                )}
            </Card.Content>
        </Card>
    );
}

function AccessScopePanel({ user, scopes, options, disabled }: {
    user: EditUser;
    scopes: AccessScopeRow[];
    options: { organizations: ScopeOption[]; facilities: ScopeOption[] };
    disabled: boolean;
}) {
    const grantForm = useForm({
        organization_id: '',
        facility_id: '',
        grant_reason: '',
        valid_until: '',
    });
    const revokeForm = useForm({ revocation_reason: '' });
    const [revokingScopeId, setRevokingScopeId] = React.useState<number | null>(null);

    const grant = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        grantForm.transform((data) => ({
            organization_id: data.organization_id === '' ? null : Number(data.organization_id),
            facility_id: data.facility_id === '' ? null : Number(data.facility_id),
            grant_reason: data.grant_reason,
            valid_until: data.valid_until === '' ? null : data.valid_until,
        }));
        grantForm.post(`/users/${user.id}/access-scopes`, {
            preserveScroll: true,
            onSuccess: () => grantForm.reset(),
        });
    };

    const revoke = (scopeId: number) => (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        revokeForm.post(`/users/${user.id}/access-scopes/${scopeId}/revoke`, {
            preserveScroll: true,
            onSuccess: () => {
                revokeForm.reset();
                setRevokingScopeId(null);
            },
        });
    };

    return (
        <Card className="mt-6">
            <Card.Content>
                <h2 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    Organization and facility scopes
                </h2>
                <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Effective-dated boundaries consumed by the canonical authorization service. Granting or revoking a scope requires recent step-up authentication and is recorded in the audit ledger.
                </p>

                {scopes.length > 0 ? (
                    <ul className="mt-4 space-y-2">
                        {scopes.map((scope) => {
                            const active = scope.revoked_at === null;

                            return (
                                <li key={scope.id} className="rounded-md border border-healthcare-border p-3 text-sm dark:border-healthcare-border-dark">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            {scope.facility_label ?? scope.organization_label ?? 'Unknown boundary'}
                                            <span className="ml-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                {scope.facility_id !== null ? 'Facility' : 'Organization'}
                                            </span>
                                        </span>
                                        {active ? (
                                            <span className="inline-flex items-center gap-1 text-xs font-medium text-healthcare-success dark:text-healthcare-success-dark">
                                                <Icon icon="heroicons:check-circle" className="h-3.5 w-3.5" aria-hidden="true" />
                                                Effective
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                <Icon icon="heroicons:x-circle" className="h-3.5 w-3.5" aria-hidden="true" />
                                                Revoked {formatDate(scope.revoked_at)}
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                        {scope.grant_reason} · granted by {scope.granted_by_username ?? 'unknown'} · from {formatDate(scope.valid_from)}
                                        {scope.valid_until ? ` until ${formatDate(scope.valid_until)}` : ''}
                                    </p>
                                    {active && !disabled && (
                                        revokingScopeId === scope.id ? (
                                            <form onSubmit={revoke(scope.id)} className="mt-2 flex flex-col gap-2 sm:flex-row">
                                                <input
                                                    aria-label={`Reason to revoke scope ${scope.id}`}
                                                    value={revokeForm.data.revocation_reason}
                                                    onChange={(event) => revokeForm.setData('revocation_reason', event.target.value)}
                                                    minLength={10}
                                                    maxLength={500}
                                                    placeholder="Reason to revoke this scope"
                                                    className="flex-1 rounded-md border-healthcare-border bg-healthcare-surface text-sm dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark"
                                                    required
                                                />
                                                <button
                                                    type="submit"
                                                    disabled={revokeForm.processing || revokeForm.data.revocation_reason.trim().length < 10}
                                                    className="rounded-md border border-healthcare-border px-3 py-2 text-sm font-medium disabled:opacity-50 dark:border-healthcare-border-dark"
                                                >
                                                    Confirm revoke
                                                </button>
                                            </form>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => setRevokingScopeId(scope.id)}
                                                className="mt-2 text-xs font-medium text-healthcare-critical dark:text-healthcare-critical-dark"
                                            >
                                                Revoke scope
                                            </button>
                                        )
                                    )}
                                    <InputError
                                        message={revokingScopeId === scope.id ? (revokeForm.errors as Record<string, string>).scope || revokeForm.errors.revocation_reason : undefined}
                                        className="mt-1"
                                    />
                                </li>
                            );
                        })}
                    </ul>
                ) : (
                    <p className="mt-4 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        No explicit organization or facility scope has been granted.
                    </p>
                )}

                {!disabled && (
                    <form onSubmit={grant} className="mt-4 grid gap-2 sm:grid-cols-2">
                        <div>
                            <label htmlFor="scope-organization" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                Organization boundary
                            </label>
                            <select
                                id="scope-organization"
                                value={grantForm.data.organization_id}
                                onChange={(event) => {
                                    grantForm.setData('organization_id', event.target.value);
                                    if (event.target.value !== '') {
                                        grantForm.setData('facility_id', '');
                                    }
                                }}
                                className={inputClasses}
                            >
                                <option value="">None</option>
                                {options.organizations.map((option) => (
                                    <option key={option.id} value={option.id}>{option.label}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label htmlFor="scope-facility" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                Facility boundary
                            </label>
                            <select
                                id="scope-facility"
                                value={grantForm.data.facility_id}
                                onChange={(event) => {
                                    grantForm.setData('facility_id', event.target.value);
                                    if (event.target.value !== '') {
                                        grantForm.setData('organization_id', '');
                                    }
                                }}
                                className={inputClasses}
                            >
                                <option value="">None</option>
                                {options.facilities.map((option) => (
                                    <option key={option.id} value={option.id}>{option.label}</option>
                                ))}
                            </select>
                        </div>
                        <div className="sm:col-span-2">
                            <label htmlFor="scope-reason" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                Grant reason
                            </label>
                            <input
                                id="scope-reason"
                                value={grantForm.data.grant_reason}
                                onChange={(event) => grantForm.setData('grant_reason', event.target.value)}
                                minLength={10}
                                maxLength={500}
                                placeholder="Why this account needs the boundary"
                                className={inputClasses}
                                required
                            />
                        </div>
                        <div>
                            <label htmlFor="scope-valid-until" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                Valid until <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">(optional)</span>
                            </label>
                            <input
                                id="scope-valid-until"
                                type="date"
                                value={grantForm.data.valid_until}
                                onChange={(event) => grantForm.setData('valid_until', event.target.value)}
                                className={inputClasses}
                            />
                        </div>
                        <div className="flex items-end">
                            <button
                                type="submit"
                                disabled={grantForm.processing
                                    || grantForm.data.grant_reason.trim().length < 10
                                    || (grantForm.data.organization_id === '' && grantForm.data.facility_id === '')}
                                className="rounded-md bg-healthcare-info px-3 py-2 text-sm font-semibold text-white disabled:opacity-50 dark:bg-healthcare-info-dark"
                            >
                                Grant scope
                            </button>
                        </div>
                        <InputError
                            message={grantForm.errors.grant_reason
                                || (grantForm.errors as Record<string, string>).scope
                                || (grantForm.errors as Record<string, string>).organization_id
                                || (grantForm.errors as Record<string, string>).facility_id
                                || (grantForm.errors as Record<string, string>).user}
                            className="sm:col-span-2"
                        />
                    </form>
                )}
            </Card.Content>
        </Card>
    );
}

function CapabilityPanel({ user, authorization, disabled }: {
    user: EditUser;
    authorization: AuthorizationSummary;
    disabled: boolean;
}) {
    const grantForm = useForm({ capability: '' });
    const revokeForm = useForm({ capability: '' });

    const grant = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        grantForm.post(`/users/${user.id}/capabilities`, {
            preserveScroll: true,
            onSuccess: () => grantForm.reset(),
        });
    };

    const revoke = (capability: string) => {
        revokeForm.transform(() => ({ capability }));
        revokeForm.post(`/users/${user.id}/capabilities/revoke`, { preserveScroll: true });
    };

    return (
        <Card className="mt-6">
            <Card.Content>
                <h2 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    Roles and capabilities
                </h2>
                <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    Effective access is resolved by the canonical role-capability service from the scalar role, role profiles, and direct grants below. Direct grants require recent step-up authentication.
                </p>

                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <h3 className="text-xs font-medium uppercase tracking-wider text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Effective roles
                        </h3>
                        <p className="mt-1 flex flex-wrap gap-1">
                            {authorization.effective_roles.length > 0 ? authorization.effective_roles.map((role) => (
                                <span key={role} className="rounded bg-healthcare-surface-secondary px-1.5 py-0.5 text-xs font-medium text-healthcare-text-secondary dark:bg-healthcare-surface-secondary-dark dark:text-healthcare-text-secondary-dark">
                                    {role}
                                </span>
                            )) : <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">None</span>}
                        </p>
                    </div>
                    <div>
                        <h3 className="text-xs font-medium uppercase tracking-wider text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            Effective capabilities <span className="tabular-nums">({authorization.effective_capabilities.length})</span>
                        </h3>
                        <p className="mt-1 flex flex-wrap gap-1">
                            {authorization.effective_capabilities.map((capability) => (
                                <span key={capability} className="rounded bg-healthcare-surface-secondary px-1.5 py-0.5 text-xs font-medium text-healthcare-text-secondary dark:bg-healthcare-surface-secondary-dark dark:text-healthcare-text-secondary-dark">
                                    {capability}
                                </span>
                            ))}
                        </p>
                    </div>
                </div>

                <div className="mt-4">
                    <h3 className="text-xs font-medium uppercase tracking-wider text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Direct capability grants
                    </h3>
                    {authorization.direct_capabilities.length > 0 ? (
                        <ul className="mt-2 space-y-1">
                            {authorization.direct_capabilities.map((capability) => (
                                <li key={capability} className="flex items-center justify-between rounded-md border border-healthcare-border px-3 py-2 text-sm dark:border-healthcare-border-dark">
                                    <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{capability}</span>
                                    {!disabled && (
                                        <button
                                            type="button"
                                            onClick={() => revoke(capability)}
                                            disabled={revokeForm.processing}
                                            className="text-xs font-medium text-healthcare-critical disabled:opacity-50 dark:text-healthcare-critical-dark"
                                        >
                                            Revoke capability
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            No direct capability grants; access derives from role profiles.
                        </p>
                    )}
                    <InputError message={(revokeForm.errors as Record<string, string>).capability || (revokeForm.errors as Record<string, string>).user} className="mt-1" />
                </div>

                {!disabled && (
                    <form onSubmit={grant} className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-end">
                        <div className="flex-1">
                            <label htmlFor="capability-grant" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                Grant direct capability
                            </label>
                            <select
                                id="capability-grant"
                                value={grantForm.data.capability}
                                onChange={(event) => grantForm.setData('capability', event.target.value)}
                                className={inputClasses}
                                required
                            >
                                <option value="">Select a capability</option>
                                {authorization.capability_options.map((capability) => (
                                    <option key={capability} value={capability}>{capability}</option>
                                ))}
                            </select>
                        </div>
                        <button
                            type="submit"
                            disabled={grantForm.processing || grantForm.data.capability === ''}
                            className="rounded-md bg-healthcare-info px-3 py-2 text-sm font-semibold text-white disabled:opacity-50 dark:bg-healthcare-info-dark"
                        >
                            Grant capability
                        </button>
                        <InputError message={grantForm.errors.capability || (grantForm.errors as Record<string, string>).user} className="sm:w-full" />
                    </form>
                )}
            </Card.Content>
        </Card>
    );
}

export default function Edit({
    auth,
    user,
    lifecycle,
    authorization,
    access_scopes: accessScopes,
    scope_options: scopeOptions,
    sso_only: ssoOnly,
}: EditProps) {
    const canManagePrivileges = Boolean(auth?.can?.manage_privileges);
    const canManageIdentity = Boolean(auth?.can?.manage_identity);
    const identityLocked = Boolean(user.is_protected || user.identity_purged_at);
    const assignmentsLocked = identityLocked || !canManageIdentity;
    const { data, setData, put, processing, errors } = useForm({
        name: user.name || '',
        email: user.email || '',
        username: user.username || '',
        password: '',
        password_confirmation: '',
        role: user.role || 'user',
        is_active: user.is_active ?? true,
        change_reason: 'routine_profile_update',
    });

    const submit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        put(`/users/${user.id}`);
    };

    return (
        <DashboardLayout>
            <Head title="Edit User" />

            <div className="p-4">
                <div>
                    <div className="flex justify-between items-center mb-6">
                        <h1 className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            Edit User: {user.name}
                        </h1>
                        <Link
                            href="/users"
                            className="inline-flex items-center px-4 py-2 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md text-healthcare-text-primary dark:text-healthcare-text-primary-dark hover:bg-healthcare-surface-secondary dark:hover:bg-healthcare-surface-secondary-dark transition-colors duration-300"
                        >
                            Back to Users
                        </Link>
                    </div>

                    <Card>
                        <Card.Content>
                            {user.is_protected && (
                                <div className="mb-6 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/10 p-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    This is a protected account. Routine administration may update its display name, but identity, credentials, role, and active state require the separately governed break-glass process.
                                </div>
                            )}
                            {user.identity_purged_at && (
                                <div className="mb-6 rounded-md border border-healthcare-critical/40 bg-healthcare-critical/10 p-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    This account has undergone an approved identity purge. It cannot be reactivated or relinked.
                                </div>
                            )}
                            {ssoOnly && !user.is_protected && (
                                <div className="mb-6 rounded-md border border-healthcare-info/40 bg-healthcare-info/10 p-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    SSO-only policy is active: local passwords cannot be set for this account. Credentials are managed by the identity provider; only sealed break-glass accounts hold local passwords.
                                </div>
                            )}
                            <form onSubmit={submit} className="space-y-6">
                                <div>
                                    <label htmlFor="name" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        Name
                                    </label>
                                    <input
                                        id="name"
                                        type="text"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        disabled={Boolean(user.identity_purged_at)}
                                        className={inputClasses}
                                        required
                                    />
                                    <InputError message={errors.name} className="mt-2" />
                                </div>

                                <div>
                                    <label htmlFor="email" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        Email
                                    </label>
                                    <input
                                        id="email"
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        disabled={identityLocked}
                                        className={inputClasses}
                                        required
                                    />
                                    <InputError message={errors.email} className="mt-2" />
                                </div>

                                <div>
                                    <label htmlFor="username" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        Username
                                    </label>
                                    <input
                                        id="username"
                                        type="text"
                                        value={data.username}
                                        onChange={(e) => setData('username', e.target.value)}
                                        disabled={identityLocked}
                                        className={inputClasses}
                                        required
                                    />
                                    <InputError message={errors.username} className="mt-2" />
                                </div>

                                <div>
                                    <label htmlFor="password" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        Password <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">(Leave blank to keep current password)</span>
                                    </label>
                                    <input
                                        id="password"
                                        type="password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        disabled={identityLocked || Boolean(ssoOnly && !user.is_protected)}
                                        className={inputClasses}
                                    />
                                    <InputError message={errors.password} className="mt-2" />
                                </div>

                                <div>
                                    <label htmlFor="password_confirmation" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        Confirm Password
                                    </label>
                                    <input
                                        id="password_confirmation"
                                        type="password"
                                        value={data.password_confirmation}
                                        onChange={(e) => setData('password_confirmation', e.target.value)}
                                        disabled={identityLocked || Boolean(ssoOnly && !user.is_protected)}
                                        className={inputClasses}
                                    />
                                    <InputError message={errors.password_confirmation} className="mt-2" />
                                </div>

                                <div>
                                    <label htmlFor="role" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        Role
                                    </label>
                                    <select
                                        id="role"
                                        value={data.role}
                                        onChange={(e) => setData('role', e.target.value)}
                                        disabled={identityLocked || !canManagePrivileges}
                                        className={inputClasses}
                                    >
                                        <option value="user">User</option>
                                        <option value="admin">Admin</option>
                                        <option value="superuser">Superuser</option>
                                    </select>
                                    <InputError message={errors.role} className="mt-2" />
                                    {!canManagePrivileges && (
                                        <p className="mt-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Changing authorization roles requires the managePrivileges capability.
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={data.is_active}
                                            onChange={(e) => setData('is_active', e.target.checked)}
                                            disabled={identityLocked}
                                            className="rounded border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-info focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
                                        />
                                        <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            Active account
                                        </span>
                                    </label>
                                    <InputError message={errors.is_active} className="mt-2" />
                                </div>

                                <div>
                                    <label htmlFor="change_reason" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        Reason for change
                                    </label>
                                    <select
                                        id="change_reason"
                                        value={data.change_reason}
                                        onChange={(e) => setData('change_reason', e.target.value)}
                                        className={inputClasses}
                                        required
                                    >
                                        <option value="routine_profile_update">Routine profile update</option>
                                        <option value="identity_correction">Identity correction</option>
                                        <option value="role_change_approved">Approved role change</option>
                                        <option value="account_deactivation">Account deactivation</option>
                                        <option value="account_reactivation">Account reactivation</option>
                                        <option value="credential_reset">Credential reset</option>
                                    </select>
                                    <InputError message={errors.change_reason} className="mt-2" />
                                </div>

                                <div className="flex items-center justify-end">
                                    <button
                                        type="submit"
                                        disabled={processing || Boolean(user.identity_purged_at)}
                                        className="inline-flex items-center px-4 py-2 bg-healthcare-info dark:bg-healthcare-info-dark text-white rounded-md hover:bg-healthcare-info-dark dark:hover:bg-healthcare-info transition-colors duration-300 disabled:opacity-50"
                                    >
                                        Update User
                                    </button>
                                </div>
                            </form>
                        </Card.Content>
                    </Card>

                    {lifecycle && (
                        <LifecyclePanel user={user} lifecycle={lifecycle} canManageIdentity={canManageIdentity} />
                    )}

                    {authorization && (
                        <CapabilityPanel
                            user={user}
                            authorization={authorization}
                            disabled={identityLocked || !canManagePrivileges}
                        />
                    )}

                    {scopeOptions && (
                        <AccessScopePanel
                            user={user}
                            scopes={accessScopes ?? []}
                            options={scopeOptions}
                            disabled={assignmentsLocked}
                        />
                    )}

                    <Card className="mt-6">
                        <Card.Content>
                            <h2 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                External identities
                            </h2>
                            <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                Unlinking immediately revokes browser sessions and API tokens. Relinking only restores the previously validated provider subject; it cannot attach a typed or unverified subject.
                            </p>
                            {user.external_identities?.length ? (
                                <ul className="mt-4 space-y-3">
                                    {user.external_identities.map((identity) => (
                                        <ExternalIdentityControl
                                            key={identity.id}
                                            userId={user.id}
                                            identity={identity}
                                            disabled={!canManageIdentity || user.is_protected || Boolean(user.identity_purged_at)}
                                        />
                                    ))}
                                </ul>
                            ) : (
                                <p className="mt-4 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    No external identity is linked. A new link is created only after a validated institutional OIDC login.
                                </p>
                            )}
                        </Card.Content>
                    </Card>

                    <Card className="mt-6">
                        <Card.Content>
                            <h2 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                Exceptional identity purge
                            </h2>
                            <p className="mt-1 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                This irreversible workflow removes direct identifiers and all access paths while retaining the numeric account key required by clinical, operational, governance, and audit records. It requires prior deactivation, recent step-up, and approval by someone other than the author.
                            </p>
                            {!user.identity_purged_at && user.is_active && (
                                <p className="mt-3 rounded-md bg-healthcare-warning/10 p-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    Deactivate and revoke this account before requesting a purge.
                                </p>
                            )}
                            {!user.identity_purged_at && !user.is_active && !user.is_protected && (
                                <PurgeRequestForm userId={user.id} disabled={!canManageIdentity} />
                            )}
                            {(user.purge_requests?.length ?? 0) > 0 && (
                                <ul className="mt-4 space-y-3">
                                    {user.purge_requests?.map((request) => (
                                        <PurgeRequestRow
                                            key={request.uuid}
                                            userId={user.id}
                                            request={request}
                                            actorId={auth?.user?.id}
                                            canApprove={canManagePrivileges}
                                            canExecute={canManageIdentity}
                                        />
                                    ))}
                                </ul>
                            )}
                        </Card.Content>
                    </Card>
                </div>
            </div>
        </DashboardLayout>
    );
}
