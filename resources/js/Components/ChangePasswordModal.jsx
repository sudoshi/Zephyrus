import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { Button } from '@heroui/react';
import { motion } from 'framer-motion';

export default function ChangePasswordModal() {
    const [showCurrentPassword, setShowCurrentPassword] = useState(false);
    const [showNewPassword, setShowNewPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        current_password: '',
        new_password: '',
        new_password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/change-password');
    };

    return (
        <div className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 backdrop-blur-sm">
            <motion.div
                initial={{ opacity: 0, scale: 0.95, y: 16 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                transition={{ duration: 0.3, ease: [0.25, 0.46, 0.45, 0.94] }}
                className="w-full max-w-md mx-4"
            >
                <div className="rounded-2xl border border-slate-200/70 dark:border-slate-700/50 bg-white dark:bg-slate-800 shadow-2xl">
                    <div className="p-6">
                        {/* Header */}
                        <div className="text-center mb-6">
                            <div className="flex justify-center mb-3">
                                <div className="flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/30">
                                    <Icon icon="lucide:shield-alert" className="w-6 h-6 text-amber-600 dark:text-amber-400" />
                                </div>
                            </div>
                            <h2 className="text-xl font-semibold text-slate-800 dark:text-slate-100">
                                Password Change Required
                            </h2>
                            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                You must change your temporary password before continuing.
                            </p>
                        </div>

                        {/* Errors */}
                        {(errors.current_password || errors.new_password || errors.new_password_confirmation) && (
                            <div className="mb-4 flex items-start gap-2.5 rounded-xl bg-red-50/80 dark:bg-red-900/20 border border-red-200/60 dark:border-red-800/40 px-4 py-3">
                                <Icon icon="lucide:alert-circle" className="w-4 h-4 text-red-500 dark:text-red-400 shrink-0 mt-0.5" />
                                <div className="space-y-0.5">
                                    {errors.current_password && <p className="text-sm text-red-600 dark:text-red-300">{errors.current_password}</p>}
                                    {errors.new_password && <p className="text-sm text-red-600 dark:text-red-300">{errors.new_password}</p>}
                                    {errors.new_password_confirmation && <p className="text-sm text-red-600 dark:text-red-300">{errors.new_password_confirmation}</p>}
                                </div>
                            </div>
                        )}

                        <form onSubmit={submit} className="space-y-4">
                            {/* Current Password */}
                            <div>
                                <label className="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1.5">
                                    Current (Temporary) Password
                                </label>
                                <div className="relative">
                                    <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                                        <Icon icon="lucide:key" className="w-[18px] h-[18px] text-slate-400" />
                                    </div>
                                    <input
                                        type={showCurrentPassword ? "text" : "password"}
                                        value={data.current_password}
                                        onChange={(e) => setData('current_password', e.target.value)}
                                        placeholder="Enter your temporary password"
                                        required
                                        autoFocus
                                        autoComplete="current-password"
                                        className="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50/50 dark:bg-slate-700/30 py-3 pl-11 pr-11 text-sm text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 outline-none transition-colors hover:border-indigo-400 dark:hover:border-indigo-500 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowCurrentPassword(!showCurrentPassword)}
                                        tabIndex={-1}
                                        className="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors"
                                    >
                                        <Icon icon={showCurrentPassword ? "lucide:eye-off" : "lucide:eye"} className="w-[18px] h-[18px]" />
                                    </button>
                                </div>
                            </div>

                            {/* New Password */}
                            <div>
                                <label className="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1.5">
                                    New Password
                                </label>
                                <div className="relative">
                                    <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                                        <Icon icon="lucide:lock" className="w-[18px] h-[18px] text-slate-400" />
                                    </div>
                                    <input
                                        type={showNewPassword ? "text" : "password"}
                                        value={data.new_password}
                                        onChange={(e) => setData('new_password', e.target.value)}
                                        placeholder="Min 8 characters"
                                        required
                                        autoComplete="new-password"
                                        className="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50/50 dark:bg-slate-700/30 py-3 pl-11 pr-11 text-sm text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 outline-none transition-colors hover:border-indigo-400 dark:hover:border-indigo-500 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowNewPassword(!showNewPassword)}
                                        tabIndex={-1}
                                        className="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors"
                                    >
                                        <Icon icon={showNewPassword ? "lucide:eye-off" : "lucide:eye"} className="w-[18px] h-[18px]" />
                                    </button>
                                </div>
                            </div>

                            {/* Confirm New Password */}
                            <div>
                                <label className="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1.5">
                                    Confirm New Password
                                </label>
                                <div className="relative">
                                    <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                                        <Icon icon="lucide:lock-keyhole" className="w-[18px] h-[18px] text-slate-400" />
                                    </div>
                                    <input
                                        type={showConfirmPassword ? "text" : "password"}
                                        value={data.new_password_confirmation}
                                        onChange={(e) => setData('new_password_confirmation', e.target.value)}
                                        placeholder="Confirm your new password"
                                        required
                                        autoComplete="new-password"
                                        className="w-full rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50/50 dark:bg-slate-700/30 py-3 pl-11 pr-11 text-sm text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 outline-none transition-colors hover:border-indigo-400 dark:hover:border-indigo-500 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                                        tabIndex={-1}
                                        className="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors"
                                    >
                                        <Icon icon={showConfirmPassword ? "lucide:eye-off" : "lucide:eye"} className="w-[18px] h-[18px]" />
                                    </button>
                                </div>
                            </div>

                            {/* Submit */}
                            <Button
                                type="submit"
                                size="lg"
                                isLoading={processing}
                                className="w-full h-12 bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600 text-white font-medium text-sm shadow-lg shadow-indigo-500/20 hover:shadow-indigo-500/30 transition-all duration-200"
                                startContent={
                                    !processing && <Icon icon="lucide:check" className="w-4 h-4" />
                                }
                                radius="lg"
                            >
                                {processing ? "Changing password..." : "Change Password"}
                            </Button>
                        </form>
                    </div>
                </div>
            </motion.div>
        </div>
    );
}
