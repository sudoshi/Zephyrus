import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const HuddleStatus = ({ status }) => {
    const getStatusColor = () => {
        switch (status) {
            case 'critical': return 'bg-healthcare-critical text-white';
            case 'warning': return 'bg-healthcare-warning text-white';
            case 'stable': return 'bg-healthcare-success text-white';
            default: return 'bg-healthcare-text-tertiary text-white';
        }
    };

    return (
        <div className={`px-2 py-1 rounded text-xs font-medium ${getStatusColor()}`}>
            {status.toUpperCase()}
        </div>
    );
};

const CapacityHuddleTracker = () => {
    // Sample data - this would come from the API/mock data in real implementation
    const huddles = [
        {
            id: 1,
            unit: 'ICU',
            status: 'critical',
            currentOccupancy: '95%',
            predictedDemand: 4,
            availableBeds: 1,
            redStretchPlan: {
                actions: [
                    'Expedite 2 step-downs to medical floor',
                    'Review 3 potential early discharges',
                    'Coordinate with ED for incoming critical patients'
                ],
                responsibleParty: 'Dr. Johnson',
                deadline: '10:30 AM'
            }
        },
        {
            id: 2,
            unit: 'Medical Floor',
            status: 'warning',
            currentOccupancy: '88%',
            predictedDemand: 6,
            availableBeds: 3,
            redStretchPlan: {
                actions: [
                    'Prioritize 4 pending discharges',
                    'Coordinate with case management for placement',
                    'Review bed assignments for optimization'
                ],
                responsibleParty: 'Charge RN Smith',
                deadline: '11:00 AM'
            }
        }
    ];

    return (
        <Card>
            <Card.Header>
                <Card.Title>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center space-x-2">
                            <Icon icon="heroicons:users-group" className="w-5 h-5" />
                            <span>Capacity Huddle Tracker</span>
                        </div>
                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            8 AM - 2 PM Window
                        </div>
                    </div>
                </Card.Title>
            </Card.Header>
            <Card.Content>
                <div className="space-y-6">
                    {huddles.map(huddle => (
                        <div 
                            key={huddle.id}
                            className="p-4 rounded-lg bg-healthcare-background dark:bg-healthcare-background-dark"
                        >
                            <div className="flex items-center justify-between mb-4">
                                <div className="flex items-center space-x-3">
                                    <HuddleStatus status={huddle.status} />
                                    <span className="text-lg font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        {huddle.unit}
                                    </span>
                                </div>
                                <div className="flex items-center space-x-6">
                                    <div className="text-center">
                                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Occupancy
                                        </div>
                                        <div className="text-lg font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            {huddle.currentOccupancy}
                                        </div>
                                    </div>
                                    <div className="text-center">
                                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Predicted Demand
                                        </div>
                                        <div className="text-lg font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            +{huddle.predictedDemand}
                                        </div>
                                    </div>
                                    <div className="text-center">
                                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Available
                                        </div>
                                        <div className="text-lg font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                            {huddle.availableBeds}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="border-t border-healthcare-border dark:border-healthcare-border-dark pt-4">
                                <div className="flex items-center space-x-2 mb-3">
                                    <Icon icon="heroicons:exclamation-triangle" className="w-5 h-5 text-healthcare-warning" />
                                    <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                        Red Stretch Plan
                                    </h4>
                                </div>
                                <ul className="list-disc list-inside space-y-2 mb-3 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {huddle.redStretchPlan.actions.map((action, index) => (
                                        <li key={index}>{action}</li>
                                    ))}
                                </ul>
                                <div className="flex items-center justify-between text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    <div>
                                        Responsible: {huddle.redStretchPlan.responsibleParty}
                                    </div>
                                    <div>
                                        Deadline: {huddle.redStretchPlan.deadline}
                                    </div>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </Card.Content>
        </Card>
    );
};

export default CapacityHuddleTracker;
