import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Card from '@/Components/Dashboard/Card';

export default function Index({ auth, users }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="text-xl font-semibold">User Management</h2>}
        >
            <Head title="User Management" />

            <div className="p-4">
                <div>
                    <div className="flex justify-between items-center mb-4">
                        <h1 className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            Users
                        </h1>
                        <Link
                            href={route('users.create')}
                            className="inline-flex items-center px-4 py-2 bg-healthcare-info dark:bg-healthcare-info-dark text-white rounded-md hover:bg-healthcare-info-dark dark:hover:bg-healthcare-info transition-colors duration-300"
                        >
                            <Icon icon="heroicons:plus" className="w-5 h-5 mr-2" />
                            Add User
                        </Link>
                    </div>

                    <Card>
                        <Card.Content>
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                    <thead>
                                        <tr>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                                                Name
                                            </th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                                                Username
                                            </th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                                                Email
                                            </th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                                                Role
                                            </th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                                                Status
                                            </th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                                                Created At
                                            </th>
                                            <th className="px-4 py-2 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                        {users.map((user) => (
                                            <tr key={user.id}>
                                                <td className="px-4 py-2.5 whitespace-nowrap text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {user.name}
                                                </td>
                                                <td className="px-4 py-2.5 whitespace-nowrap text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {user.username}
                                                </td>
                                                <td className="px-4 py-2.5 whitespace-nowrap text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {user.email}
                                                </td>
                                                <td className="px-4 py-2.5 whitespace-nowrap">
                                                    <span className="inline-flex items-center rounded-md bg-healthcare-surface-secondary dark:bg-healthcare-surface-secondary-dark px-2 py-0.5 text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark capitalize">
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
                                                <td className="px-4 py-2.5 whitespace-nowrap text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {new Date(user.created_at).toLocaleDateString()}
                                                </td>
                                                <td className="px-4 py-2.5 whitespace-nowrap text-right text-sm font-medium">
                                                    <div className="flex justify-end space-x-2">
                                                        <Link
                                                            href={route('users.edit', user.id)}
                                                            className="text-healthcare-info dark:text-healthcare-info-dark hover:text-healthcare-info-dark dark:hover:text-healthcare-info transition-colors duration-300"
                                                        >
                                                            <Icon icon="heroicons:pencil-square" className="w-5 h-5" />
                                                        </Link>
                                                        <Link
                                                            href={route('users.destroy', user.id)}
                                                            method="delete"
                                                            as="button"
                                                            className="text-healthcare-critical dark:text-healthcare-critical-dark hover:text-healthcare-critical-dark dark:hover:text-healthcare-critical transition-colors duration-300"
                                                            onClick={(e) => {
                                                                if (!confirm('Are you sure you want to delete this user?')) {
                                                                    e.preventDefault();
                                                                }
                                                            }}
                                                        >
                                                            <Icon icon="heroicons:trash" className="w-5 h-5" />
                                                        </Link>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </Card.Content>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
