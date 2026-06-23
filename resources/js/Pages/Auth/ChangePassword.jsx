import { Head, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { Button } from '@heroui/react';
import { motion, AnimatePresence } from 'framer-motion';
import AuthLayout from '@/Layouts/AuthLayout';
import { AuthField } from '@/Components/Auth/AuthField';

export default function ChangePassword() {
    const { data, setData, post, processing, errors } = useForm({
        current_password: '',
        new_password: '',
        new_password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/change-password', {
            onFinish: () => {
                if (Object.keys(errors).length > 0) {
                    setData('current_password', '');
                    setData('new_password', '');
                    setData('new_password_confirmation', '');
                }
            },
        });
    };

    return (
        <AuthLayout>
            <Head title="Change Password — Zephyrus" />

            <div className="mb-6 text-center">
                <div className="mb-3 flex justify-center">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-amber-500/15">
                        <Icon icon="lucide:shield-alert" className="h-6 w-6 text-amber-400" />
                    </div>
                </div>
                <h2 className="text-2xl font-light text-slate-100">Change Password</h2>
                <p className="mt-1.5 text-sm text-slate-400">You must change your temporary password before continuing</p>
            </div>

            <AnimatePresence mode="wait">
                {(errors.current_password || errors.new_password || errors.new_password_confirmation) && (
                    <motion.div
                        initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                        className="mb-5 flex items-start gap-2.5 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3"
                    >
                        <Icon icon="lucide:alert-circle" className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                        <div className="space-y-0.5">
                            {errors.current_password && <p className="text-sm text-red-300">{errors.current_password}</p>}
                            {errors.new_password && <p className="text-sm text-red-300">{errors.new_password}</p>}
                            {errors.new_password_confirmation && <p className="text-sm text-red-300">{errors.new_password_confirmation}</p>}
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            <form onSubmit={submit} className="space-y-5">
                <AuthField
                    id="current_password" label="Current (Temporary) Password" icon="lucide:key" type="password" revealable
                    value={data.current_password} onChange={(v) => setData('current_password', v)}
                    placeholder="Enter your temporary password" autoComplete="current-password" autoFocus required
                />
                <AuthField
                    id="new_password" label="New Password" icon="lucide:lock" type="password" revealable
                    value={data.new_password} onChange={(v) => setData('new_password', v)}
                    placeholder="Choose a new password (min 8 characters)" autoComplete="new-password" required
                />
                <AuthField
                    id="new_password_confirmation" label="Confirm New Password" icon="lucide:lock-keyhole" type="password" revealable
                    value={data.new_password_confirmation} onChange={(v) => setData('new_password_confirmation', v)}
                    placeholder="Confirm your new password" autoComplete="new-password" required
                />

                <Button
                    type="submit" size="lg" isLoading={processing} radius="lg"
                    className="h-12 w-full bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 text-sm font-medium text-white shadow-lg shadow-indigo-500/20 transition-all duration-200 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600 hover:shadow-indigo-500/30"
                    startContent={!processing && <Icon icon="lucide:check" className="h-4 w-4" />}
                >
                    {processing ? 'Changing password…' : 'Change Password'}
                </Button>
            </form>
        </AuthLayout>
    );
}
