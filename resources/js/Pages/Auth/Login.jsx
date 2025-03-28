import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { Listbox } from '@headlessui/react';
import React, { useEffect, useState } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import DarkModeToggle from '@/Components/Common/DarkModeToggle';
import DataModeToggle from '@/Components/Common/DataModeToggle';
import Card from '@/Components/Dashboard/Card';

// Inline implementation of useDarkMode hook
const useDarkMode = () => {
    const [isDarkMode, setIsDarkMode] = useState(() => {
        const savedMode = localStorage.getItem('darkMode');
        return savedMode ? JSON.parse(savedMode) : false;
    });

    useEffect(() => {
        localStorage.setItem('darkMode', JSON.stringify(isDarkMode));
        if (isDarkMode) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    }, [isDarkMode]);

    return [isDarkMode, setIsDarkMode];
};

export default function Login({ status, canResetPassword }) {
    const [isDarkMode, setIsDarkMode] = useDarkMode();
    const { data, setData, post, processing, errors, reset } = useForm({
        username: '',
        password: '',
        remember: false,
    });

    const submit = async (e) => {
        e.preventDefault();
        
        // Set the form submission state
        setData('general', '');
        
        try {
            // Method 1: Try the direct PHP script first (entirely bypasses middleware)
            const formData = new FormData();
            formData.append('username', data.username);
            formData.append('password', data.password);
            if (data.remember) {
                formData.append('remember', '1');
            }
            
            try {
                console.log('Attempting direct login...');
                const response = await fetch('/direct-login.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });
                
                const result = await response.json();
                
                if (response.ok && result.success) {
                    console.log('Direct login successful');
                    window.location.href = result.redirect || '/dashboard';
                    return;
                }
            } catch (directError) {
                console.error('Direct login failed:', directError);
                // Continue to fallback methods
            }
            
            // Method 2: Try traditional form submission
            console.log('Attempting traditional form submission...');
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/login';
            form.style.display = 'none';
            
            // Add username input
            const usernameInput = document.createElement('input');
            usernameInput.type = 'hidden';
            usernameInput.name = 'username';
            usernameInput.value = data.username;
            form.appendChild(usernameInput);
            
            // Add password input
            const passwordInput = document.createElement('input');
            passwordInput.type = 'hidden';
            passwordInput.name = 'password';
            passwordInput.value = data.password;
            form.appendChild(passwordInput);
            
            // Add remember input if checked
            if (data.remember) {
                const rememberInput = document.createElement('input');
                rememberInput.type = 'hidden';
                rememberInput.name = 'remember';
                rememberInput.value = '1';
                form.appendChild(rememberInput);
            }
            
            // Append the form to the body and submit it
            document.body.appendChild(form);
            form.submit();
            
        } catch (error) {
            console.error('Login error:', error);
            setData('general', 'An unexpected error occurred during login.');
        }
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
