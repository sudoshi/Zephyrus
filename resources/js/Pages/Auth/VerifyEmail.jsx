import { Head, Link, router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import React from 'react';
import AuthLayout from '@/Layouts/AuthLayout';

export default function VerifyEmail({ status }) {
    const [processing, setProcessing] = React.useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setProcessing(true);

        try {
            await router.post('/email/verification-notification');
        } catch (error) {
            console.error('Failed to send verification email:', error);
        } finally {
            setProcessing(false);
        }
    };

    return (
        <AuthLayout>
            <Head title="Email Verification — Zephyrus" />

            <div className="za-state">
                <div className="za-state-icon">
                    <Icon icon="lucide:mail" width="26" height="26" />
                </div>
                <h2>Verify your email</h2>
                <p>
                    Thanks for signing up! Please verify your email address by clicking the link
                    we just emailed you. If you didn't receive it, we'll gladly send another.
                </p>
            </div>

            {status === 'verification-link-sent' && (
                <div className="za-alert za-alert-ok">
                    <Icon icon="lucide:check-circle-2" width="16" height="16" />
                    <span>A new verification link has been sent to your email address.</span>
                </div>
            )}

            <form onSubmit={submit}>
                <button type="submit" className="za-btn-primary" disabled={processing}>
                    {processing ? 'Sending…' : 'Resend verification email'}
                </button>
                <p className="za-create">
                    <Link href="/logout" method="post" as="button">Log out</Link>
                </p>
            </form>
        </AuthLayout>
    );
}
