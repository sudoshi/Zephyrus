import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import React, { useEffect, useState } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Button, Checkbox } from '@heroui/react';
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

const fadeUp = (delay = 0) => ({
    initial: { opacity: 0, y: 16 },
    animate: { opacity: 1, y: 0 },
    transition: { duration: 0.45, ease: [0.25, 0.46, 0.45, 0.94], delay },
});

export default function Login({ status, canResetPassword, oidcEnabled = false, oidcLabel = 'Sign in with Authentik' }) {
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
                if (Object.keys(errors).length > 0) {
                    setData('password', '');
                }
            },
        });
    };

    const toggleDarkMode = () => setIsDarkMode(!isDarkMode);

    return (
        <GuestLayout>
            <Head title="Sign In — Zephyrus" />

            <motion.div
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                transition={{ duration: 0.5 }}
                className="w-full max-w-[420px] mx-auto"
            >
                {/* Header */}
                <motion.div {...fadeUp(0.1)} className="text-center mb-8">
                    <h1 className="text-3xl font-extralight tracking-tight text-slate-800 dark:text-slate-100">
                        Welcome back
                    </h1>
                    <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        Sign in to continue to your dashboard
                    </p>
                </motion.div>

                {/* Status messages */}
                <AnimatePresence mode="wait">
                    {status && (
                        <motion.div
                            initial={{ opacity: 0, y: -8 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: -8 }}
                            className="mb-5 flex items-center gap-2.5 rounded-xl bg-emerald-50/80 dark:bg-emerald-900/20 border border-emerald-200/60 dark:border-emerald-800/40 px-4 py-3"
                        >
                            <Icon icon="lucide:check-circle-2" className="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0" />
                            <p className="text-sm text-emerald-700 dark:text-emerald-300">{status}</p>
                        </motion.div>
                    )}
                </AnimatePresence>

                <AnimatePresence mode="wait">
                    {(errors.username || errors.password || errors.email) && (
                        <motion.div
                            initial={{ opacity: 0, y: -8 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: -8 }}
                            className="mb-5 flex items-start gap-2.5 rounded-xl bg-red-50/80 dark:bg-red-900/20 border border-red-200/60 dark:border-red-800/40 px-4 py-3"
                        >
                            <Icon icon="lucide:alert-circle" className="w-4 h-4 text-red-500 dark:text-red-400 shrink-0 mt-0.5" />
                            <div className="space-y-0.5">
                                {errors.username && <p className="text-sm text-red-600 dark:text-red-300">{errors.username}</p>}
                                {errors.password && <p className="text-sm text-red-600 dark:text-red-300">{errors.password}</p>}
                                {errors.email && <p className="text-sm text-red-600 dark:text-red-300">{errors.email}</p>}
                            </div>
                        </motion.div>
                    )}
                </AnimatePresence>

                {/* Card */}
                <motion.div {...fadeUp(0.2)}>
                    <div className="rounded-2xl border border-slate-200/70 dark:border-slate-700/50 bg-white/70 dark:bg-slate-800/50 backdrop-blur-xl shadow-xl shadow-slate-900/[0.04] dark:shadow-black/20">
                        <div className="p-7">
                            <form onSubmit={submit} className="space-y-5">
                                {/* Username */}
                                <div>
                                    <label className="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1.5">
                                        Username
                                    </label>
                                    <div className="relative">
                                        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                                            <Icon icon="lucide:user" className="w-[18px] h-[18px] text-slate-400" />
                                        </div>
                                        <input
                                            type="text"
                                            value={data.username}
                                            onChange={(e) => setData('username', e.target.value)}
                                            placeholder="Enter your username"
                                            required
                                            autoComplete="username"
                                            autoFocus
                                            className="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50/50 dark:bg-slate-700/30 py-3 pl-11 pr-4 text-sm text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 outline-none transition-colors hover:border-indigo-400 dark:hover:border-indigo-500 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                                        />
                                    </div>
                                </div>

                                {/* Password */}
                                <div>
                                    <label className="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1.5">
                                        Password
                                    </label>
                                    <div className="relative">
                                        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                                            <Icon icon="lucide:lock" className="w-[18px] h-[18px] text-slate-400" />
                                        </div>
                                        <input
                                            type={showPassword ? "text" : "password"}
                                            value={data.password}
                                            onChange={(e) => setData('password', e.target.value)}
                                            placeholder="Enter your password"
                                            required
                                            autoComplete="current-password"
                                            className="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50/50 dark:bg-slate-700/30 py-3 pl-11 pr-11 text-sm text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 outline-none transition-colors hover:border-indigo-400 dark:hover:border-indigo-500 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowPassword(!showPassword)}
                                            tabIndex={-1}
                                            className="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors"
                                        >
                                            <Icon
                                                icon={showPassword ? "lucide:eye-off" : "lucide:eye"}
                                                className="w-[18px] h-[18px]"
                                            />
                                        </button>
                                    </div>
                                </div>

                                {/* Remember + Forgot */}
                                <div className="flex items-center justify-between">
                                    <Checkbox
                                        isSelected={data.remember}
                                        onValueChange={(checked) => setData('remember', checked)}
                                        size="sm"
                                        classNames={{
                                            label: "text-xs text-slate-500 dark:text-slate-400"
                                        }}
                                    >
                                        Remember me
                                    </Checkbox>

                                    {canResetPassword && (
                                        <Link
                                            href="/forgot-password"
                                            className="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 transition-colors"
                                        >
                                            Forgot password?
                                        </Link>
                                    )}
                                </div>

                                {/* Submit */}
                                <Button
                                    type="submit"
                                    size="lg"
                                    isLoading={processing}
                                    className="w-full h-12 bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600 text-white font-medium text-sm shadow-lg shadow-indigo-500/20 hover:shadow-indigo-500/30 transition-all duration-200"
                                    startContent={
                                        !processing && <Icon icon="lucide:arrow-right" className="w-4 h-4" />
                                    }
                                    radius="lg"
                                >
                                    {processing ? "Signing in…" : "Sign in"}
                                </Button>
                            </form>

                            {oidcEnabled && (
                                <div className="mt-5">
                                    <div className="relative flex items-center">
                                        <div className="flex-grow border-t border-slate-200 dark:border-slate-700/50" />
                                        <span className="mx-3 text-xs text-slate-400">or</span>
                                        <div className="flex-grow border-t border-slate-200 dark:border-slate-700/50" />
                                    </div>
                                    <a
                                        href="/auth/oidc/redirect"
                                        className="mt-4 inline-flex w-full items-center justify-center gap-2 h-12 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700/60 transition-colors"
                                    >
                                        <Icon icon="lucide:shield-check" className="w-4 h-4 text-indigo-500" />
                                        {oidcLabel}
                                    </a>
                                </div>
                            )}

                            {/* Divider + dark mode */}
                            <div className="mt-5 pt-4 border-t border-slate-100 dark:border-slate-700/50 flex justify-center">
                                <button
                                    type="button"
                                    onClick={toggleDarkMode}
                                    className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700/50 transition-colors"
                                >
                                    <Icon
                                        icon={isDarkMode ? "lucide:sun" : "lucide:moon"}
                                        className="w-3.5 h-3.5"
                                    />
                                    {isDarkMode ? "Light mode" : "Dark mode"}
                                </button>
                            </div>
                        </div>
                    </div>
                </motion.div>

                {/* Create Account CTA */}
                <motion.div {...fadeUp(0.3)} className="mt-6">
                    <div className="rounded-xl border border-indigo-200/50 dark:border-indigo-800/30 bg-indigo-50/50 dark:bg-indigo-900/10 backdrop-blur-sm px-5 py-4">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-800/40">
                                    <Icon icon="lucide:user-plus" className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-slate-700 dark:text-slate-200">New here?</p>
                                    <p className="text-xs text-slate-500 dark:text-slate-400">Create an account to get started</p>
                                </div>
                            </div>
                            <Link
                                href="/register"
                                className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600 text-white text-xs font-medium shadow-sm transition-all duration-200"
                            >
                                Create Account
                                <Icon icon="lucide:arrow-right" className="w-3.5 h-3.5" />
                            </Link>
                        </div>
                    </div>
                </motion.div>

                {/* Demo credentials */}
                <motion.div {...fadeUp(0.4)} className="mt-4">
                    <div className="rounded-xl border border-sky-200/50 dark:border-sky-800/30 bg-sky-50/50 dark:bg-sky-900/10 backdrop-blur-sm px-5 py-4">
                        <div className="flex items-start gap-3">
                            <div className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-sky-100 dark:bg-sky-800/40">
                                <Icon icon="lucide:info" className="w-3.5 h-3.5 text-sky-600 dark:text-sky-400" />
                            </div>
                            <div>
                                <p className="text-xs font-medium text-sky-700 dark:text-sky-300 mb-1.5">Demo credentials</p>
                                <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-sky-600 dark:text-sky-400">
                                    <span>Username: <code className="font-semibold text-sky-700 dark:text-sky-300">admin</code></span>
                                    <span>Password: <code className="font-semibold text-sky-700 dark:text-sky-300">password</code></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </motion.div>
            </motion.div>
        </GuestLayout>
    );
}
