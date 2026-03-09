import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import React, { useEffect, useState } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Button } from '@heroui/react';
import { motion, AnimatePresence } from 'framer-motion';

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

export default function Register() {
    const [isDarkMode, setIsDarkMode] = useDarkMode();
    const [success, setSuccess] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        phone: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/register', {
            onSuccess: () => {
                setSuccess(true);
            },
        });
    };

    const toggleDarkMode = () => setIsDarkMode(!isDarkMode);

    return (
        <GuestLayout>
            <Head title="Create Account — Zephyrus" />

            <motion.div
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                transition={{ duration: 0.5 }}
                className="w-full max-w-[420px] mx-auto"
            >
                {/* Header */}
                <motion.div {...fadeUp(0.1)} className="text-center mb-8">
                    <h1 className="text-3xl font-extralight tracking-tight text-slate-800 dark:text-slate-100">
                        Create Account
                    </h1>
                    <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        Sign up to get started with Zephyrus
                    </p>
                </motion.div>

                {/* Error messages */}
                <AnimatePresence mode="wait">
                    {(errors.name || errors.email || errors.phone) && (
                        <motion.div
                            initial={{ opacity: 0, y: -8 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: -8 }}
                            className="mb-5 flex items-start gap-2.5 rounded-xl bg-red-50/80 dark:bg-red-900/20 border border-red-200/60 dark:border-red-800/40 px-4 py-3"
                        >
                            <Icon icon="lucide:alert-circle" className="w-4 h-4 text-red-500 dark:text-red-400 shrink-0 mt-0.5" />
                            <div className="space-y-0.5">
                                {errors.name && <p className="text-sm text-red-600 dark:text-red-300">{errors.name}</p>}
                                {errors.email && <p className="text-sm text-red-600 dark:text-red-300">{errors.email}</p>}
                                {errors.phone && <p className="text-sm text-red-600 dark:text-red-300">{errors.phone}</p>}
                            </div>
                        </motion.div>
                    )}
                </AnimatePresence>

                {/* Card */}
                <motion.div {...fadeUp(0.2)}>
                    <div className="rounded-2xl border border-slate-200/70 dark:border-slate-700/50 bg-white/70 dark:bg-slate-800/50 backdrop-blur-xl shadow-xl shadow-slate-900/[0.04] dark:shadow-black/20">
                        <div className="p-7">
                            <AnimatePresence mode="wait">
                                {success ? (
                                    <motion.div
                                        key="success"
                                        initial={{ opacity: 0, y: 8 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        exit={{ opacity: 0, y: -8 }}
                                        className="text-center py-4"
                                    >
                                        <div className="flex justify-center mb-4">
                                            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                                                <Icon icon="lucide:mail-check" className="w-7 h-7 text-emerald-600 dark:text-emerald-400" />
                                            </div>
                                        </div>
                                        <h3 className="text-lg font-medium text-slate-800 dark:text-slate-100 mb-2">
                                            Check your inbox
                                        </h3>
                                        <p className="text-sm text-slate-500 dark:text-slate-400 mb-6">
                                            We've sent your temporary password and username to your email address. Use them to sign in.
                                        </p>
                                        <Link
                                            href="/login"
                                            className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600 text-white text-sm font-medium shadow-lg shadow-indigo-500/20 transition-all duration-200"
                                        >
                                            <Icon icon="lucide:arrow-left" className="w-4 h-4" />
                                            Go to Sign In
                                        </Link>
                                    </motion.div>
                                ) : (
                                    <motion.form
                                        key="form"
                                        onSubmit={submit}
                                        className="space-y-5"
                                        initial={{ opacity: 1 }}
                                        exit={{ opacity: 0 }}
                                    >
                                        {/* Name */}
                                        <div>
                                            <label className="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1.5">
                                                Full Name
                                            </label>
                                            <div className="relative">
                                                <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                                                    <Icon icon="lucide:user" className="w-[18px] h-[18px] text-slate-400" />
                                                </div>
                                                <input
                                                    type="text"
                                                    value={data.name}
                                                    onChange={(e) => setData('name', e.target.value)}
                                                    placeholder="Enter your full name"
                                                    required
                                                    autoComplete="name"
                                                    autoFocus
                                                    className="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50/50 dark:bg-slate-700/30 py-3 pl-11 pr-4 text-sm text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 outline-none transition-colors hover:border-indigo-400 dark:hover:border-indigo-500 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                                                />
                                            </div>
                                        </div>

                                        {/* Email */}
                                        <div>
                                            <label className="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1.5">
                                                Email Address
                                            </label>
                                            <div className="relative">
                                                <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                                                    <Icon icon="lucide:mail" className="w-[18px] h-[18px] text-slate-400" />
                                                </div>
                                                <input
                                                    type="email"
                                                    value={data.email}
                                                    onChange={(e) => setData('email', e.target.value)}
                                                    placeholder="Enter your email address"
                                                    required
                                                    autoComplete="email"
                                                    className="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50/50 dark:bg-slate-700/30 py-3 pl-11 pr-4 text-sm text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 outline-none transition-colors hover:border-indigo-400 dark:hover:border-indigo-500 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                                                />
                                            </div>
                                        </div>

                                        {/* Phone (optional) */}
                                        <div>
                                            <label className="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1.5">
                                                Phone Number <span className="text-slate-400 dark:text-slate-500">(optional)</span>
                                            </label>
                                            <div className="relative">
                                                <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                                                    <Icon icon="lucide:phone" className="w-[18px] h-[18px] text-slate-400" />
                                                </div>
                                                <input
                                                    type="tel"
                                                    value={data.phone}
                                                    onChange={(e) => setData('phone', e.target.value)}
                                                    placeholder="Enter your phone number"
                                                    autoComplete="tel"
                                                    className="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50/50 dark:bg-slate-700/30 py-3 pl-11 pr-4 text-sm text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 outline-none transition-colors hover:border-indigo-400 dark:hover:border-indigo-500 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                                                />
                                            </div>
                                        </div>

                                        {/* Submit */}
                                        <Button
                                            type="submit"
                                            size="lg"
                                            isLoading={processing}
                                            className="w-full h-12 bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600 text-white font-medium text-sm shadow-lg shadow-indigo-500/20 hover:shadow-indigo-500/30 transition-all duration-200"
                                            startContent={
                                                !processing && <Icon icon="lucide:user-plus" className="w-4 h-4" />
                                            }
                                            radius="lg"
                                        >
                                            {processing ? "Creating account..." : "Create Account"}
                                        </Button>

                                        {/* Already have account */}
                                        <div className="text-center pt-2">
                                            <span className="text-sm text-slate-500 dark:text-slate-400">
                                                Already have an account?{' '}
                                            </span>
                                            <Link
                                                href="/login"
                                                className="text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 transition-colors"
                                            >
                                                Sign in
                                            </Link>
                                        </div>
                                    </motion.form>
                                )}
                            </AnimatePresence>

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
            </motion.div>
        </GuestLayout>
    );
}
