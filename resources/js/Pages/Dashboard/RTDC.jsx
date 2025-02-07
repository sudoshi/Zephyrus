import React, { useState } from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';
import { Icon } from '@iconify/react';
import SimpleTrendChart from '@/Components/Analytics/Common/SimpleTrendChart';
import CompactTabPanel from '@/Components/RTDC/CompactTabPanel';
import EnhancedDepartmentMetrics from '@/Components/RTDC/EnhancedDepartmentMetrics';
import HistoricalMetricsSection from '@/Components/RTDC/HistoricalMetrics/HistoricalMetricsSection';
import { departmentData } from '@/mock-data/rtdc';
import { alertsData } from '@/mock-data/rtdc-alerts';
import { capacityData } from '@/mock-data/rtdc-capacity';
import { staffingData } from '@/mock-data/rtdc-staffing';

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
                {/* Overview Panel */}
                <CompactTabPanel 
                    alerts={alertsData.active}
                    alertStats={alertsData.statistics}
                    bedTypes={capacityData.bedTypes}
                    staffingData={staffingData}
                />

                {/* Enhanced Department Metrics */}
                <div className="mt-4">
                    <EnhancedDepartmentMetrics departments={departmentData} />
                </div>

                {/* Historical Metrics */}
                <div className="mt-4">
                    <HistoricalMetricsSection />
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default RTDCDashboard;
