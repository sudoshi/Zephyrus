import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { Listbox } from '@headlessui/react';
import React, { useEffect } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import DarkModeToggle from '@/Components/Common/DarkModeToggle';
import DataModeToggle from '@/Components/Common/DataModeToggle';
import Card from '@/Components/Dashboard/Card';
import { useDarkMode } from '@/hooks/useDarkMode';
export default function Login({ status, canResetPassword }) {
    const [isDarkMode, setIsDarkMode] = useDarkMode();
    const { data, setData, post, processing, errors, reset } = useForm({
        workflow: 'rtdc',
        username: '',
        password: '',
        remember: false,
    });

    const workflowOptions = [
        { value: 'rtdc', label: 'RTDC' },
        { value: 'or', label: 'OR' },
        { value: 'ed', label: 'ED' },
    ];

const submit = (e) => {
    e.preventDefault();
    post('/login', {
        preserveState: false,
        preserveScroll: false,
        onFinish: () => reset('password'),
        onError: (errors) => {
            if (errors.general) {
                setData('general', errors.general);
            }
        },
    });
};

    return (
        <GuestLayout>
            <Head title="Log in" />

            {status && (
                <div className="mb-4 text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
            {errors.general && (
                <div className="mb-4 text-sm font-medium text-healthcare-critical dark:text-healthcare-critical-dark">
                    {errors.general}
                </div>
            )}

            <Card>
                <Card.Content>
                    <DataModeToggle />
                    <form onSubmit={submit} className="mt-4 space-y-4">
                        <div>
                            <label htmlFor="workflow" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                Workflow
                            </label>
                            <Listbox value={data.workflow} onChange={(value) => setData('workflow', value)}>
                                <div className="relative mt-1">
                                    <Listbox.Button className="relative w-full rounded-md border border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark px-4 py-2 text-left">
                                        <span className="block truncate">
                                            {workflowOptions.find(option => option.value === data.workflow)?.label}
                                        </span>
                                        <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                            <Icon
                                                icon="heroicons:chevron-down"
                                                className="h-5 w-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                                aria-hidden="true"
                                            />
                                        </span>
                                    </Listbox.Button>
                                    <Listbox.Options className="absolute z-10 w-full mt-1 overflow-auto rounded-md bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark shadow-lg">
                                        {workflowOptions.map((option) => (
                                            <Listbox.Option
                                                key={option.value}
                                                value={option.value}
                                                className={({ active }) =>
                                                    `relative cursor-pointer select-none py-2 px-4 ${
                                                        active ? 'bg-healthcare-info dark:bg-healthcare-info-dark text-white' : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                                                    }`
                                                }
                                            >
                                                {option.label}
                                            </Listbox.Option>
                                        ))}
                                    </Listbox.Options>
                                </div>
                            </Listbox>
                        </div>

                        <div>
                            <label htmlFor="username" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                Username
                            </label>
                            <input
                                id="username"
                                type="text"
                                name="username"
                                value={data.username}
                                className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
                                autoComplete="username"
                                onChange={(e) => setData('username', e.target.value)}
                            />
                            {errors.username && (
                                <p className="mt-1 text-sm text-healthcare-critical dark:text-healthcare-critical-dark transition-colors duration-300">
                                    {errors.username}
                                </p>
                            )}
                        </div>

                        <div>
                            <label htmlFor="password" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                Password
                            </label>
                            <input
                                id="password"
                                type="password"
                                name="password"
                                value={data.password}
                                className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
                                autoComplete="current-password"
                                onChange={(e) => setData('password', e.target.value)}
                            />
                            {errors.password && (
                                <p className="mt-1 text-sm text-healthcare-critical dark:text-healthcare-critical-dark transition-colors duration-300">
                                    {errors.password}
                                </p>
                            )}
                        </div>

                        <div className="flex items-center">
                            <label className="inline-flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="sr-only peer"
                                    name="remember"
                                    checked={data.remember}
                                    onChange={(e) => setData('remember', e.target.checked)}
                                />
                                <div className="relative w-8 h-4 bg-healthcare-surface dark:bg-healthcare-surface-dark peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-healthcare-info dark:after:bg-healthcare-info-dark after:rounded-full after:h-3 after:w-3 after:transition-all border-healthcare-border dark:border-healthcare-border-dark peer-checked:bg-healthcare-surface dark:peer-checked:bg-healthcare-surface-dark"></div>
                                <span className="ms-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
                                    Remember me
                                </span>
                            </label>
                        </div>

                        <div className="flex items-center justify-between space-x-4">
                            <DarkModeToggle isDarkMode={isDarkMode} onToggle={() => setIsDarkMode(!isDarkMode)} />
                            <div className="flex items-center space-x-4">
                                {canResetPassword && (
                                    <Link
                                        href="/forgot-password"
                                        className="text-sm text-healthcare-info dark:text-healthcare-info-dark hover:text-healthcare-info-dark dark:hover:text-healthcare-info transition-colors duration-300"
                                    >
                                        Forgot your password?
                                    </Link>
                                )}

                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex items-center px-4 py-2 border border-transparent rounded-md font-semibold text-xs uppercase tracking-widest bg-healthcare-info dark:bg-healthcare-info-dark text-white hover:bg-healthcare-info-dark dark:hover:bg-healthcare-info disabled:opacity-50 transition-all duration-300"
                                >
                                    Log in
                                </button>
                            </div>
                        </div>

                        {errors.general && (
                            <div className="mt-4 text-sm text-healthcare-critical dark:text-healthcare-critical-dark">
                                {errors.general}
                            </div>
                        )}

                    </form>
                </Card.Content>
            </Card>
        </GuestLayout>
    );
}
