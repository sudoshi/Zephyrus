import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';
import Card from '@/Components/Dashboard/Card';
import MetricsCard, { MetricsCardGroup } from '@/Components/Analytics/Common/MetricsCard';
import { Icon } from '@iconify/react';
import TrendChart from '@/Components/Analytics/Common/TrendChart';
import AlertsAndPredictions from '@/Components/ED/AlertsAndPredictions';
import ResourceManagement from '@/Components/ED/ResourceManagement';
import { edMetrics, performanceMetrics, patientStatusBoard, alertsData } from '@/mock-data/ed';

const EDDashboard = () => {
    return (
        <DashboardLayout>
            <Head title="ED Dashboard - ZephyrusOR" />
            <PageContentLayout
                title="Emergency Department"
                subtitle="Real-time ED operations and metrics"
            >
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Current Status */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:heart" className="w-5 h-5" />
                                    <span>Current Status</span>
                                </div>
                            </Card.Title>
                            <Card.Description>Real-time department metrics</Card.Description>
                        </Card.Header>
                        <Card.Content>
                            <MetricsCardGroup cols={2}>
                                <MetricsCard
                                    title="Total Patients"
                                    value={edMetrics.currentStatus.totalPatients.toString()}
                                    trend={edMetrics.currentStatus.totalPatients > edMetrics.currentStatus.capacity * 0.8 ? 'down' : 'up'}
                                    trendValue={edMetrics.currentStatus.occupancy}
                                    icon="heroicons:users"
                                    description={`${edMetrics.currentStatus.occupancy}% occupancy`}
                                />
                                <MetricsCard
                                    title="Waiting Room"
                                    value={edMetrics.currentStatus.waitingRoom.toString()}
                                    trend={edMetrics.currentStatus.waitingRoom > 10 ? 'down' : 'up'}
                                    trendValue={edMetrics.currentStatus.averageWaitTime}
                                    icon="heroicons:clock"
                                    description={`${edMetrics.currentStatus.averageWaitTime} min avg wait`}
                                />
                            </MetricsCardGroup>
                            <div className="mt-6">
                                <h4 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                                    Triage Categories
                                </h4>
                                <div className="space-y-3">
                                    {Object.entries(edMetrics.triageCategories).map(([category, data]) => (
                                        <div key={category} className="flex items-center justify-between p-3 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
                                            <div className="flex items-center space-x-3">
                                                <div className={`w-2 h-2 rounded-full ${
                                                    category === 'resuscitation' ? 'bg-healthcare-critical dark:bg-healthcare-critical-dark' :
                                                    category === 'emergent' ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark' :
                                                    'bg-healthcare-success dark:bg-healthcare-success-dark'
                                                }`} />
                                                <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark capitalize">
                                                    {category}
                                                </span>
                                            </div>
                                            <div className="flex items-center space-x-4">
                                                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                    {data.count} patients
                                                </span>
                                                <span className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark">
                                                    Target: {data.targetTime}
                                                </span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </Card.Content>
                    </Card>

                    {/* Performance Metrics */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:chart-bar" className="w-5 h-5" />
                                    <span>Performance Metrics</span>
                                </div>
                            </Card.Title>
                            <Card.Description>Key performance indicators</Card.Description>
                        </Card.Header>
                        <Card.Content>
                            <MetricsCardGroup cols={2}>
                                <MetricsCard
                                    title="Door to Provider"
                                    value={`${performanceMetrics.doorToProvider.current}min`}
                                    trend={performanceMetrics.doorToProvider.trend}
                                    trendValue={performanceMetrics.doorToProvider.trendValue}
                                    icon="heroicons:clock"
                                    description={`Target: ${performanceMetrics.doorToProvider.target}min`}
                                />
                                <MetricsCard
                                    title="Left Without Being Seen"
                                    value={`${performanceMetrics.leftWithoutBeingSeen.current}%`}
                                    trend={performanceMetrics.leftWithoutBeingSeen.trend}
                                    trendValue={performanceMetrics.leftWithoutBeingSeen.trendValue}
                                    icon="heroicons:arrow-right"
                                    description={`Target: ${performanceMetrics.leftWithoutBeingSeen.target}%`}
                                />
                            </MetricsCardGroup>
                            <div className="mt-6">
                                <h4 className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                                    Wait Time Trends
                                </h4>
                                <div className="h-48">
                                        <TrendChart
                                            data={edMetrics.waitTimes.trends}
                                            series={[
                                                {
                                                    dataKey: 'waitTime',
                                                    name: 'Wait Time',
                                                },
                                            ]}
                                            xAxis={{
                                                dataKey: 'hour',
                                                type: 'category',
                                            }}
                                        />
                                </div>
                            </div>
                        </Card.Content>
                    </Card>

                    {/* Patient Status Board */}
                    <Card className="lg:col-span-2">
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:clipboard-document-list" className="w-5 h-5" />
                                    <span>Patient Status Board</span>
                                </div>
                            </Card.Title>
                            <Card.Description>Active patient tracking</Card.Description>
                        </Card.Header>
                        <Card.Content>
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                    <thead>
                                        <tr>
                                            <th className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                Location
                                            </th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                Chief Complaint
                                            </th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                Triage Level
                                            </th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                Wait Time
                                            </th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                Next Action
                                            </th>
                                            <th className="px-4 py-3 text-left text-xs font-semibold text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                                Provider
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                                        {patientStatusBoard.map((patient) => (
                                            <tr key={patient.id}>
                                                <td className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {patient.location}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {patient.chiefComplaint}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                        patient.triageLevel <= 2 ? 'bg-healthcare-critical/20 text-healthcare-critical dark:text-healthcare-critical-dark' :
                                                        patient.triageLevel === 3 ? 'bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark' :
                                                        'bg-healthcare-success/20 text-healthcare-success dark:text-healthcare-success-dark'
                                                    }`}>
                                                        Level {patient.triageLevel}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {patient.waitTime} min
                                                </td>
                                                <td className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {patient.nextAction}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                                    {patient.provider}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </Card.Content>
                    </Card>

                    {/* Resource Management */}
                    <ResourceManagement resources={edMetrics.resources} />

                    {/* Alerts and Predictions */}
                    <AlertsAndPredictions
                        alerts={alertsData.alerts}
                        predictions={edMetrics.predictions}
                    />
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default EDDashboard;
