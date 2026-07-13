import { Head, router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import React from 'react';
import AuthLayout from '@/Layouts/AuthLayout';
import { AuthField } from '@/Components/Auth/AuthField';

export default function ConfirmPassword({ oidcAvailable = false }) {
    const [data, setData] = React.useState({ password: '' });
    const [processing, setProcessing] = React.useState(false);
    const [errors, setErrors] = React.useState({});

    const submit = async (e) => {
        e.preventDefault();
        setProcessing(true);

        try {
            await router.post('/confirm-password', data);
            setData((prev) => ({ ...prev, password: '' }));
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
            <Head title="Confirm Password — Zephyrus" />

            <div className="za-form-head">
                <div className="za-head-icon">
                    <Icon icon="lucide:lock" width="22" height="22" />
                </div>
                <h1>Confirm your password</h1>
                <p>This is a secure area. Please confirm your password before continuing.</p>
            </div>

            <form onSubmit={submit}>
                <AuthField
                    id="password" label="Password" icon="lucide:lock" type="password" revealable
                    value={data.password} onChange={(v) => setData((p) => ({ ...p, password: v }))}
                    autoComplete="current-password" autoFocus required error={errors.password}
                />

                <button type="submit" className="za-btn-primary" disabled={processing}>
                    {processing ? 'Confirming…' : 'Confirm'}
                    {!processing && <Icon icon="lucide:arrow-right" width="18" height="18" />}
                </button>
            </form>

            {oidcAvailable && (
                <a href="/auth/oidc/step-up" className="za-btn-secondary mt-3 inline-flex w-full items-center justify-center gap-2">
                    <Icon icon="lucide:shield-check" width="18" height="18" />
                    Reauthenticate with enterprise MFA
                </a>
            )}
        </AuthLayout>
    );
}
