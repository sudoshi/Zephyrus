import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import React, { useEffect, useState } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Input, Button, Checkbox, Card, CardBody } from '@heroui/react';
import { motion } from 'framer-motion';

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
    const [showPassword, setShowPassword] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        username: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post('/login', {
            onFinish: () => {
                // Reset password field on error
                if (Object.keys(errors).length > 0) {
                    setData('password', '');
                }
            },
        });
    };

    const toggleDarkMode = () => {
        setIsDarkMode(!isDarkMode);
    };

    return (
        <GuestLayout>
            <Head title="Sign In - Zephyrus" />

            <motion.div
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.5 }}
                className="w-full"
            >
                {/* Header */}
                <div className="text-center mb-8">
                    <h1 className="text-3xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
                        Welcome Back
                    </h1>
                    <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Sign in to access your healthcare operations dashboard
                    </p>
                </div>

                {/* Status Messages */}
                {status && (
                    <motion.div
                        initial={{ opacity: 0, x: -20 }}
                        animate={{ opacity: 1, x: 0 }}
                        className="mb-4 p-4 rounded-lg bg-success-50 dark:bg-success-900/20 border border-success-200 dark:border-success-800"
                    >
                        <div className="flex items-center gap-3">
                            <Icon icon="lucide:check-circle" className="w-5 h-5 text-success-600 dark:text-success-400" />
                            <p className="text-sm text-success-700 dark:text-success-300">{status}</p>
                        </div>
                    </motion.div>
                )}

                {/* Error Messages */}
                {(errors.username || errors.password || errors.email) && (
                    <motion.div
                        initial={{ opacity: 0, x: -20 }}
                        animate={{ opacity: 1, x: 0 }}
                        className="mb-4 p-4 rounded-lg bg-danger-50 dark:bg-danger-900/20 border border-danger-200 dark:border-danger-800"
                    >
                        <div className="flex items-center gap-3">
                            <Icon icon="lucide:alert-circle" className="w-5 h-5 text-danger-600 dark:text-danger-400" />
                            <div className="flex-1">
                                {errors.username && <p className="text-sm text-danger-700 dark:text-danger-300">{errors.username}</p>}
                                {errors.password && <p className="text-sm text-danger-700 dark:text-danger-300">{errors.password}</p>}
                                {errors.email && <p className="text-sm text-danger-700 dark:text-danger-300">{errors.email}</p>}
                            </div>
                        </div>
                    </motion.div>
                )}

                {/* Login Form */}
                <Card shadow="lg" className="border border-healthcare-border dark:border-healthcare-border-dark">
                    <CardBody className="gap-6 p-8">
                        <form onSubmit={submit} className="flex flex-col gap-5">
                            {/* Username Input */}
                            <Input
                                label="Username"
                                placeholder="Enter your username"
                                value={data.username}
                                onValueChange={(value) => setData('username', value)}
                                isRequired
                                variant="bordered"
                                size="lg"
                                startContent={
                                    <Icon icon="lucide:user" className="w-5 h-5 text-default-400" />
                                }
                                classNames={{
                                    input: "text-base",
                                    inputWrapper: "border-healthcare-border dark:border-healthcare-border-dark hover:border-primary",
                                }}
                                autoComplete="username"
                                autoFocus
                            />

                            {/* Password Input */}
                            <Input
                                label="Password"
                                placeholder="Enter your password"
                                value={data.password}
                                onValueChange={(value) => setData('password', value)}
                                isRequired
                                variant="bordered"
                                size="lg"
                                type={showPassword ? "text" : "password"}
                                startContent={
                                    <Icon icon="lucide:lock" className="w-5 h-5 text-default-400" />
                                }
                                endContent={
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(!showPassword)}
                                        className="focus:outline-none"
                                        tabIndex={-1}
                                    >
                                        <Icon
                                            icon={showPassword ? "lucide:eye-off" : "lucide:eye"}
                                            className="w-5 h-5 text-default-400 hover:text-default-600 transition-colors"
                                        />
                                    </button>
                                }
                                classNames={{
                                    input: "text-base",
                                    inputWrapper: "border-healthcare-border dark:border-healthcare-border-dark hover:border-primary",
                                }}
                                autoComplete="current-password"
                            />

                            {/* Remember Me & Forgot Password */}
                            <div className="flex items-center justify-between">
                                <Checkbox
                                    isSelected={data.remember}
                                    onValueChange={(checked) => setData('remember', checked)}
                                    size="sm"
                                    classNames={{
                                        label: "text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                    }}
                                >
                                    Remember me
                                </Checkbox>

                {canResetPassword && (
                                    <Link
                                        href="/forgot-password"
                                        className="text-sm text-primary hover:underline transition-colors"
                                    >
                                        Forgot password?
                                    </Link>
                                )}
                            </div>

                            {/* Submit Button */}
                            <Button
                                type="submit"
                                color="primary"
                                size="lg"
                                isLoading={processing}
                                className="w-full font-semibold"
                                startContent={
                                    !processing && <Icon icon="lucide:log-in" className="w-5 h-5" />
                                }
                            >
                                {processing ? "Signing In..." : "Sign In"}
                            </Button>
                        </form>

                        {/* Dark Mode Toggle */}
                        <div className="flex items-center justify-center pt-4 border-t border-healthcare-border dark:border-healthcare-border-dark">
                            <button
                                type="button"
                                onClick={toggleDarkMode}
                                className="flex items-center gap-2 px-4 py-2 rounded-lg hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark transition-colors"
                            >
                                <Icon
                                    icon={isDarkMode ? "lucide:sun" : "lucide:moon"}
                                    className="w-5 h-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                                />
                                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {isDarkMode ? "Light Mode" : "Dark Mode"}
                                </span>
                            </button>
                        </div>
                    </CardBody>
                </Card>

                {/* Demo Credentials */}
                <motion.div
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ delay: 0.3 }}
                    className="mt-6 p-4 rounded-lg bg-info-50 dark:bg-info-900/20 border border-info-200 dark:border-info-800"
                >
                    <div className="flex items-start gap-3">
                        <Icon icon="lucide:info" className="w-5 h-5 text-info-600 dark:text-info-400 flex-shrink-0 mt-0.5" />
                        <div className="flex-1">
                            <p className="text-sm font-medium text-info-700 dark:text-info-300 mb-1">
                                Demo Access
                            </p>
                            <p className="text-xs text-info-600 dark:text-info-400">
                                Username: <span className="font-mono font-semibold">admin</span> | Password: <span className="font-mono font-semibold">password</span>
                            </p>
                        </div>
                    </div>
                </motion.div>
            </motion.div>
        </GuestLayout>
    );
}
