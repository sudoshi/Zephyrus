import React from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Construction } from 'lucide-react';

const EDPlaceholder = ({ title, subtitle }) => {
    return (
        <DashboardLayout>
            <Head title={`${title} - Emergency`} />
            <PageContentLayout title={title} subtitle={subtitle}>
                <div className="flex flex-col items-center justify-center rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark py-10 text-center shadow-sm transition-colors duration-300">
                    <div className="mb-4 rounded-full bg-healthcare-primary/10 dark:bg-healthcare-primary-dark/10 p-4">
                        <Construction className="h-8 w-8 text-healthcare-primary dark:text-healthcare-primary-dark" />
                    </div>
                    <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {title}
                    </h3>
                    <p className="mt-2 max-w-md text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        This Emergency Department view is coming soon.
                    </p>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default EDPlaceholder;
