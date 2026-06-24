import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
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

            <div className="za-form-head">
                <h1>Welcome back</h1>
                <p>Sign in to your operations command center.</p>
            </div>

            {status && (
                <div className="za-alert za-alert-ok">
                    <Icon icon="lucide:check-circle-2" width="16" height="16" />
                    <span>{status}</span>
                </div>
            )}

            {(errors.username || errors.password || errors.email) && (
                <div className="za-alert za-alert-err">
                    <Icon icon="lucide:alert-circle" width="16" height="16" />
                    <div>
                        {errors.username && <div>{errors.username}</div>}
                        {errors.password && <div>{errors.password}</div>}
                        {errors.email && <div>{errors.email}</div>}
                    </div>
                </div>
            )}

            <form onSubmit={submit}>
                <AuthField
                    id="username" label="Username" icon="lucide:user"
                    value={data.username} onChange={(v) => setData('username', v)}
                    placeholder="admin" autoComplete="username" autoFocus required
                />
                <AuthField
                    id="password" label="Password" icon="lucide:lock" type="password" revealable
                    value={data.password} onChange={(v) => setData('password', v)}
                    placeholder="••••••••••" autoComplete="current-password" required
                />

                <div className="za-row">
                    <label className="za-check">
                        <input
                            type="checkbox"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                        />
                        <span className="za-box">
                            <Icon icon="lucide:check" width="12" height="12" />
                        </span>
                        Remember me
                    </label>
                    {canResetPassword && (
                        <Link href="/forgot-password" className="za-link">Forgot password?</Link>
                    )}
                </div>

                <button type="submit" className="za-btn-primary" disabled={processing}>
                    {processing ? 'Signing in…' : 'Sign in'}
                    {!processing && <Icon icon="lucide:arrow-right" width="18" height="18" />}
                </button>

                {oidcEnabled && (
                    <>
                        <div className="za-divider">or</div>
                        <a href="/auth/oidc/redirect" className="za-btn-ghost">
                            <Icon icon="lucide:shield" width="18" height="18" />
                            {oidcLabel}
                        </a>
                    </>
                )}

                {/* Create Account CTA — required by auth-system.md (do not remove) */}
                <p className="za-create">New to Zephyrus? <Link href="/register">Create an account</Link></p>

                <p className="za-demo">Demo · user <code>admin</code> · pass <code>password</code></p>
            </form>
        </AuthLayout>
    );
}
