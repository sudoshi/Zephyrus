import React, { useState } from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head, Link } from '@inertiajs/react';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';
import { Icon } from '@iconify/react';
import TrendChart from '@/Components/Analytics/Common/TrendChart';
import DepartmentDetailsPanel from '@/Components/RTDC/DepartmentDetailsPanel';
import { censusData, departmentData, staffingData, alertsData } from '@/mock-data/rtdc';

const RTDCDashboard = () => {
    const [selectedDepartment, setSelectedDepartment] = useState(null);
    const departments = Object.values(departmentData);

    return (
        <DashboardLayout>
            <Head title="RTDC Dashboard - ZephyrusOR" />
            <PageContentLayout
                title="Real Time Demand & Capacity"
                subtitle="Hospital-wide demand and capacity metrics"
            >
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Census Overview */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:users" className="w-5 h-5" />
                                    <span>Census Overview</span>
                                </div>
                            </Card.Title>
                            <Card.Description>Current hospital census and capacity</Card.Description>
                        </Card.Header>
                        <Card.Content>
                            <MetricsCardGroup cols={2}>
                                <MetricsCard
                                    title="Total Census"
                                    value={censusData.total.currentCensus.toString()}
                                    trend={censusData.total.trend}
                                    trendValue={censusData.total.trendValue}
                                    icon="heroicons:building-office-2"
                                    description={`${censusData.total.occupancy}% occupancy`}
                                />
                                <MetricsCard
                                    title="Available Beds"
                                    value={(censusData.total.totalBeds - censusData.total.currentCensus).toString()}
                                    trend="down"
                                    trendValue={censusData.total.trendValue}
                                    icon="heroicons:home"
                                    description={`${100 - censusData.total.occupancy}% capacity`}
                                />
                            </MetricsCardGroup>
                            <div className="mt-6 h-48">
                                    <TrendChart
                                        data={censusData.weeklyTrend}
                                        series={[
                                            {
                                                dataKey: 'value',
                                                name: 'Occupancy',
                                            },
                                        ]}
                                        xAxis={{
                                            dataKey: 'date',
                                            type: 'category',
                                            formatter: (value) =>
                                                new Date(value).toLocaleDateString('en-US', {
                                                    month: 'short',
                                                    day: 'numeric',
                                                }),
                                        }}
                                        yAxis={{
                                            formatter: (value) => `${value}%`,
                                        }}
                                    />
                            </div>
                        </Card.Content>
                    </Card>

                    {/* Department Status */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:building-library" className="w-5 h-5" />
                                    <span>Department Status</span>
                                </div>
                            </Card.Title>
                            <Card.Description>Real-time department metrics</Card.Description>
                        </Card.Header>
                        <Card.Content>
                            <MetricsCardGroup cols={2}>
                                <MetricsCard
                                    title="ED Boarding"
                                    value={departmentData.emergency.boardingPatients.toString()}
                                    trend="up"
                                    trendValue={2}
                                    icon="heroicons:clock"
                                    description="Awaiting beds"
                                />
                                <MetricsCard
                                    title="ICU Capacity"
                                    value={`${departmentData.icu.occupancy}%`}
                                    trend="down"
                                    trendValue={5}
                                    icon="heroicons:heart"
                                    description={`${departmentData.icu.occupiedBeds}/${departmentData.icu.totalBeds} beds`}
                                />
                            </MetricsCardGroup>
                            <div className="mt-6 space-y-4">
                                {/* Department List */}
                                    {departments.map((dept) => (
                                        <div 
                                            key={dept.name}
                                            className={`flex items-center justify-between p-3 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg cursor-pointer transition-colors duration-300 hover:bg-healthcare-background-dark dark:hover:bg-healthcare-background ${
                                                selectedDepartment?.name === dept.name ? 'ring-2 ring-healthcare-primary dark:ring-healthcare-primary-dark' : ''
                                            }`}
                                            onClick={() => setSelectedDepartment(selectedDepartment?.name === dept.name ? null : dept)}
                                        >
                                            <div className="flex items-center space-x-3">
                                                <div className={`w-2 h-2 rounded-full ${
                                                    dept.status === 'critical' ? 'bg-healthcare-critical dark:bg-healthcare-critical-dark' :
                                                    dept.status === 'warning' ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark' :
                                                    'bg-healthcare-success dark:bg-healthcare-success-dark'
                                                }`} />
                                                <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {dept.name}
                                                </span>
                                            </div>
                                            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                {dept.occupancy}% Occupied
                                            </span>
                                        </div>
                                    ))}
                                {/* Department Details Panel */}
                                <DepartmentDetailsPanel
                                    department={selectedDepartment}
                                    onClose={() => setSelectedDepartment(null)}
                                />
                            </div>
                        </Card.Content>
                    </Card>

                    {/* Staffing Overview */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:user-group" className="w-5 h-5" />
                                    <span>Staffing Overview</span>
                                </div>
                            </Card.Title>
                            <Card.Description>Current staffing levels and requirements</Card.Description>
                        </Card.Header>
                        <Card.Content>
                            <MetricsCardGroup cols={2}>
                                <MetricsCard
                                    title="Current Staff"
                                    value={staffingData.currentShift.present.toString()}
                                    trend="down"
                                    trendValue={staffingData.currentShift.required - staffingData.currentShift.present}
                                    icon="heroicons:users"
                                    description={`${staffingData.currentShift.coverage}% coverage`}
                                />
                                <MetricsCard
                                    title="Required Staff"
                                    value={staffingData.currentShift.required.toString()}
                                    trend="up"
                                    trendValue={2}
                                    icon="heroicons:clipboard-document-check"
                                    description="Based on census"
                                />
                            </MetricsCardGroup>
                        </Card.Content>
                    </Card>

                    {/* Alerts & Notifications */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:bell-alert" className="w-5 h-5" />
                                    <span>Active Alerts</span>
                                </div>
                            </Card.Title>
                            <Card.Description>Critical notifications requiring attention</Card.Description>
                        </Card.Header>
                        <Card.Content>
                            <div className="space-y-4">
                                    {alertsData.active.slice(0, 2).map((alert) => (
                                    <div key={alert.title} className="flex items-start space-x-4 p-4 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                        <div className={`w-2 h-2 mt-2 rounded-full ${
                                            alert.priority === 'high' ? 'bg-healthcare-critical dark:bg-healthcare-critical-dark' :
                                            alert.priority === 'medium' ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark' :
                                            'bg-healthcare-success dark:bg-healthcare-success-dark'
                                        }`} />
                                        <div>
                                            <h4 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                {alert.title}
                                            </h4>
                                            <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                                                {alert.description}
                                            </p>
                                            <span className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark mt-2 block">
                                                {alert.time}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </Card.Content>
                    </Card>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default RTDCDashboard;
