import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import BlockScheduleManager from '@/Components/BlockSchedule/BlockScheduleManager';
import { Head } from '@inertiajs/react';

const BlockSchedule = () => {
    return (
        <DashboardLayout>
            <Head title="Block Schedule - ZephyrusOR" />
            <BlockScheduleManager />
        </DashboardLayout>
    );
};

export default BlockSchedule;
