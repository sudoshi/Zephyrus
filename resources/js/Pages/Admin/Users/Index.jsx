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

            <div className="py-6">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="flex justify-between items-center mb-6">
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
                                            <th className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                                                Name
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                                                Username
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                                                Email
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                                                Created At
                                            </th>
                                            <th className="px-6 py-3 text-right text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase tracking-wider">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                        {users.map((user) => (
                                            <tr key={user.id}>
                                                <td className="px-6 py-4 whitespace-nowrap text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {user.name}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {user.username}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {user.email}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {new Date(user.created_at).toLocaleDateString()}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
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
