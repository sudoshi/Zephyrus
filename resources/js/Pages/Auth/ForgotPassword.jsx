import { Head, Link, router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import React from 'react';
import AuthLayout from '@/Layouts/AuthLayout';
import { AuthField } from '@/Components/Auth/AuthField';

export default function ForgotPassword({ status }) {
    const [data, setData] = React.useState({ email: '' });
    const [processing, setProcessing] = React.useState(false);
    const [errors, setErrors] = React.useState({});

    const submit = async (e) => {
        e.preventDefault();
        setProcessing(true);

        try {
            await router.post('/forgot-password', data);
        } catch (error) {
            if (error.response?.data?.errors) {
                setErrors(error.response.data.errors);
            }
        } finally {
            setProcessing(false);
        }
    };

    return (
        <AuthLayout>
            <Head title="Forgot Password — Zephyrus" />

            <div className="za-form-head">
                <h1>Reset your password</h1>
                <p>Enter your email and we'll send you a secure reset link.</p>
            </div>

            {status && (
                <div className="za-alert za-alert-ok">
                    <Icon icon="lucide:check-circle-2" width="16" height="16" />
                    <span>{status}</span>
                </div>
            )}

            <form onSubmit={submit}>
                <AuthField
                    id="email" label="Email address" icon="lucide:mail" type="email"
                    value={data.email} onChange={(v) => setData((p) => ({ ...p, email: v }))}
                    placeholder="you@hospital.org" autoComplete="email" autoFocus required
                    error={errors.email}
                />

                <button type="submit" className="za-btn-primary" disabled={processing}>
                    {processing ? 'Sending…' : 'Send reset link'}
                    {!processing && <Icon icon="lucide:arrow-right" width="18" height="18" />}
                </button>

                <p className="za-create"><Link href="/login">← Back to sign in</Link></p>
            </form>
        </AuthLayout>
    );
}
