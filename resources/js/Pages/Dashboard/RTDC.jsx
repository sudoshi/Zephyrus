import React, { useState } from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';
import { Icon } from '@iconify/react';
import SimpleTrendChart from '@/Components/Analytics/Common/SimpleTrendChart';
import CompactAlerts from '@/Components/RTDC/CompactAlerts';
import CompactCapacityOverview from '@/Components/RTDC/CompactCapacityOverview';
import CompactStaffingOverview from '@/Components/RTDC/CompactStaffingOverview';
import EnhancedDepartmentMetrics from '@/Components/RTDC/EnhancedDepartmentMetrics';
import HistoricalMetricsSection from '@/Components/RTDC/HistoricalMetrics/HistoricalMetricsSection';
import { censusData, departmentData, staffingData, alertsData } from '@/mock-data/rtdc';

const RTDCDashboard = () => {
    const [selectedTimeframe, setSelectedTimeframe] = useState('day');
    const [expandedSections, setExpandedSections] = useState({
        census: true,
        capacity: true,
        staffing: true,
    });

    return (
        <DashboardLayout>
            <Head title="RTDC Dashboard - ZephyrusOR" />
            <PageContentLayout
                title="Real Time Demand & Capacity"
                subtitle="Hospital-wide demand and capacity metrics"
            >
                {/* Top Panels */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    {/* Alerts Panel */}
                    <div>
                        <CompactAlerts alerts={alertsData.active} statistics={alertsData.statistics} />
                    </div>

                    {/* Capacity Overview Panel */}
                    <div>
                        <CompactCapacityOverview bedTypes={censusData.total.bedTypes} />
                    </div>

                    {/* Staffing Overview Panel */}
                    <div>
                        <CompactStaffingOverview staffingData={staffingData} />
                    </div>
                </div>

                {/* Enhanced Department Metrics */}
                <div className="mt-8">
                    <EnhancedDepartmentMetrics departments={departmentData} />
                </div>

                {/* Historical Metrics */}
                <div className="mt-8">
                    <HistoricalMetricsSection />
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default RTDCDashboard;
