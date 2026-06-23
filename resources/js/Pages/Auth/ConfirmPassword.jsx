import { Head, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { Button } from '@heroui/react';
import { motion, AnimatePresence } from 'framer-motion';
import AuthLayout from '@/Layouts/AuthLayout';
import { AuthField } from '@/Components/Auth/AuthField';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors } = useForm({
        password: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/confirm-password', {
            onFinish: () => {
                setData('password', '');
            },
        });
    };

    return (
        <AuthLayout>
            <Head title="Confirm Password — Zephyrus" />

            {/* Heading */}
            <div className="mb-6 text-center">
                <div className="mb-3 flex justify-center">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-500/15">
                        <Icon icon="lucide:shield-check" className="h-6 w-6 text-indigo-400" />
                    </div>
                </div>
                <h2 className="text-2xl font-light text-slate-100">Confirm your password.</h2>
                <p className="mt-1.5 text-sm text-slate-400">This is a secure area. Please confirm your password before continuing.</p>
            </div>

            {/* Errors */}
            <AnimatePresence mode="wait">
                {errors.password && (
                    <motion.div
                        initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                        className="mb-5 flex items-start gap-2.5 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3"
                    >
                        <Icon icon="lucide:alert-circle" className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                        <p className="text-sm text-red-300">{errors.password}</p>
                    </motion.div>
                )}
            </AnimatePresence>

            <form onSubmit={submit} className="space-y-5">
                <AuthField
                    id="password" label="Password" icon="lucide:lock" type="password" revealable
                    value={data.password} onChange={(v) => setData('password', v)}
                    placeholder="Enter your password" autoComplete="current-password" autoFocus required
                />

                <Button
                    type="submit" size="lg" isLoading={processing} radius="lg"
                    className="h-12 w-full bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 text-sm font-medium text-white shadow-lg shadow-indigo-500/20 transition-all duration-200 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600 hover:shadow-indigo-500/30"
                    startContent={!processing && <Icon icon="lucide:arrow-right" className="h-4 w-4" />}
                >
                    {processing ? 'Confirming…' : 'Confirm'}
                </Button>
            </form>
        </AuthLayout>
    );
}
