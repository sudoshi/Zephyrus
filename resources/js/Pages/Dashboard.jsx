import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import DashboardOverview from '@/Components/Dashboard/DashboardOverview';
import { Head } from '@inertiajs/react';

const Dashboard = () => {
    return (
        <DashboardLayout>
            <Head title="Dashboard - ZephyrusOR" />
            <DashboardOverview />
        </DashboardLayout>
    );
};

export default Dashboard;
