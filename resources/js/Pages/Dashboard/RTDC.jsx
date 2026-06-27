import React, { useState } from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';
import { Section } from '@/Components/system';
import CompactTabPanel from '@/Components/RTDC/CompactTabPanel';
import EnhancedDepartmentMetrics from '@/Components/RTDC/EnhancedDepartmentMetrics';
import HistoricalMetricsSection from '@/Components/RTDC/HistoricalMetrics/HistoricalMetricsSection';
import { departmentData as mockDepartmentData } from '@/mock-data/rtdc';
import { alertsData as mockAlertsData } from '@/mock-data/rtdc-alerts';
import { capacityData as mockCapacityData } from '@/mock-data/rtdc-capacity';
import { staffingData as mockStaffingData } from '@/mock-data/rtdc-staffing';

// RTDC workflow dashboard rebuilt on the gold-standard design system: each block
// is grouped under a shared Section header (icon + title + summary), preserving
// the live-data child widgets (CompactTabPanel, EnhancedDepartmentMetrics,
// HistoricalMetricsSection) and the DashboardLayout/PageContentLayout wrapper.
const RTDCDashboard = ({
    departmentData = mockDepartmentData,
    alertsData = mockAlertsData,
    capacityData = mockCapacityData,
    staffingData = mockStaffingData,
}) => {
    // eslint-disable-next-line no-unused-vars
    const [selectedTimeframe, setSelectedTimeframe] = useState('day');
    const departmentCount = Object.keys(departmentData ?? {}).length;

    return (
        <DashboardLayout>
            <Head title="RTDC Dashboard - ZephyrusOR" />
            <PageContentLayout
                title="Real Time Demand & Capacity"
                subtitle="Hospital-wide demand and capacity metrics"
            >
                <div className="flex flex-col gap-5">
                    <Section
                        title="System status"
                        icon="heroicons:signal"
                        summary={`${alertsData.active.length} active alerts · capacity & staffing at a glance`}
                    >
                        <CompactTabPanel
                            alerts={alertsData.active}
                            alertStats={alertsData.statistics}
                            bedTypes={capacityData.bedTypes}
                            staffingData={staffingData}
                        />
                    </Section>

                    <Section
                        title="Department metrics"
                        icon="heroicons:building-office-2"
                        summary={`Occupancy, staffing & flow across ${departmentCount} departments`}
                    >
                        <EnhancedDepartmentMetrics departments={departmentData} />
                    </Section>

                    <Section
                        title="Historical metrics"
                        icon="heroicons:presentation-chart-line"
                        summary="Trailing throughput and capacity trends"
                    >
                        <HistoricalMetricsSection />
                    </Section>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default RTDCDashboard;
