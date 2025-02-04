import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import DashboardOverview from '@/Components/Dashboard/DashboardOverview';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';

const ORDashboard = () => {
    return (
        <DashboardLayout>
            <Head title="OR Dashboard - ZephyrusOR" />
            <PageContentLayout
                title="Operating Room"
                subtitle="Overview of surgical services metrics"
            >
                <DashboardOverview />
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default ORDashboard;
