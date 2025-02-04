import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import { Head } from '@inertiajs/react';

const RTDCPageLayout = ({ title, subtitle, children }) => {
    return (
        <DashboardLayout>
            <Head title={`${title} - RTDC - ZephyrusOR`} />
            <PageContentLayout
                title={title}
                subtitle={subtitle}
            >
                <div className="space-y-6">
                    {children}
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default RTDCPageLayout;
