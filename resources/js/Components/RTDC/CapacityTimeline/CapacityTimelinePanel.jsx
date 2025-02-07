import React, { useState } from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import DemandCapacityModel from './DemandCapacityModel';
import DischargeTracker from './DischargeTracker';
import CapacityHuddleTracker from './CapacityHuddleTracker';
import ExecutiveMetrics from './ExecutiveMetrics';

const TimeSelector = ({ selectedTime, onTimeChange }) => {
    const times = [
        { id: 'overnight', label: '2 PM - 8 AM', icon: 'heroicons:moon' },
        { id: 'morning', label: '8 AM - 2 PM', icon: 'heroicons:sun' },
        { id: 'report', label: '2 PM Report', icon: 'heroicons:document-chart-bar' }
    ];

    return (
        <div className="flex space-x-2 mb-6">
            {times.map(time => (
                <button
                    key={time.id}
                    onClick={() => onTimeChange(time.id)}
                    className={`flex items-center px-4 py-2 rounded-lg text-sm font-medium transition-colors
                        ${selectedTime === time.id 
                            ? 'bg-healthcare-primary text-white dark:bg-healthcare-primary-dark'
                            : 'bg-healthcare-background hover:bg-healthcare-background/80 text-healthcare-text-primary dark:bg-healthcare-background-dark dark:hover:bg-healthcare-background-dark/80 dark:text-healthcare-text-primary-dark'
                        }`}
                >
                    <Icon icon={time.icon} className="w-4 h-4 mr-2" />
                    {time.label}
                </button>
            ))}
        </div>
    );
};

const CapacityTimelinePanel = () => {
    const [selectedTime, setSelectedTime] = useState('overnight');

    const renderContent = () => {
        switch (selectedTime) {
            case 'overnight':
                return (
                    <div className="space-y-6">
                        <DemandCapacityModel />
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <DischargeTracker />
                            <div className="space-y-6">
                                <Card>
                                    <Card.Header>
                                        <Card.Title>
                                            <div className="flex items-center space-x-2">
                                                <Icon icon="heroicons:user-group" className="w-5 h-5" />
                                                <span>Staffing Adjustments</span>
                                            </div>
                                        </Card.Title>
                                    </Card.Header>
                                    <Card.Content>
                                        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Staffing adjustment recommendations coming soon
                                        </p>
                                    </Card.Content>
                                </Card>
                                <Card>
                                    <Card.Header>
                                        <Card.Title>
                                            <div className="flex items-center space-x-2">
                                                <Icon icon="heroicons:truck" className="w-5 h-5" />
                                                <span>Ancillary Services Priority</span>
                                            </div>
                                        </Card.Title>
                                    </Card.Header>
                                    <Card.Content>
                                        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            Service prioritization view coming soon
                                        </p>
                                    </Card.Content>
                                </Card>
                            </div>
                        </div>
                    </div>
                );
            case 'morning':
                return (
                    <div className="space-y-6">
                        <DemandCapacityModel />
                        <CapacityHuddleTracker />
                    </div>
                );
            case 'report':
                return <ExecutiveMetrics />;
            default:
                return null;
        }
    };

    return (
        <div>
            <TimeSelector selectedTime={selectedTime} onTimeChange={setSelectedTime} />
            {renderContent()}
        </div>
    );
};

export default CapacityTimelinePanel;
