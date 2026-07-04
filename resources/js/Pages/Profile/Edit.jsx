import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({ mustVerifyEmail, status }) {
    return (
        <DashboardLayout>
            <Head title="Profile" />

            <PageContentLayout title="Profile">
                <div className="space-y-4">
                    <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 shadow-sm sm:rounded-lg sm:p-6">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                            className="max-w-xl"
                        />
                    </div>

                    <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 shadow-sm sm:rounded-lg sm:p-6">
                        <UpdatePasswordForm className="max-w-xl" />
                    </div>

                    <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 shadow-sm sm:rounded-lg sm:p-6">
                        <DeleteUserForm className="max-w-xl" />
                    </div>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
}
