import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import Card from '@/Components/Dashboard/Card';
import InputError from '@/Components/InputError';

function ExternalIdentityControl({ userId, identity, disabled }) {
    const { data, setData, post, processing, errors, reset } = useForm({ reason: '' });
    const operation = identity.is_active ? 'unlink' : 'relink';

    const submit = (event) => {
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
                    <InputError message={errors.reason || errors.identity} className="mt-1" />
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

function PurgeRequestForm({ userId, disabled }) {
    const { data, setData, post, processing, errors, reset } = useForm({ reason: '' });

    const submit = (event) => {
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
            <InputError message={errors.reason || errors.user || errors.governance} />
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

function PurgeRequestRow({ userId, request, actorId, canApprove, canExecute }) {
    const decisionForm = useForm({ decision: 'approved', reason: '' });
    const executionForm = useForm({});
    const canDecide = request.status === 'pending' && canApprove && Number(request.author_user_id) !== Number(actorId);
    const canRun = request.status === 'approved' && canExecute;

    const decide = (event) => {
        event.preventDefault();
        decisionForm.post(`/admin/identity-purge-requests/${request.uuid}/decision`, { preserveScroll: true });
    };

    const execute = (event) => {
        event.preventDefault();
        executionForm.post(`/users/${userId}/identity-purge-requests/${request.uuid}/execute`);
    };

    return (
        <li className="rounded-md border border-healthcare-border p-4 dark:border-healthcare-border-dark">
            <div className="flex flex-wrap justify-between gap-2">
                <div>
                    <p className="font-mono text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{request.uuid}</p>
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
                    <InputError message={decisionForm.errors.reason || decisionForm.errors.governance} className="sm:col-span-3" />
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
                    <InputError message={executionForm.errors.user || executionForm.errors.governance} className="mt-1" />
                </form>
            )}
        </li>
    );
}

export default function Edit({ auth, user }) {
    const canManagePrivileges = Boolean(auth?.can?.manage_privileges);
    const canManageIdentity = Boolean(auth?.can?.manage_identity);
    const identityLocked = Boolean(user.is_protected || user.identity_purged_at);
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

    const submit = (e) => {
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
                                        className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
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
                                        className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
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
                                        className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
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
                                        disabled={identityLocked}
                                        className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
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
                                        disabled={identityLocked}
                                        className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
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
                                        className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
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
                                        className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark"
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
                            {user.purge_requests?.length > 0 && (
                                <ul className="mt-4 space-y-3">
                                    {user.purge_requests.map((request) => (
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
