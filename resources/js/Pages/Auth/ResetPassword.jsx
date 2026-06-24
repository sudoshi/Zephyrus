import { Head, router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import React from 'react';
import AuthLayout from '@/Layouts/AuthLayout';
import { AuthField } from '@/Components/Auth/AuthField';

export default function ResetPassword({ token, email }) {
    const [data, setData] = React.useState({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });
    const [processing, setProcessing] = React.useState(false);
    const [errors, setErrors] = React.useState({});

    const submit = async (e) => {
        e.preventDefault();
        setProcessing(true);

        try {
            await router.post('/reset-password', data);
            setData((prev) => ({ ...prev, password: '', password_confirmation: '' }));
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
            <Head title="Reset Password — Zephyrus" />

            <div className="za-form-head">
                <h1>Choose a new password</h1>
                <p>Set a new password for your account.</p>
            </div>

            <form onSubmit={submit}>
                <AuthField
                    id="email" label="Email address" icon="lucide:mail" type="email"
                    value={data.email} onChange={(v) => setData((p) => ({ ...p, email: v }))}
                    autoComplete="username" required error={errors.email}
                />
                <AuthField
                    id="password" label="New password" icon="lucide:lock" type="password" revealable
                    value={data.password} onChange={(v) => setData((p) => ({ ...p, password: v }))}
                    placeholder="At least 8 characters" autoComplete="new-password" autoFocus required
                    error={errors.password}
                />
                <AuthField
                    id="password_confirmation" label="Confirm password" icon="lucide:lock-keyhole" type="password" revealable
                    value={data.password_confirmation} onChange={(v) => setData((p) => ({ ...p, password_confirmation: v }))}
                    placeholder="Re-enter new password" autoComplete="new-password" required
                    error={errors.password_confirmation}
                />

                <button type="submit" className="za-btn-primary" disabled={processing}>
                    {processing ? 'Resetting…' : 'Reset password'}
                    {!processing && <Icon icon="lucide:check" width="18" height="18" />}
                </button>
            </form>
        </AuthLayout>
    );
}
