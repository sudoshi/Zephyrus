import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import Card from '@/Components/Dashboard/Card';
import InputError from '@/Components/InputError';

export default function Create({ auth, sso_only: ssoOnly }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        username: '',
        password: '',
        password_confirmation: '',
        role: 'user',
        is_active: true,
        change_reason: 'new_account',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/users', {
            onSuccess: () => reset(),
        });
    };

    return (
        <DashboardLayout>
            <Head title="Create User" />

            <div className="p-4">
                <div>
                    <div className="flex justify-between items-center mb-6">
                        <h1 className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            Create New User
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
                            {ssoOnly && (
                                <div className="mb-6 rounded-md border border-healthcare-warning/40 bg-healthcare-warning/10 p-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    SSO-only policy is active: local account creation with a password is disabled. Accounts are provisioned just-in-time by the identity provider; this form will be rejected on submit.
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
                                        className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
                                        required
                                    />
                                    <InputError message={errors.name} className="mt-2" />
                                </div>

                                <div>
                                    <label htmlFor="change_reason" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        Reason for creation
                                    </label>
                                    <select
                                        id="change_reason"
                                        value={data.change_reason}
                                        onChange={(e) => setData('change_reason', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark"
                                        required
                                    >
                                        <option value="new_account">New workforce account</option>
                                        {auth?.can?.manage_privileges && <option value="approved_privileged_account">Approved privileged account</option>}
                                    </select>
                                    <InputError message={errors.change_reason} className="mt-2" />
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
                                        className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
                                        required
                                    />
                                    <InputError message={errors.username} className="mt-2" />
                                </div>

                                <div>
                                    <label htmlFor="password" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        Password
                                    </label>
                                    <input
                                        id="password"
                                        type="password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
                                        required
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
                                        className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
                                        required
                                    />
                                    <InputError message={errors.password_confirmation} className="mt-2" />
                                </div>

                                {auth?.can?.manage_privileges && <div>
                                    <label htmlFor="role" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        Role
                                    </label>
                                    <select
                                        id="role"
                                        value={data.role}
                                        onChange={(e) => setData('role', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
                                    >
                                        <option value="user">User</option>
                                        <option value="admin">Admin</option>
                                        <option value="superuser">Superuser</option>
                                    </select>
                                    <InputError message={errors.role} className="mt-2" />
                                </div>}

                                <div>
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={data.is_active}
                                            onChange={(e) => setData('is_active', e.target.checked)}
                                            className="rounded border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-info focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
                                        />
                                        <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            Active account
                                        </span>
                                    </label>
                                    <InputError message={errors.is_active} className="mt-2" />
                                </div>

                                <div className="flex items-center justify-end">
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="inline-flex items-center px-4 py-2 bg-healthcare-info dark:bg-healthcare-info-dark text-white rounded-md hover:bg-healthcare-info-dark dark:hover:bg-healthcare-info transition-colors duration-300 disabled:opacity-50"
                                    >
                                        Create User
                                    </button>
                                </div>
                            </form>
                        </Card.Content>
                    </Card>
                </div>
            </div>
        </DashboardLayout>
    );
}
