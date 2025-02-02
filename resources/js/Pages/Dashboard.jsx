import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import DashboardOverview from '@/Components/Dashboard/DashboardOverview';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';

const Dashboard = () => {
    return (
        <DashboardLayout>
            <Head title="Dashboard - ZephyrusOR" />
                <PageContentLayout
                    title="Dashboard"
                    subtitle="Overview of surgical services metrics"
                >
                    <DashboardOverview />
                </PageContentLayout>
        </DashboardLayout>
    );
};

export default Dashboard;
