import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import DashboardOverview from '@/Components/Dashboard/DashboardOverview';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';

const PerioperativeDashboard = ({ overview }) => {
    return (
        <DashboardLayout>
            <Head title="Perioperative Dashboard - Zephyrus" />
            <PageContentLayout
                title="Perioperative"
                subtitle="Overview of surgical services metrics"
            >
                <DashboardOverview overview={overview} />
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default PerioperativeDashboard;
