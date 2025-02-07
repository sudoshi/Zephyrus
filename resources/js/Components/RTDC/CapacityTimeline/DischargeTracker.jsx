import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const MilestoneStatus = ({ status }) => {
    const getStatusColor = () => {
        switch (status) {
            case 'completed': return 'text-healthcare-success';
            case 'in_progress': return 'text-healthcare-warning';
            case 'pending': return 'text-healthcare-text-tertiary';
            case 'blocked': return 'text-healthcare-critical';
            default: return 'text-healthcare-text-tertiary';
        }
    };

    const getStatusIcon = () => {
        switch (status) {
            case 'completed': return 'heroicons:check-circle';
            case 'in_progress': return 'heroicons:clock';
            case 'pending': return 'heroicons:circle';
            case 'blocked': return 'heroicons:exclamation-circle';
            default: return 'heroicons:circle';
        }
    };

    return (
        <Icon 
            icon={getStatusIcon()} 
            className={`w-5 h-5 ${getStatusColor()}`}
        />
    );
};

const DischargeTracker = () => {
    // Sample data - this would come from the API/mock data in real implementation
    const discharges = [
        {
            id: 1,
            unit: '5E',
            room: '5E-12',
            priority: 'high',
            expectedTime: '10:00 AM',
            milestones: [
                { id: 1, name: 'MD Order', status: 'completed' },
                { id: 2, name: 'Case Mgmt', status: 'completed' },
                { id: 3, name: 'Transport', status: 'in_progress' },
                { id: 4, name: 'Pharmacy', status: 'pending' },
                { id: 5, name: 'Final Check', status: 'pending' }
            ],
            notes: 'Telemetry discharge, coordinating with cardiology'
        },
        {
            id: 2,
            unit: 'ICU',
            room: 'ICU-04',
            priority: 'high',
            expectedTime: '11:30 AM',
            milestones: [
                { id: 1, name: 'MD Order', status: 'completed' },
                { id: 2, name: 'Case Mgmt', status: 'in_progress' },
                { id: 3, name: 'Transport', status: 'blocked' },
                { id: 4, name: 'Pharmacy', status: 'pending' },
                { id: 5, name: 'Final Check', status: 'pending' }
            ],
            notes: 'Step-down to medical floor pending bed availability'
        }
    ];

    return (
        <Card>
            <Card.Header>
                <Card.Title>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:arrow-right-circle" className="w-5 h-5" />
                            <span>Prioritized Discharges</span>
                        </div>
                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {discharges.length} tracked
                        </div>
                    </div>
                </Card.Title>
            </Card.Header>
            <Card.Content>
                <div className="space-y-4">
                    {discharges.map(discharge => (
                        <div 
                            key={discharge.id}
                            className="p-4 rounded-lg bg-healthcare-background dark:bg-healthcare-background-dark"
                        >
                            <div className="flex items-center justify-between mb-3">
                                <div className="flex items-center space-x-3">
                                    <div className={`px-2 py-1 rounded text-xs font-medium
                                        ${discharge.priority === 'high' 
                                            ? 'bg-healthcare-critical/10 text-healthcare-critical'
                                            : 'bg-healthcare-warning/10 text-healthcare-warning'
                                        }`}
                                    >
                                        {discharge.priority.toUpperCase()}
                                    </div>
                                    <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {discharge.unit} • {discharge.room}
                                    </span>
                                </div>
                                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    Expected: {discharge.expectedTime}
                                </span>
                            </div>
                            <div className="flex items-center justify-between mb-3">
                                {discharge.milestones.map((milestone, index) => (
                                    <div key={milestone.id} className="flex flex-col items-center">
                                        <MilestoneStatus status={milestone.status} />
                                        <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                                            {milestone.name}
                                        </span>
                                        {index < discharge.milestones.length - 1 && (
                                            <div className="absolute w-8 h-0.5 bg-healthcare-border dark:bg-healthcare-border-dark translate-x-10" />
                                        )}
                                    </div>
                                ))}
                            </div>
                            {discharge.notes && (
                                <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {discharge.notes}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </Card.Content>
        </Card>
    );
};

export default DischargeTracker;
