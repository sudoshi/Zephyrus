import React from 'react';
import type { ReactNode } from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';

interface HomePageLayoutProps {
    title: string;
    subtitle?: string;
    headerContent?: ReactNode;
    children: ReactNode;
}

const HomePageLayout = ({ title, subtitle, headerContent = null, children }: HomePageLayoutProps) => {
    return (
        <DashboardLayout>
            <Head title={`${title} - Home Hospital`} />
            <PageContentLayout title={title} subtitle={subtitle} headerContent={headerContent}>
                <div className="space-y-4">
                    {children}
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default HomePageLayout;
