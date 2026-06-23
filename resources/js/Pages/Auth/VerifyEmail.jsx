import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { Button } from '@heroui/react';
import { motion, AnimatePresence } from 'framer-motion';
import AuthLayout from '@/Layouts/AuthLayout';

export default function VerifyEmail({ status }) {
    const { post, processing } = useForm({});

    const submit = (e) => {
        e.preventDefault();
        post('/email/verification-notification');
    };

    return (
        <AuthLayout>
            <Head title="Verify Email — Zephyrus" />

            {/* Heading */}
            <div className="mb-6 text-center">
                <div className="mb-3 flex justify-center">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-500/15">
                        <Icon icon="lucide:mail-check" className="h-6 w-6 text-indigo-400" />
                    </div>
                </div>
                <h2 className="text-2xl font-light text-slate-100">Verify your email.</h2>
                <p className="mt-1.5 text-sm text-slate-400">
                    Thanks for signing up! Before getting started, please verify your email address by clicking the link we sent you. If you didn't receive it, we can send another.
                </p>
            </div>

            {/* Status */}
            <AnimatePresence mode="wait">
                {status === 'verification-link-sent' && (
                    <motion.div
                        initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                        className="mb-5 flex items-center gap-2.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3"
                    >
                        <Icon icon="lucide:check-circle-2" className="h-4 w-4 shrink-0 text-emerald-400" />
                        <p className="text-sm text-emerald-300">
                            A new verification link has been sent to the email address you provided during registration.
                        </p>
                    </motion.div>
                )}
            </AnimatePresence>

            <form onSubmit={submit} className="space-y-4">
                <Button
                    type="submit" size="lg" isLoading={processing} radius="lg"
                    className="h-12 w-full bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 text-sm font-medium text-white shadow-lg shadow-indigo-500/20 transition-all duration-200 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600 hover:shadow-indigo-500/30"
                    startContent={!processing && <Icon icon="lucide:send" className="h-4 w-4" />}
                >
                    {processing ? 'Sending…' : 'Resend Verification Email'}
                </Button>

                <div className="text-center">
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        className="text-sm text-slate-400 transition-colors hover:text-slate-200"
                    >
                        Log Out
                    </Link>
                </div>
            </form>
        </AuthLayout>
    );
}
