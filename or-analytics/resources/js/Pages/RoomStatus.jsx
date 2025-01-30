import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import RoomStatusBoard from '@/Components/RoomStatus/RoomStatusBoard';
import { Head } from '@inertiajs/react';

const RoomStatus = () => {
    return (
        <DashboardLayout>
            <Head title="Room Status - ZephyrusOR" />
            <div className="p-6">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold">Operating Room Status</h1>
                    <p className="text-gray-500">Real-time status and progress of all operating rooms</p>
                </div>
                <RoomStatusBoard />
            </div>
        </DashboardLayout>
    );
};

export default RoomStatus;
