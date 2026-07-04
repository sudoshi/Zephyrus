import React from 'react';
import type { ReactNode } from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';

interface RTDCPageLayoutProps {
    title: string;
    subtitle?: string;
    headerContent?: ReactNode;
    children: ReactNode;
}

const RTDCPageLayout = ({ title, subtitle, headerContent = null, children }: RTDCPageLayoutProps) => {
    return (
        <DashboardLayout>
            <Head title={`${title} - RTDC`} />
            <PageContentLayout title={title} subtitle={subtitle} headerContent={headerContent}>
                <div className="space-y-4">
                    {children}
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default RTDCPageLayout;
