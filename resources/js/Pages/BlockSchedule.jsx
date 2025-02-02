import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import BlockScheduleManager from '@/Components/BlockSchedule/BlockScheduleManager';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';

const BlockSchedule = () => {
    return (
        <DashboardLayout>
            <Head title="Block Schedule - ZephyrusOR" />
            <PageContentLayout
                title="Block Schedule"
                subtitle="Manage operating room block allocations"
            >
                <BlockScheduleManager />
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default BlockSchedule;
