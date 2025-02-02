import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import CaseList from '@/Components/Cases/CaseList';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';

const Cases = () => {
    return (
        <DashboardLayout>
            <Head title="OR Cases - ZephyrusOR" />
            <PageContentLayout
                title="Cases"
                subtitle="View and manage surgical cases"
            >
                <CaseList />
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default Cases;
