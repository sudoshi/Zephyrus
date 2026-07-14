import React, { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import axios, { isAxiosError } from 'axios';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import Card from '@/Components/Dashboard/Card';

export interface LifecycleSummary {
    identity_source: string;
    provisioning_state: string;
    external_subjects: Array<{
        id: number;
        provider: string;
        subject_fingerprint: string;
        is_active: boolean;
        linked_at: string | null;
    }>;
    group_reconciliation_state: string;
    mfa_assurance: { method: string; verified_at: string } | null;
    last_login_at: string | null;
    last_meaningful_activity_at: string | null;
    active_session_count: number;
    active_token_count: number;
}

export interface UserRow {
    id: number;
    name: string;
    username: string;
    email: string | null;
    role: string;
    is_active: boolean;
    is_protected: boolean;
    deactivated_at?: string | null;
    identity_purged_at?: string | null;
    created_at?: string;
    lifecycle?: LifecycleSummary;
}

export interface PreviewMember {
    id: number;
    name: string | null;
    username: string | null;
    role: string | null;
    is_protected: boolean;
    eligible: boolean;
    blocked_reason: string | null;
    blocked_message: string | null;
}

interface BulkPreview {
    members: PreviewMember[];
    eligible_count: number;
    blocked_count: number;
}

interface IndexProps {
    users: UserRow[];
    redaction?: { piiVisible: boolean };
}

const RECONCILIATION_LABELS: Record<string, { label: string; icon: string }> = {
    not_applicable: { label: 'Local only', icon: 'heroicons:minus-small' },
    reconciled: { label: 'Groups reconciled', icon: 'heroicons:check-circle' },
    awaiting_login: { label: 'Awaiting IdP login', icon: 'heroicons:clock' },
    unlinked: { label: 'Unlinked', icon: 'heroicons:x-circle' },
};

const BULK_REASONS = [
    { value: 'bulk_account_deactivation', label: 'Bulk account deactivation' },
    { value: 'workforce_offboarding', label: 'Workforce offboarding' },
    { value: 'security_incident_response', label: 'Security incident response' },
    { value: 'access_review_remediation', label: 'Access review remediation' },
];

function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }
    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime()) ? '—' : parsed.toLocaleDateString();
}

function extractErrorMessage(error: unknown): string {
    if (isAxiosError(error)) {
        const data: unknown = error.response?.data;
        if (data && typeof data === 'object') {
            const record = data as Record<string, unknown>;
            if (typeof record.message === 'string' && record.message !== '') {
                return record.message;
            }
            const nested = record.error;
            if (nested && typeof nested === 'object'
                && typeof (nested as Record<string, unknown>).message === 'string') {
                return (nested as Record<string, unknown>).message as string;
            }
        }

        return error.message;
    }

    return 'The request failed.';
}

function LifecycleCell({ lifecycle }: { lifecycle?: LifecycleSummary }) {
    if (!lifecycle) {
        return <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">—</span>;
    }
    const reconciliation = RECONCILIATION_LABELS[lifecycle.group_reconciliation_state]
        ?? { label: lifecycle.group_reconciliation_state, icon: 'heroicons:question-mark-circle' };

    return (
        <div className="flex flex-col gap-0.5">
            <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark capitalize">
                {lifecycle.identity_source}
                <span className="ml-1.5 rounded bg-healthcare-surface-secondary dark:bg-healthcare-surface-hover-dark px-1.5 py-0.5 text-xs font-medium uppercase text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {lifecycle.provisioning_state}
                </span>
            </span>
            <span className="inline-flex items-center gap-1 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                <Icon icon={reconciliation.icon} className="h-3.5 w-3.5" aria-hidden="true" />
                {reconciliation.label}
            </span>
        </div>
    );
}

function AssuranceCell({ lifecycle }: { lifecycle?: LifecycleSummary }) {
    if (!lifecycle) {
        return <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">—</span>;
    }

    return (
        <div className="flex flex-col gap-0.5 text-xs">
            {lifecycle.mfa_assurance ? (
                <span className="inline-flex items-center gap-1 font-medium text-healthcare-success dark:text-healthcare-success-dark">
                    <Icon icon="heroicons:shield-check" className="h-3.5 w-3.5" aria-hidden="true" />
                    IdP MFA {formatDate(lifecycle.mfa_assurance.verified_at)}
                </span>
            ) : (
                <span className="inline-flex items-center gap-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    <Icon icon="heroicons:minus-small" className="h-3.5 w-3.5" aria-hidden="true" />
                    No MFA evidence
                </span>
            )}
            <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                Login {formatDate(lifecycle.last_login_at)} · Activity {formatDate(lifecycle.last_meaningful_activity_at)}
            </span>
        </div>
    );
}

export default function Index({ users, redaction }: IndexProps) {
    const [selected, setSelected] = useState<number[]>([]);
    const [preview, setPreview] = useState<BulkPreview | null>(null);
    const [reason, setReason] = useState<string>(BULK_REASONS[0].value);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [message, setMessage] = useState<string | null>(null);

    const selectable = useMemo(
        () => users.filter((user) => user.is_active && !user.is_protected && !user.identity_purged_at),
        [users],
    );

    const toggle = (id: number) => {
        setPreview(null);
        setError(null);
        setSelected((current) => (current.includes(id)
            ? current.filter((item) => item !== id)
            : [...current, id]));
    };

    const toggleAll = () => {
        setPreview(null);
        setError(null);
        setSelected((current) => (current.length === selectable.length
            ? []
            : selectable.map((user) => user.id)));
    };

    const requestPreview = async () => {
        setBusy(true);
        setError(null);
        setMessage(null);
        try {
            const response = await axios.post<BulkPreview>('/users/bulk-deactivation/preview', {
                user_ids: selected,
            });
            setPreview(response.data);
        } catch (requestError: unknown) {
            setPreview(null);
            setError(extractErrorMessage(requestError));
        } finally {
            setBusy(false);
        }
    };

    const executeBulk = async () => {
        setBusy(true);
        setError(null);
        try {
            const response = await axios.post<{ deactivated_count: number }>('/users/bulk-deactivation', {
                user_ids: selected,
                change_reason: reason,
            });
            setMessage(`Deactivated ${response.data.deactivated_count} account(s).`);
            setSelected([]);
            setPreview(null);
            router.reload({ only: ['users'] });
        } catch (requestError: unknown) {
            if (isAxiosError(requestError) && requestError.response?.status === 428) {
                const data = requestError.response.data as { error?: { reauthentication_url?: string } };
                const url = data.error?.reauthentication_url;
                if (url) {
                    window.location.assign(url);

                    return;
                }
            }
            setError(extractErrorMessage(requestError));
        } finally {
            setBusy(false);
        }
    };

    return (
        <DashboardLayout>
            <Head title="User Management" />

            <div className="p-4">
                <div>
                    <div className="flex justify-between items-center mb-4">
                        <h1 className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            Users
                        </h1>
                        <Link
                            href="/users/create"
                            className="inline-flex items-center px-4 py-2 bg-healthcare-info dark:bg-healthcare-info-dark text-white rounded-md hover:bg-healthcare-info-dark dark:hover:bg-healthcare-info transition-colors duration-300"
                        >
                            <Icon icon="heroicons:plus" className="w-5 h-5 mr-2" />
                            Add User
                        </Link>
                    </div>

                    {redaction && !redaction.piiVisible && (
                        <div className="mb-4 rounded-md border border-healthcare-border bg-healthcare-surface-secondary p-3 text-sm text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-surface-hover-dark dark:text-healthcare-text-secondary-dark">
                            <span className="inline-flex items-center gap-1.5">
                                <Icon icon="heroicons:eye-slash" className="h-4 w-4" aria-hidden="true" />
                                Email addresses are partially masked for your role; identity administration rights are required to view contact details.
                            </span>
                        </div>
                    )}

                    {message && (
                        <div className="mb-4 rounded-md border border-healthcare-success/40 bg-healthcare-success/10 p-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {message}
                        </div>
                    )}

                    {selected.length > 0 && (
                        <Card className="mb-4">
                            <Card.Content>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {selected.length} account(s) selected for deactivation
                                    </p>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={requestPreview}
                                            disabled={busy}
                                            className="rounded-md border border-healthcare-border px-3 py-2 text-sm font-medium text-healthcare-text-primary disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-primary-dark"
                                        >
                                            Preview deactivation
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setSelected([]);
                                                setPreview(null);
                                                setError(null);
                                            }}
                                            className="rounded-md px-3 py-2 text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                        >
                                            Clear selection
                                        </button>
                                    </div>
                                </div>

                                {error && (
                                    <p className="mt-3 rounded-md bg-healthcare-critical/10 p-3 text-sm text-healthcare-critical dark:text-healthcare-critical-dark" role="alert">
                                        {error}
                                    </p>
                                )}

                                {preview && (
                                    <div className="mt-4 space-y-3">
                                        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            {preview.eligible_count} account(s) will be deactivated and revoked;{' '}
                                            {preview.blocked_count} blocked. Execution is all-or-nothing: remove blocked accounts from the selection before executing.
                                        </p>
                                        <ul className="space-y-1">
                                            {preview.members.map((member) => (
                                                <li
                                                    key={member.id}
                                                    className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-healthcare-border px-3 py-2 text-sm dark:border-healthcare-border-dark"
                                                >
                                                    <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {member.name ?? `User ${member.id}`}
                                                        <span className="ml-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                            {member.username ?? ''}
                                                        </span>
                                                    </span>
                                                    {member.eligible ? (
                                                        <span className="inline-flex items-center gap-1 text-xs font-medium text-healthcare-success dark:text-healthcare-success-dark">
                                                            <Icon icon="heroicons:check-circle" className="h-3.5 w-3.5" aria-hidden="true" />
                                                            Will deactivate
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 text-xs font-medium text-healthcare-warning dark:text-healthcare-warning-dark">
                                                            <Icon icon="heroicons:no-symbol" className="h-3.5 w-3.5" aria-hidden="true" />
                                                            Blocked: {member.blocked_message ?? member.blocked_reason}
                                                        </span>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <label htmlFor="bulk-reason" className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                Reason
                                            </label>
                                            <select
                                                id="bulk-reason"
                                                value={reason}
                                                onChange={(event) => setReason(event.target.value)}
                                                className="rounded-md border-healthcare-border bg-healthcare-surface text-sm text-healthcare-text-primary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark"
                                            >
                                                {BULK_REASONS.map((option) => (
                                                    <option key={option.value} value={option.value}>{option.label}</option>
                                                ))}
                                            </select>
                                            <button
                                                type="button"
                                                onClick={executeBulk}
                                                disabled={busy || preview.blocked_count > 0 || preview.eligible_count === 0}
                                                className="rounded-md bg-healthcare-critical px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                                            >
                                                Deactivate {preview.eligible_count} account(s)
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </Card.Content>
                        </Card>
                    )}

                    <Card>
                        <Card.Content>
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                    <thead>
                                        <tr>
                                            <th className="px-3 py-2 text-left">
                                                <input
                                                    type="checkbox"
                                                    aria-label="Select all deactivatable users"
                                                    checked={selectable.length > 0 && selected.length === selectable.length}
                                                    onChange={toggleAll}
                                                    className="rounded border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-info focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark"
                                                />
                                            </th>
                                            {['Name', 'Username', 'Email', 'Role', 'Status', 'Identity', 'Assurance & activity'].map((heading) => (
                                                <th key={heading} className="px-4 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                                                    {heading}
                                                </th>
                                            ))}
                                            <th className="px-4 py-2 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                                                Sessions / Tokens
                                            </th>
                                            <th className="px-4 py-2 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                        {users.map((user) => {
                                            const eligible = user.is_active && !user.is_protected && !user.identity_purged_at;

                                            return (
                                                <tr key={user.id}>
                                                    <td className="px-3 py-2.5">
                                                        <input
                                                            type="checkbox"
                                                            aria-label={`Select ${user.name}`}
                                                            checked={selected.includes(user.id)}
                                                            onChange={() => toggle(user.id)}
                                                            disabled={!eligible}
                                                            className="rounded border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-info focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark disabled:opacity-40"
                                                        />
                                                    </td>
                                                    <td className="px-4 py-2.5 whitespace-nowrap text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        <span className="inline-flex items-center gap-2">
                                                            {user.name}
                                                            {user.is_protected && (
                                                                <span className="rounded bg-healthcare-warning/10 px-1.5 py-0.5 text-xs font-semibold uppercase text-healthcare-warning">Protected</span>
                                                            )}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-2.5 whitespace-nowrap text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {user.username}
                                                    </td>
                                                    <td className="px-4 py-2.5 whitespace-nowrap text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                        {user.email ?? '—'}
                                                    </td>
                                                    <td className="px-4 py-2.5 whitespace-nowrap">
                                                        <span className="inline-flex items-center rounded-md bg-healthcare-surface-secondary dark:bg-healthcare-surface-hover-dark px-2 py-0.5 text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark capitalize">
                                                            {user.role}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-2.5 whitespace-nowrap">
                                                        {user.is_active ? (
                                                            <span className="inline-flex items-center gap-1 rounded-md bg-healthcare-success/10 dark:bg-healthcare-success-dark/10 px-2 py-0.5 text-xs font-medium text-healthcare-success dark:text-healthcare-success-dark">
                                                                <Icon icon="heroicons:check-circle" className="w-3.5 h-3.5" />
                                                                Active
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center gap-1 rounded-md bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/10 px-2 py-0.5 text-xs font-medium text-healthcare-critical dark:text-healthcare-critical-dark">
                                                                <Icon icon="heroicons:x-circle" className="w-3.5 h-3.5" />
                                                                Inactive
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-2.5 whitespace-nowrap">
                                                        <LifecycleCell lifecycle={user.lifecycle} />
                                                    </td>
                                                    <td className="px-4 py-2.5 whitespace-nowrap">
                                                        <AssuranceCell lifecycle={user.lifecycle} />
                                                    </td>
                                                    <td className="px-4 py-2.5 whitespace-nowrap text-right text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark tabular-nums">
                                                        {user.lifecycle
                                                            ? `${user.lifecycle.active_session_count} / ${user.lifecycle.active_token_count}`
                                                            : '—'}
                                                    </td>
                                                    <td className="px-4 py-2.5 whitespace-nowrap text-right text-sm font-medium">
                                                        <div className="flex justify-end space-x-2">
                                                            <Link
                                                                href={`/users/${user.id}/edit`}
                                                                aria-label={`Edit ${user.name}`}
                                                                className="inline-flex items-center gap-1 rounded-md border border-healthcare-border px-2.5 py-1.5 text-healthcare-info transition-colors duration-300 hover:border-healthcare-info hover:text-healthcare-info-dark dark:border-healthcare-border-dark dark:text-healthcare-info-dark dark:hover:border-healthcare-info-dark dark:hover:text-healthcare-info"
                                                            >
                                                                <Icon icon="heroicons:pencil-square" className="h-4 w-4" aria-hidden="true" />
                                                                <span>Edit</span>
                                                            </Link>
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </Card.Content>
                    </Card>
                </div>
            </div>
        </DashboardLayout>
    );
}
