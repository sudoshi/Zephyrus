import { Head, Link, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useState } from 'react';
import AuthLayout from '@/Layouts/AuthLayout';
import { AuthField } from '@/Components/Auth/AuthField';

export default function Register() {
    const [success, setSuccess] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        phone: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/register', { onSuccess: () => setSuccess(true) });
    };

    return (
        <AuthLayout>
            <Head title="Create Account — Zephyrus" />

            {success ? (
                <div className="za-state">
                    <div className="za-state-icon">
                        <Icon icon="lucide:mail-check" width="26" height="26" />
                    </div>
                    <h2>Check your inbox</h2>
                    <p>We've sent your temporary password and username to your email address. Use them to sign in.</p>
                    <Link href="/login" className="za-btn-primary" style={{ textDecoration: 'none' }}>
                        <Icon icon="lucide:arrow-left" width="18" height="18" />
                        Go to sign in
                    </Link>
                </div>
            ) : (
                <>
                    <div className="za-form-head">
                        <h1>Create your account</h1>
                        <p>Get started with Zephyrus operations.</p>
                    </div>

                    {(errors.name || errors.email || errors.phone) && (
                        <div className="za-alert za-alert-err">
                            <Icon icon="lucide:alert-circle" width="16" height="16" />
                            <div>
                                {errors.name && <div>{errors.name}</div>}
                                {errors.email && <div>{errors.email}</div>}
                                {errors.phone && <div>{errors.phone}</div>}
                            </div>
                        </div>
                    )}

                    <form onSubmit={submit}>
                        <AuthField
                            id="name" label="Full name" icon="lucide:user"
                            value={data.name} onChange={(v) => setData('name', v)}
                            placeholder="Jane Doe" autoComplete="name" autoFocus required
                        />
                        <AuthField
                            id="email" label="Email address" icon="lucide:mail" type="email"
                            value={data.email} onChange={(v) => setData('email', v)}
                            placeholder="you@hospital.org" autoComplete="email" required
                        />
                        <AuthField
                            id="phone" label="Phone number" icon="lucide:phone" type="tel" optional
                            value={data.phone} onChange={(v) => setData('phone', v)}
                            placeholder="(555) 555-5555" autoComplete="tel"
                        />

                        <button type="submit" className="za-btn-primary" disabled={processing}>
                            {processing ? 'Creating account…' : 'Create account'}
                            {!processing && <Icon icon="lucide:arrow-right" width="18" height="18" />}
                        </button>

                        <p className="za-create">Already have an account? <Link href="/login">Sign in</Link></p>
                    </form>
                </>
            )}
        </AuthLayout>
    );
}
