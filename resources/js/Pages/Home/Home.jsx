import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import Card from '@/Components/Dashboard/Card';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import Progress from '@/Components/ui/progress'; // Update import statement to use default import
import { useDashboard } from '@/Contexts/DashboardContext';

// Mock data for demonstration
const mockData = {
    bottlenecks: [
        { id: 1, hospital: 'Marlton Hospital', department: 'ED', issue: 'CT Scanner Availability', impact: 89, affectedPatients: 45, trend: 'increasing' },
        { id: 2, hospital: 'Mount Holly Hospital', department: 'PACU', issue: 'Staffing Shortage', impact: 82, affectedPatients: 28, trend: 'stable' },
        { id: 3, hospital: 'Our Lady of Lourdes Hospital', department: 'OR', issue: 'Room Turnover Time', impact: 76, affectedPatients: 32, trend: 'decreasing' },
        { id: 4, hospital: 'Voorhees Hospital', department: 'ICU', issue: 'Bed Availability', impact: 71, affectedPatients: 15, trend: 'increasing' },
        { id: 5, hospital: 'Willingboro Hospital', department: 'Med/Surg', issue: 'Discharge Delays', impact: 68, affectedPatients: 52, trend: 'stable' },
    ],
    pdsaCycles: [
        { id: 1, title: 'ED Flow Optimization', status: 'Study', progress: 75, dueDate: '2025-03-15', priority: 'high' },
        { id: 2, title: 'PACU Handoff Process', status: 'Do', progress: 45, dueDate: '2025-03-01', priority: 'medium' },
        { id: 3, title: 'OR Schedule Optimization', status: 'Plan', progress: 20, dueDate: '2025-03-30', priority: 'high' },
        { id: 4, title: 'ICU Capacity Management', status: 'Act', progress: 90, dueDate: '2025-02-28', priority: 'medium' },
    ],
    dischargePriority: [
        { id: 1, patient: 'Smith, John', room: '4A-123', hospital: 'Marlton', priority: 'Critical', barrier: 'Transportation', timeReported: '14:35' },
        { id: 2, patient: 'Johnson, Mary', room: '3B-234', hospital: 'Mount Holly', priority: 'High', barrier: 'Home Care Setup', timeReported: '14:42' },
        { id: 3, patient: 'Williams, Robert', room: '5C-345', hospital: 'Voorhees', priority: 'Medium', barrier: 'Medication Reconciliation', timeReported: '14:15' },
        { id: 4, patient: 'Brown, Patricia', room: '2A-456', hospital: 'Willingboro', priority: 'High', barrier: 'Pending Test Results', timeReported: '14:28' },
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
            return 'text-yellow-600 bg-yellow-100 dark:text-yellow-400 dark:bg-yellow-900';
        case 'do':
            return 'text-blue-600 bg-blue-100 dark:text-blue-400 dark:bg-blue-900';
        case 'study':
            return 'text-purple-600 bg-purple-100 dark:text-purple-400 dark:bg-purple-900';
        case 'act':
            return 'text-green-600 bg-green-100 dark:text-green-400 dark:bg-green-900';
        default:
            return 'text-gray-600 bg-gray-100 dark:text-gray-400 dark:bg-gray-900';
    }
};

const getPriorityColor = (priority) => {
    switch (priority.toLowerCase()) {
        case 'critical':
            return 'text-red-600 bg-red-100 dark:bg-red-900';
        case 'high':
            return 'text-orange-600 bg-orange-100 dark:bg-orange-900';
        case 'medium':
            return 'text-yellow-600 bg-yellow-100 dark:bg-yellow-900';
        default:
            return 'text-green-600 bg-green-100 dark:bg-green-900';
    }
};

const Home = () => {
    return (
        <DashboardLayout>
            <Head title="Home - Zephyrus" />
            <PageContentLayout
                title="System Overview"
                subtitle="Cross-facility performance metrics and priorities"
            >
                <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">
                    {/* Top Bottlenecks */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:exclamation-triangle" className="w-5 h-5 text-healthcare-warning" />
                                    <span>Top Bottlenecks</span>
                                </div>
                            </Card.Title>
                            <Card.Description>System-wide bottlenecks across 5 hospitals (Last 7 days)</Card.Description>
                        </Card.Header>
                        <Card.Content>
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
                        </Card.Content>
                    </Card>

                    {/* Active PDSA Cycles */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:arrow-path" className="w-5 h-5 text-healthcare-primary" />
                                    <span>Active PDSA Cycles</span>
                                </div>
                            </Card.Title>
                            <Card.Description>Current improvement initiatives in progress</Card.Description>
                        </Card.Header>
                        <Card.Content>
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
                        </Card.Content>
                    </Card>

                    {/* Discharge Prioritization */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:home" className="w-5 h-5 text-healthcare-success" />
                                    <span>Discharge Prioritization</span>
                                </div>
                            </Card.Title>
                            <Card.Description>High-priority discharges reported after 2 PM yesterday</Card.Description>
                        </Card.Header>
                        <Card.Content>
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
                        </Card.Content>
                    </Card>

                    {/* Improvement Impact */}
                    <Card>
                        <Card.Header>
                            <Card.Title>
                                <div className="flex items-center space-x-2">
                                    <Icon icon="heroicons:chart-bar" className="w-5 h-5 text-healthcare-primary" />
                                    <span>Improvement Impact</span>
                                </div>
                            </Card.Title>
                            <Card.Description>Active improvement initiatives and their impact scores</Card.Description>
                        </Card.Header>
                        <Card.Content>
                            <div className="space-y-4">
                                {mockData.improvements.map(improvement => (
                                    <div key={improvement.id} className="p-4 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg">
                                        <div className="flex items-center justify-between mb-2">
                                            <div>
                                                <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{improvement.title}</h4>
                                                <div className="flex items-center space-x-4 mt-1">
                                                    <span className="text-sm">Impact Score: {improvement.impact}</span>
                                                    <span className={`text-xs px-2 py-1 rounded-full ${
                                                        improvement.status === 'On Track' ? 'text-green-600 bg-green-100 dark:bg-green-900' :
                                                        improvement.status === 'At Risk' ? 'text-yellow-600 bg-yellow-100 dark:bg-yellow-900' :
                                                        'text-blue-600 bg-blue-100 dark:bg-blue-900'
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
                        </Card.Content>
                    </Card>
                </div>
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default Home;
