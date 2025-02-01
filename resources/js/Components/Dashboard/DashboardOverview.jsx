import React from 'react';
import LastMonthSection from './LastMonthSection';
import MonthToDateSection from './MonthToDateSection';

const DashboardOverview = () => {
    return (
        <div className="min-h-screen bg-gray-50">
            <div className="max-w-[1600px] mx-auto p-6 space-y-8">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold text-gray-900">OR Manager Home</h1>
                    <div className="flex items-center space-x-4">
                        <span className="text-sm text-gray-500">Location (My Locations)</span>
                        <span className="text-sm text-gray-500">Service</span>
                        <span className="text-sm text-gray-500">Surgeon</span>
                    </div>
                </div>

                {/* Main Content */}
                <div className="space-y-8">
                    <LastMonthSection />
                    <MonthToDateSection />
                </div>
            </div>
        </div>
    );
};

export default DashboardOverview;
