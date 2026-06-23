import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useState } from 'react';
import { Button } from '@heroui/react';
import { motion, AnimatePresence } from 'framer-motion';
import AuthLayout from '@/Layouts/AuthLayout';
import { AuthField } from '@/Components/Auth/AuthField';

export default function Register() {
    const [success, setSuccess] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        phone: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/register', {
            onSuccess: () => setSuccess(true),
        });
    };

    return (
        <AuthLayout>
            <Head title="Create Account — Zephyrus" />

            <div className="mb-6 text-center">
                <h2 className="text-2xl font-light text-slate-100">Create Account</h2>
                <p className="mt-1.5 text-sm text-slate-400">Sign up to get started with Zephyrus</p>
            </div>

            <AnimatePresence mode="wait">
                {(errors.name || errors.email || errors.phone) && (
                    <motion.div
                        initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                        className="mb-5 flex items-start gap-2.5 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3"
                    >
                        <Icon icon="lucide:alert-circle" className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                        <div className="space-y-0.5">
                            {errors.name && <p className="text-sm text-red-300">{errors.name}</p>}
                            {errors.email && <p className="text-sm text-red-300">{errors.email}</p>}
                            {errors.phone && <p className="text-sm text-red-300">{errors.phone}</p>}
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            <AnimatePresence mode="wait">
                {success ? (
                    <motion.div
                        key="success"
                        initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                        className="py-4 text-center"
                    >
                        <div className="mb-4 flex justify-center">
                            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-500/15">
                                <Icon icon="lucide:mail-check" className="h-7 w-7 text-emerald-400" />
                            </div>
                        </div>
                        <h3 className="mb-2 text-lg font-medium text-slate-100">Check your inbox</h3>
                        <p className="mb-6 text-sm text-slate-400">
                            We've sent your temporary password and username to your email address. Use them to sign in.
                        </p>
                        <Link
                            href="/login"
                            className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 px-5 py-2.5 text-sm font-medium text-white shadow-lg shadow-indigo-500/20 transition-all duration-200 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600"
                        >
                            <Icon icon="lucide:arrow-left" className="h-4 w-4" />
                            Go to Sign In
                        </Link>
                    </motion.div>
                ) : (
                    <motion.form key="form" onSubmit={submit} className="space-y-5" initial={{ opacity: 1 }} exit={{ opacity: 0 }}>
                        <AuthField
                            id="name" label="Full Name" icon="lucide:user"
                            value={data.name} onChange={(v) => setData('name', v)}
                            placeholder="Enter your full name" autoComplete="name" autoFocus required
                        />
                        <AuthField
                            id="email" label="Email Address" icon="lucide:mail" type="email"
                            value={data.email} onChange={(v) => setData('email', v)}
                            placeholder="Enter your email address" autoComplete="email" required
                        />
                        <AuthField
                            id="phone" label="Phone Number" icon="lucide:phone" type="tel" optional
                            value={data.phone} onChange={(v) => setData('phone', v)}
                            placeholder="Enter your phone number" autoComplete="tel"
                        />

                        <Button
                            type="submit" size="lg" isLoading={processing} radius="lg"
                            className="h-12 w-full bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 text-sm font-medium text-white shadow-lg shadow-indigo-500/20 transition-all duration-200 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600 hover:shadow-indigo-500/30"
                            startContent={!processing && <Icon icon="lucide:user-plus" className="h-4 w-4" />}
                        >
                            {processing ? 'Creating account…' : 'Create Account'}
                        </Button>

                        <div className="pt-1 text-center">
                            <span className="text-sm text-slate-400">Already have an account? </span>
                            <Link href="/login" className="text-sm font-medium text-indigo-300 transition-colors hover:text-indigo-200">
                                Sign in
                            </Link>
                        </div>
                    </motion.form>
                )}
            </AnimatePresence>
        </AuthLayout>
    );
}
