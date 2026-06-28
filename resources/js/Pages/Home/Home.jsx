import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import Progress from '@/Components/ui/progress'; // Update import statement to use default import
import { Section, MetricGrid, Panel, metric } from '@/Components/system';
import TabNavigation from '@/Components/ui/TabNavigation';
import { useState } from 'react';
import { NETWORK_FACILITY_NAMES } from '@/constants/summitHospital';

const [
    FACILITY_1,
    FACILITY_2,
    FACILITY_3,
    FACILITY_4,
    FACILITY_5,
] = NETWORK_FACILITY_NAMES;

// Mock data for demonstration
const mockData = {
    bottlenecks: [
        { id: 1, hospital: FACILITY_1, department: 'ED', issue: 'CT Scanner Availability', impact: 89, affectedPatients: 45, trend: 'increasing' },
        { id: 2, hospital: FACILITY_2, department: 'PACU', issue: 'Staffing Shortage', impact: 82, affectedPatients: 28, trend: 'stable' },
        { id: 3, hospital: FACILITY_3, department: 'OR', issue: 'Room Turnover Time', impact: 76, affectedPatients: 32, trend: 'decreasing' },
        { id: 4, hospital: FACILITY_4, department: 'ICU', issue: 'Bed Availability', impact: 71, affectedPatients: 15, trend: 'increasing' },
        { id: 5, hospital: FACILITY_5, department: 'Med/Surg', issue: 'Discharge Delays', impact: 68, affectedPatients: 52, trend: 'stable' },
    ],
    pdsaCycles: [
        { id: 1, title: 'ED Flow Optimization', status: 'Study', progress: 75, dueDate: '2025-03-15', priority: 'high' },
        { id: 2, title: 'PACU Handoff Process', status: 'Do', progress: 45, dueDate: '2025-03-01', priority: 'medium' },
        { id: 3, title: 'OR Schedule Optimization', status: 'Plan', progress: 20, dueDate: '2025-03-30', priority: 'high' },
        { id: 4, title: 'ICU Capacity Management', status: 'Act', progress: 90, dueDate: '2025-02-28', priority: 'medium' },
    ],
    dischargePriority: [
        { id: 1, patient: 'Smith, John', room: '4A-123', hospital: FACILITY_1, priority: 'Critical', barrier: 'Transportation', timeReported: '14:35' },
        { id: 2, patient: 'Johnson, Mary', room: '3B-234', hospital: FACILITY_2, priority: 'High', barrier: 'Home Care Setup', timeReported: '14:42' },
        { id: 3, patient: 'Williams, Robert', room: '5C-345', hospital: FACILITY_4, priority: 'Medium', barrier: 'Medication Reconciliation', timeReported: '14:15' },
        { id: 4, patient: 'Brown, Patricia', room: '2A-456', hospital: FACILITY_5, priority: 'High', barrier: 'Pending Test Results', timeReported: '14:28' },
    ],
    improvements: [
        { id: 1, title: 'ED Wait Time Reduction', impact: 92, components: { patientSatisfaction: 95, efficiency: 88, quality: 94, cost: 91 }, status: 'On Track' },
        { id: 2, title: 'OR Turnover Time', impact: 87, components: { patientSatisfaction: 85, efficiency: 90, quality: 86, cost: 88 }, status: 'At Risk' },
        { id: 3, title: 'Discharge by Noon', impact: 78, components: { patientSatisfaction: 82, efficiency: 75, quality: 80, cost: 76 }, status: 'On Track' },
        { id: 4, title: 'Medication Reconciliation', impact: 85, components: { patientSatisfaction: 88, efficiency: 82, quality: 89, cost: 81 }, status: 'Complete' },
    ]
};

const getTrendIcon = (trend) => {
    switch (trend) {
        case 'increasing':
            return <Icon icon="heroicons:arrow-trending-up" className="w-5 h-5 text-healthcare-warning" />;
        case 'decreasing':
            return <Icon icon="heroicons:arrow-trending-down" className="w-5 h-5 text-healthcare-success" />;
        default:
            return <Icon icon="heroicons:minus" className="w-5 h-5 text-healthcare-text-secondary" />;
    }
};

const getStatusColor = (status) => {
    switch (status.toLowerCase()) {
        case 'plan':
            return 'text-healthcare-warning bg-healthcare-warning/10 dark:text-healthcare-warning-dark dark:bg-healthcare-warning-dark/20';
        case 'do':
            return 'text-healthcare-info bg-healthcare-info/10 dark:text-healthcare-info-dark dark:bg-healthcare-info-dark/20';
        case 'study':
            return 'text-purple-600 bg-purple-100 dark:text-purple-400 dark:bg-purple-900';
        case 'act':
            return 'text-healthcare-success bg-healthcare-success/10 dark:text-healthcare-success-dark dark:bg-healthcare-success-dark/20';
        default:
            return 'text-healthcare-text-secondary bg-healthcare-background dark:text-healthcare-text-secondary-dark dark:bg-healthcare-background-dark';
    }
};

const getPriorityColor = (priority) => {
    switch (priority.toLowerCase()) {
        case 'critical':
            return 'text-healthcare-critical bg-healthcare-critical/10 dark:text-healthcare-critical-dark dark:bg-healthcare-critical-dark/20';
        case 'high':
            return 'text-healthcare-warning bg-healthcare-warning/10 dark:text-healthcare-warning-dark dark:bg-healthcare-warning-dark/20';
        case 'medium':
            return 'text-healthcare-warning bg-healthcare-warning/10 dark:text-healthcare-warning-dark dark:bg-healthcare-warning-dark/20';
        default:
            return 'text-healthcare-success bg-healthcare-success/10 dark:text-healthcare-success-dark dark:bg-healthcare-success-dark/20';
    }
};

const Home = ({ workflow }) => {
    const pageTitle = workflow === 'superuser' ? 'SUPERUSER Dashboard' : 'System Overview';
    const pageSubtitle = workflow === 'superuser' ? 'Complete access to all system modules and workflows' : 'Cross-facility performance metrics and priorities';

    // Tab navigation for SUPERUSER dashboard
    const [activeTab, setActiveTab] = useState('overview');

    const menuGroups = [
        {
            title: 'Dashboard Views',
            items: [
                { id: 'overview', label: 'Overview', icon: 'carbon:dashboard' },
                { id: 'analytics', label: 'Analytics', icon: 'carbon:analytics' },
                { id: 'administration', label: 'Administration', icon: 'carbon:settings' },
                { id: 'reports', label: 'Reports', icon: 'carbon:report' },
            ]
        }
    ];

    const handleTabChange = (tabId) => {
        setActiveTab(tabId);
    };

    const systemMetrics = [
        metric({ key: 'total-facilities', label: 'Total Facilities', value: 5, status: 'info',
            definition: 'Hospitals connected to the Zephyrus command center.' }),
        metric({ key: 'active-users', label: 'Active Users', value: 128, status: 'success',
            definition: 'Users currently signed in across all facilities.' }),
        metric({ key: 'system-alerts', label: 'System Alerts', value: 3, status: 'warning',
            definition: 'Open system-level alerts requiring attention.' }),
        metric({ key: 'system-health', label: 'System Health', value: 98, unit: '%', status: 'success',
            definition: 'Composite platform health across services.' }),
    ];

    return (
        <DashboardLayout>
            <Head title={`${pageTitle} - Zephyrus`} />
            <PageContentLayout
                title={pageTitle}
                subtitle={pageSubtitle}
            >
                <div className="flex flex-col gap-5">
                {workflow === 'superuser' && (
                    <>
                    <div>
                        <TabNavigation
                            menuGroups={menuGroups}
                            activeTab={activeTab}
                            onTabChange={handleTabChange}
                        />
                    </div>
                    {activeTab === 'overview' && (
                        <>
                        <Section title="System Status" icon="heroicons:server-stack"
                                 summary="Platform-wide facility, user, alert, and health snapshot">
                            <MetricGrid metrics={systemMetrics} />
                        </Section>

                        <Section title="Quick Access" icon="heroicons:key"
                                 summary="Navigate to specific workflow dashboards">
                            <Panel className="p-4">
                                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                                    {[
                                        { name: 'RTDC', icon: 'heroicons:command-line', color: 'text-healthcare-primary', href: '/dashboard/rtdc' },
                                        { name: 'PERIOPERATIVE', icon: 'heroicons:heart', color: 'text-healthcare-success', href: '/dashboard/perioperative' },
                                        { name: 'EMERGENCY', icon: 'heroicons:exclamation-triangle', color: 'text-healthcare-warning', href: '/dashboard/emergency' },
                                        { name: 'IMPROVEMENT', icon: 'heroicons:arrow-trending-up', color: 'text-healthcare-info', href: '/dashboard/improvement' }
                                    ].map((item) => (
                                        <a
                                            key={item.name}
                                            href={item.href}
                                            className="flex flex-col items-center justify-center p-6 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg border border-healthcare-border dark:border-healthcare-border-dark hover:bg-healthcare-hover dark:hover:bg-healthcare-hover-dark transition-all duration-300"
                                        >
                                            <Icon icon={item.icon} className={`w-10 h-10 ${item.color} mb-3`} />
                                            <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{item.name}</span>
                                        </a>
                                    ))}
                                </div>
                            </Panel>
                        </Section>

                        <div className="grid grid-cols-1 xl:grid-cols-2 gap-5">
                            <Section title="Recent Activity" icon="heroicons:clock"
                                     summary="System-wide activity feed">
                                <Panel className="p-4">
                                    <div className="space-y-4">
                                        {[
                                            { user: 'Admin User', action: 'Updated system settings', time: '10 minutes ago', icon: 'heroicons:cog-6-tooth', color: 'text-healthcare-primary' },
                                            { user: 'Dr. Patel', action: 'Logged into RTDC workflow', time: '25 minutes ago', icon: 'heroicons:user', color: 'text-healthcare-success' },
                                            { user: 'System', action: 'Completed daily data refresh', time: '1 hour ago', icon: 'heroicons:arrow-path', color: 'text-healthcare-info' },
                                            { user: 'Jane Doe', action: 'Generated monthly report', time: '3 hours ago', icon: 'heroicons:document-text', color: 'text-healthcare-warning' },
                                            { user: 'System', action: 'Backup completed successfully', time: '6 hours ago', icon: 'heroicons:server', color: 'text-healthcare-primary' },
                                        ].map((item, index) => (
                                            <div key={index} className="flex items-start space-x-3 p-3 rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark">
                                                <div className={`p-2 rounded-full ${item.color} bg-opacity-10`}>
                                                    <Icon icon={item.icon} className={`w-5 h-5 ${item.color}`} />
                                                </div>
                                                <div className="flex-1">
                                                    <div className="flex justify-between items-start">
                                                        <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{item.user}</span>
                                                        <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.time}</span>
                                                    </div>
                                                    <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{item.action}</p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </Panel>
                            </Section>

                            <Section title="System Alerts" icon="heroicons:bell-alert"
                                     summary="Recent system alerts and notifications">
                                <Panel className="p-4">
                                    <div className="space-y-4">
                                        {[
                                            { title: 'Database Performance', message: 'Database query performance degraded in the last hour', severity: 'warning', time: '45 minutes ago' },
                                            { title: 'API Rate Limit', message: 'External API rate limit approaching threshold', severity: 'info', time: '2 hours ago' },
                                            { title: 'User Access', message: 'Multiple failed login attempts detected', severity: 'error', time: '3 hours ago' },
                                        ].map((alert, index) => {
                                            const severityColors = {
                                                info: 'text-healthcare-info border-healthcare-info',
                                                warning: 'text-healthcare-warning border-healthcare-warning',
                                                error: 'text-healthcare-danger border-healthcare-danger',
                                                success: 'text-healthcare-success border-healthcare-success',
                                            };
                                            const severityIcons = {
                                                info: 'heroicons:information-circle',
                                                warning: 'heroicons:exclamation-triangle',
                                                error: 'heroicons:exclamation-circle',
                                                success: 'heroicons:check-circle',
                                            };
                                            return (
                                                <div key={index} className={`p-4 border-l-4 ${severityColors[alert.severity]} rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark`}>
                                                    <div className="flex justify-between items-start mb-2">
                                                        <div className="flex items-center space-x-2">
                                                            <Icon icon={severityIcons[alert.severity]} className={`${severityIcons[alert.severity] === 'heroicons:information-circle' ? 'w-10 h-10' : 'w-5 h-5'} ${severityColors[alert.severity].split(' ')[0]}`} />
                                                            <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{alert.title}</span>
                                                        </div>
                                                        <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{alert.time}</span>
                                                    </div>
                                                    <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{alert.message}</p>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </Panel>
                            </Section>
                        </div>
                        </>
                    )}

                    {activeTab === 'analytics' && (
                        <Section title="System Analytics" icon="heroicons:chart-bar"
                                 summary="System-wide analytics and metrics">
                            <Panel className="p-4">
                                <div className="p-6 text-center">
                                    <Icon icon="heroicons:chart-bar" className="w-16 h-16 text-healthcare-primary mx-auto mb-4" />
                                    <h3 className="text-xl font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">Analytics Dashboard</h3>
                                    <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">Comprehensive analytics across all workflows and facilities</p>
                                </div>
                            </Panel>
                        </Section>
                    )}

                    {activeTab === 'administration' && (
                        <Section title="System Administration" icon="heroicons:cog-6-tooth"
                                 summary="Manage system settings and user access">
                            <Panel className="p-4">
                                <div className="p-6 text-center">
                                    <Icon icon="heroicons:cog-6-tooth" className="w-16 h-16 text-healthcare-primary mx-auto mb-4" />
                                    <h3 className="text-xl font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">Administration Panel</h3>
                                    <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">Configure system settings and manage user permissions</p>
                                </div>
                            </Panel>
                        </Section>
                    )}

                    {activeTab === 'reports' && (
                        <Section title="System Reports" icon="heroicons:document-text"
                                 summary="Generate and view system reports">
                            <Panel className="p-4">
                                <div className="p-6 text-center">
                                    <Icon icon="heroicons:document-text" className="w-16 h-16 text-healthcare-primary mx-auto mb-4" />
                                    <h3 className="text-xl font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">Reports Dashboard</h3>
                                    <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">Access and generate reports across all workflows and facilities</p>
                                </div>
                            </Panel>
                        </Section>
                    )}
                    </>
                )}


                <div className="grid grid-cols-1 xl:grid-cols-2 gap-5">
                    {/* Top Bottlenecks */}
                    <Section title="Top Bottlenecks" icon="heroicons:exclamation-triangle"
                             summary="System-wide bottlenecks across 5 hospitals (Last 7 days)">
                        <Panel className="p-4">
                            <div className="space-y-4">
                                {mockData.bottlenecks.map(bottleneck => (
                                    <div key={bottleneck.id} className="p-4 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg">
                                        <div className="flex items-center justify-between mb-2">
                                            <div>
                                                <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{bottleneck.issue}</h4>
                                                <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{bottleneck.hospital} - {bottleneck.department}</p>
                                            </div>
                                            <div className="flex items-center space-x-4">
                                                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{bottleneck.affectedPatients} patients affected</span>
                                                {getTrendIcon(bottleneck.trend)}
                                            </div>
                                        </div>
                                        <Progress value={bottleneck.impact} className="h-2" />
                                    </div>
                                ))}
                            </div>
                        </Panel>
                    </Section>

                    {/* Active PDSA Cycles */}
                    <Section title="Active PDSA Cycles" icon="heroicons:arrow-path"
                             summary="Current improvement initiatives in progress">
                        <Panel className="p-4">
                            <div className="space-y-4">
                                {mockData.pdsaCycles.map(cycle => (
                                    <div key={cycle.id} className="p-4 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg">
                                        <div className="flex items-center justify-between mb-2">
                                            <div>
                                                <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{cycle.title}</h4>
                                                <div className="flex items-center space-x-2 mt-1">
                                                    <span className={`text-xs px-2 py-1 rounded-full ${getStatusColor(cycle.status)}`}>{cycle.status}</span>
                                                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Due {new Date(cycle.dueDate).toLocaleDateString()}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <Progress value={cycle.progress} className="h-2" />
                                    </div>
                                ))}
                            </div>
                        </Panel>
                    </Section>

                    {/* Discharge Prioritization */}
                    <Section title="Discharge Prioritization" icon="heroicons:home"
                             summary="High-priority discharges reported after 2 PM yesterday">
                        <Panel className="p-4">
                            <div className="space-y-4">
                                {mockData.dischargePriority.map(discharge => (
                                    <div key={discharge.id} className="p-4 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg">
                                        <div className="flex items-center justify-between">
                                            <div>
                                                <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{discharge.patient}</h4>
                                                <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{discharge.hospital} - Room {discharge.room}</p>
                                            </div>
                                            <span className={`text-xs px-2 py-1 rounded-full ${getPriorityColor(discharge.priority)}`}>{discharge.priority}</span>
                                        </div>
                                        <div className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            <span>Barrier: {discharge.barrier}</span>
                                            <span className="ml-4">Reported: {discharge.timeReported}</span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </Panel>
                    </Section>

                    {/* Improvement Impact */}
                    <Section title="Improvement Impact" icon="heroicons:chart-bar"
                             summary="Active improvement initiatives and their impact scores">
                        <Panel className="p-4">
                            <div className="space-y-4">
                                {mockData.improvements.map(improvement => (
                                    <div key={improvement.id} className="p-4 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg">
                                        <div className="flex items-center justify-between mb-2">
                                            <div>
                                                <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{improvement.title}</h4>
                                                <div className="flex items-center space-x-4 mt-1">
                                                    <span className="text-sm">Impact Score: {improvement.impact}</span>
                                                    <span className={`text-xs px-2 py-1 rounded-full ${
                                                        improvement.status === 'On Track' ? 'text-healthcare-success bg-healthcare-success/10 dark:text-healthcare-success-dark dark:bg-healthcare-success-dark/20' :
                                                        improvement.status === 'At Risk' ? 'text-healthcare-warning bg-healthcare-warning/10 dark:text-healthcare-warning-dark dark:bg-healthcare-warning-dark/20' :
                                                        'text-healthcare-info bg-healthcare-info/10 dark:text-healthcare-info-dark dark:bg-healthcare-info-dark/20'
                                                    }`}>{improvement.status}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-4 gap-2 mt-2">
                                            {Object.entries(improvement.components).map(([key, value]) => (
                                                <div key={key} className="text-center">
                                                    <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark capitalize">{key}</div>
                                                    <Progress value={value} className="h-1 mt-1" />
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </Panel>
                    </Section>
                </div>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default Home;
