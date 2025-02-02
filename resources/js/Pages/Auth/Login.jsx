import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import GuestLayout from '@/Layouts/GuestLayout';
import DarkModeToggle from '@/Components/Common/DarkModeToggle';
import DataModeToggle from '@/Components/Common/DataModeToggle';
import Card from '@/Components/Dashboard/Card';
import { useDarkMode } from '@/hooks/useDarkMode';

export default function Login({ status, canResetPassword }) {
    const [isDarkMode, setIsDarkMode] = useDarkMode();
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
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

            <Card>
                <Card.Content>
                    <div className="text-center mb-6">
                        <h2 className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                            ZephyrusOR
                        </h2>
                    </div>
                    <DataModeToggle />
                    <div className="text-center">
                        <div className="flex items-center justify-center space-x-2 mb-4">
                            <Icon 
                                icon="heroicons:information-circle" 
                                className="w-5 h-5 text-healthcare-info dark:text-healthcare-info-dark transition-colors duration-300"
                            />
                            <h3 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                Demo Credentials
                            </h3>
                        </div>
                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark space-y-1 transition-colors duration-300">
                            <p>Email: acumenus@example.com</p>
                            <p>Password: acumenus</p>
                        </div>
                    </div>

                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <label htmlFor="email" className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                Email
                            </label>
                            <input
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                className="mt-1 block w-full rounded-md border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark focus:border-healthcare-info dark:focus:border-healthcare-info-dark focus:ring-healthcare-info dark:focus:ring-healthcare-info-dark transition-colors duration-300"
                                autoComplete="username"
                                onChange={(e) => setData('email', e.target.value)}
                            />
                            {errors.email && (
                                <p className="mt-1 text-sm text-healthcare-critical dark:text-healthcare-critical-dark transition-colors duration-300">
                                    {errors.email}
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
                                        href={route('password.request')}
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
                    </form>
                </Card.Content>
            </Card>
        </GuestLayout>
    );
}
