import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { Button, Checkbox } from '@heroui/react';
import { motion, AnimatePresence } from 'framer-motion';
import AuthLayout from '@/Layouts/AuthLayout';
import { AuthField } from '@/Components/Auth/AuthField';

export default function Login({ status, canResetPassword, oidcEnabled = false, oidcLabel = 'Sign in with Authentik' }) {
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

    return (
        <AuthLayout>
            <Head title="Sign In — Zephyrus" />

            {/* Heading */}
            <div className="mb-6 text-center">
                <h2 className="text-2xl font-light text-slate-100">Welcome back</h2>
                <p className="mt-1.5 text-sm text-slate-400">Sign in to continue to your dashboard</p>
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
                {(errors.username || errors.password || errors.email) && (
                    <motion.div
                        initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }}
                        className="mb-5 flex items-start gap-2.5 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3"
                    >
                        <Icon icon="lucide:alert-circle" className="mt-0.5 h-4 w-4 shrink-0 text-red-400" />
                        <div className="space-y-0.5">
                            {errors.username && <p className="text-sm text-red-300">{errors.username}</p>}
                            {errors.password && <p className="text-sm text-red-300">{errors.password}</p>}
                            {errors.email && <p className="text-sm text-red-300">{errors.email}</p>}
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            <form onSubmit={submit} className="space-y-5">
                <AuthField
                    id="username" label="Username" icon="lucide:user"
                    value={data.username} onChange={(v) => setData('username', v)}
                    placeholder="Enter your username" autoComplete="username" autoFocus required
                />
                <AuthField
                    id="password" label="Password" icon="lucide:lock" type="password" revealable
                    value={data.password} onChange={(v) => setData('password', v)}
                    placeholder="Enter your password" autoComplete="current-password" required
                />

                <div className="flex items-center justify-between">
                    <Checkbox
                        isSelected={data.remember}
                        onValueChange={(checked) => setData('remember', checked)}
                        size="sm"
                        classNames={{ label: 'text-xs text-slate-400' }}
                    >
                        Remember me
                    </Checkbox>
                    {canResetPassword && (
                        <Link href="/forgot-password" className="text-xs font-medium text-indigo-300 transition-colors hover:text-indigo-200">
                            Forgot password?
                        </Link>
                    )}
                </div>

                <Button
                    type="submit" size="lg" isLoading={processing} radius="lg"
                    className="h-12 w-full bg-gradient-to-r from-indigo-500 via-blue-500 to-cyan-500 text-sm font-medium text-white shadow-lg shadow-indigo-500/20 transition-all duration-200 hover:from-indigo-600 hover:via-blue-600 hover:to-cyan-600 hover:shadow-indigo-500/30"
                    startContent={!processing && <Icon icon="lucide:arrow-right" className="h-4 w-4" />}
                >
                    {processing ? 'Signing in…' : 'Sign in'}
                </Button>
            </form>

            {oidcEnabled && (
                <div className="mt-5">
                    <div className="relative flex items-center">
                        <div className="flex-grow border-t border-white/10" />
                        <span className="mx-3 text-xs text-slate-500">or</span>
                        <div className="flex-grow border-t border-white/10" />
                    </div>
                    <a
                        href="/auth/oidc/redirect"
                        className="mt-4 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/[0.04] text-sm font-medium text-slate-200 transition-colors hover:bg-white/[0.08]"
                    >
                        <Icon icon="lucide:shield-check" className="h-4 w-4 text-indigo-400" />
                        {oidcLabel}
                    </a>
                </div>
            )}

            {/* Create Account CTA — REQUIRED by auth-system.md (do not remove) */}
            <div className="mt-6 rounded-xl border border-indigo-400/20 bg-indigo-500/[0.08] px-5 py-4">
                <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-500/20">
                            <Icon icon="lucide:user-plus" className="h-4 w-4 text-indigo-300" />
                        </div>
                        <div>
                            <p className="text-sm font-medium text-slate-200">New here?</p>
                            <p className="text-xs text-slate-400">Create an account to get started</p>
                        </div>
                    </div>
                    <Link
                        href="/register"
                        className="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-xs font-medium text-white shadow-sm transition-all duration-200 hover:bg-indigo-500"
                    >
                        Create Account
                        <Icon icon="lucide:arrow-right" className="h-3.5 w-3.5" />
                    </Link>
                </div>
            </div>

            {/* Demo credentials — subtle footnote */}
            <div className="mt-4 flex items-center justify-center gap-x-4 text-xs text-slate-500">
                <span className="inline-flex items-center gap-1.5">
                    <Icon icon="lucide:info" className="h-3.5 w-3.5 text-sky-400/70" />
                    Demo:
                </span>
                <span>user <code className="font-semibold text-slate-300">admin</code></span>
                <span>pass <code className="font-semibold text-slate-300">password</code></span>
            </div>
        </AuthLayout>
    );
}
