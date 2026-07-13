import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { AuthField } from '@/Components/Auth/AuthField';

export default function Login({
    status,
    canResetPassword,
    localAuthEnabled = true,
    registrationEnabled = false,
    oidcEnabled = false,
    oidcLabel = 'Sign in with Authentik',
    showDemoCredentials = false,
}) {
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

            {(errors.general || errors.username || errors.password || errors.email) && (
                <div className="za-alert za-alert-err">
                    <Icon icon="lucide:alert-circle" width="16" height="16" />
                    <div>
                        {errors.general && <div>{errors.general}</div>}
                        {errors.username && <div>{errors.username}</div>}
                        {errors.password && <div>{errors.password}</div>}
                        {errors.email && <div>{errors.email}</div>}
                    </div>
                </div>
            )}

            {localAuthEnabled && (
                <form onSubmit={submit}>
                    <AuthField
                        id="username" label="Username" icon="lucide:user"
                        value={data.username} onChange={(v) => setData('username', v)}
                        placeholder="Username" autoComplete="username" autoFocus required
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
                </form>
            )}

            {oidcEnabled && (
                <>
                    {localAuthEnabled && <div className="za-divider">or</div>}
                    <a href="/auth/oidc/redirect" className="za-btn-ghost">
                        <Icon icon="lucide:shield" width="18" height="18" />
                        {oidcLabel}
                    </a>
                </>
            )}

            {!localAuthEnabled && !oidcEnabled && (
                <div className="za-alert za-alert-err" role="alert">
                    <Icon icon="lucide:shield-alert" width="16" height="16" />
                    <span>No sign-in provider is currently available. Contact your administrator.</span>
                </div>
            )}

            {registrationEnabled && (
                <p className="za-create">New to Zephyrus? <Link href="/register">Create an account</Link></p>
            )}

            {showDemoCredentials && (
                <p className="za-demo">Demo credentials are configured for this local environment.</p>
            )}
        </AuthLayout>
    );
}
