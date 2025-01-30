import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import CaseList from '@/Components/Cases/CaseList';
import { Head } from '@inertiajs/react';

const Cases = () => {
    return (
        <DashboardLayout>
            <Head title="OR Cases - ZephyrusOR" />
            <div className="p-6">
                <CaseList />
            </div>
        </DashboardLayout>
    );
};

export default Cases;
