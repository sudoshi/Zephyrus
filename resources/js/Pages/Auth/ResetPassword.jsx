import { Head, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { Button } from '@heroui/react';
import { motion, AnimatePresence } from 'framer-motion';
import AuthLayout from '@/Layouts/AuthLayout';
import { AuthField } from '@/Components/Auth/AuthField';

export default function ResetPassword({ token, email }) {
    const { data, setData, post, processing, errors } = useForm({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/reset-password', {
            onFinish: () => {
                setData('password', '');
                setData('password_confirmation', '');
            },
        });
    };

    return (
        <AuthLayout>
            <Head title="Reset Password — Zephyrus" />

            {/* Heading */}
            <div className="mb-6 text-center">
                <h2 className="text-2xl font-light text-slate-100">Choose a new password.</h2>
                <p className="mt-1.5 text-sm text-slate-400">Enter your email and a new password below.</p>
            </div>

            {/* Errors */}
            <AnimatePresence mode="wait">
                {(errors.email || errors.password || errors.password_confirmation || errors.token) && (
                    <motion.div
                        initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                        className="mb-5 flex items-start gap-2.5 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3"
                    >
                        <Icon icon="lucide:alert-circle" className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                        <div className="space-y-0.5">
                            {errors.token && <p className="text-sm text-red-300">{errors.token}</p>}
                            {errors.email && <p className="text-sm text-red-300">{errors.email}</p>}
                            {errors.password && <p className="text-sm text-red-300">{errors.password}</p>}
                            {errors.password_confirmation && <p className="text-sm text-red-300">{errors.password_confirmation}</p>}
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            <form onSubmit={submit} className="space-y-5">
                <AuthField
                    id="email" label="Email address" icon="lucide:mail" type="email"
                    value={data.email} onChange={(v) => setData('email', v)}
                    placeholder="Enter your email address" autoComplete="username" required
                />
                <AuthField
                    id="password" label="New Password" icon="lucide:lock" type="password" revealable
                    value={data.password} onChange={(v) => setData('password', v)}
                    placeholder="Choose a new password" autoComplete="new-password" autoFocus required
                />
                <AuthField
                    id="password_confirmation" label="Confirm New Password" icon="lucide:lock-keyhole" type="password" revealable
                    value={data.password_confirmation} onChange={(v) => setData('password_confirmation', v)}
                    placeholder="Confirm your new password" autoComplete="new-password" required
                />

                <Button
                    type="submit" size="lg" isLoading={processing} radius="lg"
                    className="h-12 w-full bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 text-sm font-medium text-white shadow-lg shadow-indigo-500/20 transition-all duration-200 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600 hover:shadow-indigo-500/30"
                    startContent={!processing && <Icon icon="lucide:check" className="h-4 w-4" />}
                >
                    {processing ? 'Resetting…' : 'Reset Password'}
                </Button>
            </form>
        </AuthLayout>
    );
}
