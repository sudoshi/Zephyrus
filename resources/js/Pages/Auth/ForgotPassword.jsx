import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { Button } from '@heroui/react';
import { motion, AnimatePresence } from 'framer-motion';
import AuthLayout from '@/Layouts/AuthLayout';
import { AuthField } from '@/Components/Auth/AuthField';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/forgot-password');
    };

    return (
        <AuthLayout>
            <Head title="Reset Password — Zephyrus" />

            {/* Heading */}
            <div className="mb-6 text-center">
                <h2 className="text-2xl font-light text-slate-100">Reset your password</h2>
                <p className="mt-1.5 text-sm text-slate-400">We'll email you a secure reset link.</p>
            </div>

            {/* Status */}
            <AnimatePresence mode="wait">
                {status && (
                    <motion.div
                        initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                        className="mb-5 flex items-center gap-2.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3"
                    >
                        <Icon icon="lucide:check-circle-2" className="h-4 w-4 shrink-0 text-emerald-400" />
                        <p className="text-sm text-emerald-300">{status}</p>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* Errors */}
            <AnimatePresence mode="wait">
                {errors.email && (
                    <motion.div
                        initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                        className="mb-5 flex items-start gap-2.5 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3"
                    >
                        <Icon icon="lucide:alert-circle" className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                        <p className="text-sm text-red-300">{errors.email}</p>
                    </motion.div>
                )}
            </AnimatePresence>

            <form onSubmit={submit} className="space-y-5">
                <AuthField
                    id="email" label="Email address" icon="lucide:mail" type="email"
                    value={data.email} onChange={(v) => setData('email', v)}
                    placeholder="Enter your email address" autoComplete="email" autoFocus required
                />

                <Button
                    type="submit" size="lg" isLoading={processing} radius="lg"
                    className="h-12 w-full bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 text-sm font-medium text-white shadow-lg shadow-indigo-500/20 transition-all duration-200 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600 hover:shadow-indigo-500/30"
                    startContent={!processing && <Icon icon="lucide:send" className="h-4 w-4" />}
                >
                    {processing ? 'Sending…' : 'Email Password Reset Link'}
                </Button>
            </form>

            <div className="mt-6 text-center">
                <Link href="/login" className="inline-flex items-center gap-1.5 text-sm text-indigo-300 transition-colors hover:text-indigo-200">
                    <Icon icon="lucide:arrow-left" className="h-3.5 w-3.5" />
                    Back to sign in
                </Link>
            </div>
        </AuthLayout>
    );
}
