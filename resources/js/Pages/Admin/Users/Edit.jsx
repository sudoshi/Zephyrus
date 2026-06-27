import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Card from '@/Components/Dashboard/Card';
import InputError from '@/Components/InputError';

export default function Edit({ auth, user }) {
    const { data, setData, put, processing, errors } = useForm({
        name: user.name || '',
        email: user.email || '',
        username: user.username || '',
        password: '',
        password_confirmation: '',
        role: user.role || 'user',
        is_active: user.is_active ?? true,
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('users.update', user.id));
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="text-xl font-semibold">Edit User</h2>}
        >
            <Head title="Edit User" />

            <div className="p-4">
                <div>
                    <div className="flex justify-between items-center mb-6">
                        <h1 className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            Edit User: {user.name}
                        </h1>
                        <Link
                            href={route('users.index')}
                            className="inline-flex items-center px-4 py-2 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md text-healthcare-text-primary dark:text-healthcare-text-primary-dark hover:bg-healthcare-surface-secondary dark:hover:bg-healthcare-surface-secondary-dark transition-colors duration-300"
                        >
                            Back to Users
                        </Link>
                    </div>

                    <Card>
                        <Card.Content>
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
                                        Password <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">(Leave blank to keep current password)</span>
                                    </label>
                                    <input
                                        id="password"
                                        type="password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
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
                                        className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
                                    >
                                        <option value="user">User</option>
                                        <option value="admin">Admin</option>
                                        <option value="superuser">Superuser</option>
                                    </select>
                                    <InputError message={errors.role} className="mt-2" />
                                </div>

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
                                        Update User
                                    </button>
                                </div>
                            </form>
                        </Card.Content>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
