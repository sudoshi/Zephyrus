import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import Card from '@/Components/Dashboard/Card';

export default function Index({ auth, users }) {
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
                                                    <span className="inline-flex items-center gap-2">
                                                        {user.name}
                                                        {user.is_protected && (
                                                            <span className="rounded bg-healthcare-warning/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-healthcare-warning">Protected</span>
                                                        )}
                                                    </span>
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
                                        ))}
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
