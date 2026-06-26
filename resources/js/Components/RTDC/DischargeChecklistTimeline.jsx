import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const DischargeChecklistTimeline = ({ milestones }) => {
    if (!milestones?.length) return null;

    const getTypeIcon = (type) => {
        switch (type) {
            case 'admission':
                return 'heroicons:user-plus';
            case 'discharge':
                return 'heroicons:arrow-right-on-rectangle';
            default:
                return 'heroicons:flag';
        }
    };

    const getTypeColor = (type) => {
        switch (type) {
            case 'admission':
                return 'bg-healthcare-info/10 dark:bg-healthcare-info/20 text-healthcare-info dark:text-healthcare-info-dark';
            case 'discharge':
                return 'bg-healthcare-success/10 dark:bg-healthcare-success/20 text-healthcare-success dark:text-healthcare-success-dark';
            default:
                return 'bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-400';
        }
    };

    const TimelineItem = ({ milestone, isLast }) => (
        <div className="relative pb-8">
            {!isLast && (
                <span
                    className="absolute top-5 left-5 -ml-px h-full w-0.5 bg-healthcare-border dark:bg-healthcare-border-dark"
                    aria-hidden="true"
                />
            )}
            <div className="relative flex items-start space-x-3">
                <div className={`relative ${milestone.isAlert ? 'animate-pulse' : ''}`}>
                    <div className={`h-10 w-10 rounded-full flex items-center justify-center ring-8 ring-healthcare-surface dark:ring-healthcare-surface-dark ${getTypeColor(milestone.type)}`}>
                        <Icon icon={getTypeIcon(milestone.type)} className="h-5 w-5" />
                    </div>
                </div>
                <div className="min-w-0 flex-1">
                    <div>
                        <div className="text-sm">
                            <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                {milestone.title}
                            </span>
                            {milestone.isAnticipated && (
                                <span className="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-healthcare-warning/10 dark:bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark">
                                    Anticipated
                                </span>
                            )}
                        </div>
                        <p className="mt-0.5 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {milestone.date} at {milestone.time}
                        </p>
                    </div>
                    <div className="mt-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        <p>{milestone.description}</p>
                    </div>
                    {milestone.isAlert && (
                        <div className="mt-2">
                            <span className="inline-flex items-center gap-1 text-sm text-healthcare-critical dark:text-healthcare-critical-dark">
                                <Icon icon="heroicons:exclamation-circle" className="h-4 w-4" />
                                Requires attention
                            </span>
                        </div>
                    )}
                </div>
                <div className="flex-shrink-0 self-center">
                    <button className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark">
                        <Icon icon="heroicons:pencil-square" className="h-5 w-5" />
                    </button>
                </div>
            </div>
        </div>
    );

    return (
        <Card>
            <Card.Header>
                <div className="flex justify-between items-center">
                    <Card.Title>Discharge Timeline</Card.Title>
                    <button className="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1">
                        <Icon icon="heroicons:plus" className="w-4 h-4" />
                        Add Milestone
                    </button>
                </div>
            </Card.Header>
            <Card.Content>
                <div className="flow-root">
                    <ul className="-mb-6">
                        {milestones.map((milestone, idx) => (
                            <li key={milestone.id}>
                                <TimelineItem 
                                    milestone={milestone} 
                                    isLast={idx === milestones.length - 1} 
                                />
                            </li>
                        ))}
                    </ul>
                </div>
            </Card.Content>
        </Card>
    );
};

export default DischargeChecklistTimeline;
