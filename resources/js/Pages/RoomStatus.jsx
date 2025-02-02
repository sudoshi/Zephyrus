import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import RoomStatusBoard from '@/Components/RoomStatus/RoomStatusBoard';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';

const RoomStatus = () => {
    return (
        <DashboardLayout>
            <Head title="Room Status - ZephyrusOR" />
            <PageContentLayout
                title="Operating Room Status"
                subtitle="Real-time status and progress of all operating rooms"
            >
                <RoomStatusBoard />
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default RoomStatus;
