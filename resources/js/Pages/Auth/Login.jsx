import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import React, { useEffect, useState } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Input, Button, Checkbox, Card, CardBody, Divider } from '@heroui/react';
import { motion, AnimatePresence } from 'framer-motion';

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
                initial={{ opacity: 0, y: 30, scale: 0.95 }}
                animate={{ opacity: 1, y: 0, scale: 1 }}
                transition={{ 
                    duration: 0.6,
                    type: "spring",
                    stiffness: 100,
                    damping: 15
                }}
                className="w-full max-w-md mx-auto"
            >
                {/* Elegant Header */}
                <motion.div 
                    initial={{ opacity: 0, y: -20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.2, duration: 0.5 }}
                    className="text-center mb-10"
                >
                    <motion.div
                        initial={{ scale: 0.8, opacity: 0 }}
                        animate={{ scale: 1, opacity: 1 }}
                        transition={{ delay: 0.1, duration: 0.5 }}
                        className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gradient-to-br from-primary-500 to-primary-700 shadow-lg mb-6"
                    >
                        <Icon icon="lucide:heart-pulse" className="w-8 h-8 text-white" />
                    </motion.div>
                    <h1 className="text-4xl font-light text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-3">
                        Welcome Back
                    </h1>
                    <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark font-light">
                        Access your healthcare operations platform
                    </p>
                </motion.div>

                {/* Elegant Status Messages */}
                <AnimatePresence mode="wait">
                    {status && (
                        <motion.div
                            initial={{ opacity: 0, y: -10, scale: 0.95 }}
                            animate={{ opacity: 1, y: 0, scale: 1 }}
                            exit={{ opacity: 0, y: -10, scale: 0.95 }}
                            transition={{ type: "spring", stiffness: 300, damping: 25 }}
                            className="mb-6"
                        >
                            <div className="p-4 rounded-2xl bg-gradient-to-r from-success-50 to-success-100 dark:from-success-900/20 dark:to-success-800/20 border border-success-200/50 dark:border-success-700/50 backdrop-blur-sm">
                                <div className="flex items-center gap-3">
                                    <div className="flex-shrink-0">
                                        <Icon icon="lucide:check-circle" className="w-5 h-5 text-success-600 dark:text-success-400" />
                                    </div>
                                    <p className="text-sm font-medium text-success-700 dark:text-success-300">{status}</p>
                                </div>
                            </div>
                        </motion.div>
                    )}
                </AnimatePresence>

                <AnimatePresence mode="wait">
                    {(errors.username || errors.password || errors.email) && (
                        <motion.div
                            initial={{ opacity: 0, y: -10, scale: 0.95 }}
                            animate={{ opacity: 1, y: 0, scale: 1 }}
                            exit={{ opacity: 0, y: -10, scale: 0.95 }}
                            transition={{ type: "spring", stiffness: 300, damping: 25 }}
                            className="mb-6"
                        >
                            <div className="p-4 rounded-2xl bg-gradient-to-r from-danger-50 to-danger-100 dark:from-danger-900/20 dark:to-danger-800/20 border border-danger-200/50 dark:border-danger-700/50 backdrop-blur-sm">
                                <div className="flex items-start gap-3">
                                    <div className="flex-shrink-0 mt-0.5">
                                        <Icon icon="lucide:alert-circle" className="w-5 h-5 text-danger-600 dark:text-danger-400" />
                                    </div>
                                    <div className="flex-1 space-y-1">
                                        {errors.username && <p className="text-sm font-medium text-danger-700 dark:text-danger-300">{errors.username}</p>}
                                        {errors.password && <p className="text-sm font-medium text-danger-700 dark:text-danger-300">{errors.password}</p>}
                                        {errors.email && <p className="text-sm font-medium text-danger-700 dark:text-danger-300">{errors.email}</p>}
                                    </div>
                                </div>
                            </div>
                        </motion.div>
                    )}
                </AnimatePresence>

                {/* Elegant Login Form */}
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.3, duration: 0.5 }}
                >
                    <Card 
                        shadow="lg" 
                        className="border-0 bg-white/80 dark:bg-healthcare-surface-dark/80 backdrop-blur-xl shadow-2xl shadow-primary-500/10 dark:shadow-primary-400/5"
                    >
                        <CardBody className="p-8">
                            <form onSubmit={submit} className="space-y-6">
                                {/* Elegant Username Input */}
                                <motion.div
                                    initial={{ opacity: 0, x: -20 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    transition={{ delay: 0.4, duration: 0.4 }}
                                >
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
                                            input: "text-base font-medium",
                                            inputWrapper: "border-2 border-default-200 dark:border-default-700 hover:border-primary-400 focus-within:!border-primary-500 transition-colors duration-200 bg-default-50/50 dark:bg-default-100/10",
                                            label: "font-medium text-default-600 dark:text-default-400"
                                        }}
                                        autoComplete="username"
                                        autoFocus
                                    />
                                </motion.div>

                                {/* Elegant Password Input */}
                                <motion.div
                                    initial={{ opacity: 0, x: -20 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    transition={{ delay: 0.5, duration: 0.4 }}
                                >
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
                                            <motion.button
                                                type="button"
                                                onClick={() => setShowPassword(!showPassword)}
                                                className="focus:outline-none p-1 rounded-lg hover:bg-default-100 dark:hover:bg-default-800 transition-colors"
                                                tabIndex={-1}
                                                whileHover={{ scale: 1.05 }}
                                                whileTap={{ scale: 0.95 }}
                                            >
                                                <Icon
                                                    icon={showPassword ? "lucide:eye-off" : "lucide:eye"}
                                                    className="w-5 h-5 text-default-400 hover:text-default-600 transition-colors"
                                                />
                                            </motion.button>
                                        }
                                        classNames={{
                                            input: "text-base font-medium",
                                            inputWrapper: "border-2 border-default-200 dark:border-default-700 hover:border-primary-400 focus-within:!border-primary-500 transition-colors duration-200 bg-default-50/50 dark:bg-default-100/10",
                                            label: "font-medium text-default-600 dark:text-default-400"
                                        }}
                                        autoComplete="current-password"
                                    />
                                </motion.div>

                                {/* Remember Me & Forgot Password */}
                                <motion.div
                                    initial={{ opacity: 0, y: 10 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ delay: 0.6, duration: 0.4 }}
                                    className="flex items-center justify-between"
                                >
                                    <Checkbox
                                        isSelected={data.remember}
                                        onValueChange={(checked) => setData('remember', checked)}
                                        size="sm"
                                        classNames={{
                                            label: "text-sm font-medium text-default-600 dark:text-default-400"
                                        }}
                                    >
                                        Remember me
                                    </Checkbox>

                                    {canResetPassword && (
                                        <Link
                                            href="/forgot-password"
                                            className="text-sm font-medium text-primary hover:text-primary-600 transition-colors duration-200"
                                        >
                                            Forgot password?
                                        </Link>
                                    )}
                                </motion.div>

                                {/* Elegant Submit Button */}
                                <motion.div
                                    initial={{ opacity: 0, y: 20 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ delay: 0.7, duration: 0.4 }}
                                >
                                    <Button
                                        type="submit"
                                        size="lg"
                                        isLoading={processing}
                                        className="w-full h-14 bg-gradient-to-r from-primary-500 to-primary-600 hover:from-primary-600 hover:to-primary-700 font-semibold text-white shadow-lg shadow-primary-500/25 hover:shadow-primary-500/40 transition-all duration-200"
                                        startContent={
                                            !processing && <Icon icon="lucide:log-in" className="w-5 h-5" />
                                        }
                                        radius="lg"
                                    >
                                        {processing ? "Signing In..." : "Sign In"}
                                    </Button>
                                </motion.div>
                            </form>

                            {/* Elegant Divider */}
                            <motion.div
                                initial={{ opacity: 0, scaleX: 0 }}
                                animate={{ opacity: 1, scaleX: 1 }}
                                transition={{ delay: 0.8, duration: 0.4 }}
                                className="py-4"
                            >
                                <Divider className="bg-gradient-to-r from-transparent via-default-300 dark:via-default-600 to-transparent" />
                            </motion.div>

                            {/* Elegant Dark Mode Toggle */}
                            <motion.div
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ delay: 0.9, duration: 0.4 }}
                                className="flex items-center justify-center"
                            >
                                <motion.button
                                    type="button"
                                    onClick={toggleDarkMode}
                                    className="flex items-center gap-3 px-4 py-2 rounded-xl hover:bg-default-100 dark:hover:bg-default-800 transition-colors duration-200 group"
                                    whileHover={{ scale: 1.02 }}
                                    whileTap={{ scale: 0.98 }}
                                >
                                    <motion.div
                                        animate={{ rotate: isDarkMode ? 180 : 0 }}
                                        transition={{ duration: 0.3 }}
                                    >
                                        <Icon
                                            icon={isDarkMode ? "lucide:sun" : "lucide:moon"}
                                            className="w-5 h-5 text-default-500 group-hover:text-primary transition-colors duration-200"
                                        />
                                    </motion.div>
                                    <span className="text-sm font-medium text-default-600 dark:text-default-400 group-hover:text-primary transition-colors duration-200">
                                        {isDarkMode ? "Light Mode" : "Dark Mode"}
                                    </span>
                                </motion.button>
                            </motion.div>
                        </CardBody>
                    </Card>
                </motion.div>

                {/* Elegant Demo Credentials */}
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 1.0, duration: 0.5 }}
                    className="mt-8"
                >
                    <Card className="bg-gradient-to-br from-info-50/80 to-info-100/80 dark:from-info-900/20 dark:to-info-800/20 border border-info-200/50 dark:border-info-700/50 backdrop-blur-sm">
                        <CardBody className="p-6">
                            <div className="flex items-start gap-4">
                                <div className="flex-shrink-0">
                                    <div className="w-10 h-10 rounded-full bg-gradient-to-br from-info-500 to-info-600 flex items-center justify-center">
                                        <Icon icon="lucide:key" className="w-5 h-5 text-white" />
                                    </div>
                                </div>
                                <div className="flex-1">
                                    <h3 className="text-sm font-semibold text-info-700 dark:text-info-300 mb-2">
                                        Demo Access
                                    </h3>
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-2 text-xs">
                                            <span className="text-info-600 dark:text-info-400 font-medium">Username:</span>
                                            <code className="px-2 py-1 bg-info-100 dark:bg-info-800 text-info-700 dark:text-info-300 rounded font-mono font-semibold">admin</code>
                                        </div>
                                        <div className="flex items-center gap-2 text-xs">
                                            <span className="text-info-600 dark:text-info-400 font-medium">Password:</span>
                                            <code className="px-2 py-1 bg-info-100 dark:bg-info-800 text-info-700 dark:text-info-300 rounded font-mono font-semibold">password</code>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </CardBody>
                    </Card>
                </motion.div>
            </motion.div>
        </GuestLayout>
    );
}
