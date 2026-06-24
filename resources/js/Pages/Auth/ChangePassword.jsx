import { Head, useForm } from '@inertiajs/react';
import { Icon } from '@iconify/react';
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

            <div className="za-form-head">
                <div className="za-head-icon">
                    <Icon icon="lucide:shield-alert" width="22" height="22" />
                </div>
                <h1>Change your password</h1>
                <p>You must change your temporary password before continuing.</p>
            </div>

            {(errors.current_password || errors.new_password || errors.new_password_confirmation) && (
                <div className="za-alert za-alert-err">
                    <Icon icon="lucide:alert-circle" width="16" height="16" />
                    <div>
                        {errors.current_password && <div>{errors.current_password}</div>}
                        {errors.new_password && <div>{errors.new_password}</div>}
                        {errors.new_password_confirmation && <div>{errors.new_password_confirmation}</div>}
                    </div>
                </div>
            )}

            <form onSubmit={submit}>
                <AuthField
                    id="current_password" label="Current (temporary) password" icon="lucide:key" type="password" revealable
                    value={data.current_password} onChange={(v) => setData('current_password', v)}
                    placeholder="Temporary password" autoComplete="current-password" autoFocus required
                />
                <AuthField
                    id="new_password" label="New password" icon="lucide:lock" type="password" revealable
                    value={data.new_password} onChange={(v) => setData('new_password', v)}
                    placeholder="At least 8 characters" autoComplete="new-password" required
                />
                <AuthField
                    id="new_password_confirmation" label="Confirm new password" icon="lucide:lock-keyhole" type="password" revealable
                    value={data.new_password_confirmation} onChange={(v) => setData('new_password_confirmation', v)}
                    placeholder="Re-enter new password" autoComplete="new-password" required
                />

                <button type="submit" className="za-btn-primary" disabled={processing}>
                    {processing ? 'Updating…' : 'Update password'}
                    {!processing && <Icon icon="lucide:check" width="18" height="18" />}
                </button>
            </form>
        </AuthLayout>
    );
}
