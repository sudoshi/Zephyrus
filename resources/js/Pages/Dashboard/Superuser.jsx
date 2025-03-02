import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import DashboardOverview from '@/Components/Dashboard/DashboardOverview';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';

const SuperuserDashboard = () => {
    return (
        <DashboardLayout>
            <Head title="Superuser Dashboard - Zephyrus" />
            <PageContentLayout
                title="Superuser Dashboard"
                subtitle="Complete access to all system modules and workflows"
            >
                <DashboardOverview />
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default SuperuserDashboard;
