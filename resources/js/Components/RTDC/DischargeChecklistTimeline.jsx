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
                return 'bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400';
            case 'discharge':
                return 'bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-400';
            default:
                return 'bg-indigo-100 dark:bg-indigo-900 text-indigo-600 dark:text-indigo-400';
        }
    };

    const TimelineItem = ({ milestone, isLast }) => (
        <div className="relative pb-8">
            {!isLast && (
                <span
                    className="absolute top-5 left-5 -ml-px h-full w-0.5 bg-gray-200 dark:bg-gray-700"
                    aria-hidden="true"
                />
            )}
            <div className="relative flex items-start space-x-3">
                <div className={`relative ${milestone.isAlert ? 'animate-pulse' : ''}`}>
                    <div className={`h-10 w-10 rounded-full flex items-center justify-center ring-8 ring-white dark:ring-gray-800 ${getTypeColor(milestone.type)}`}>
                        <Icon icon={getTypeIcon(milestone.type)} className="h-5 w-5" />
                    </div>
                </div>
                <div className="min-w-0 flex-1">
                    <div>
                        <div className="text-sm">
                            <span className="font-medium text-gray-900 dark:text-gray-100">
                                {milestone.title}
                            </span>
                            {milestone.isAnticipated && (
                                <span className="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200">
                                    Anticipated
                                </span>
                            )}
                        </div>
                        <p className="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                            {milestone.date} at {milestone.time}
                        </p>
                    </div>
                    <div className="mt-2 text-sm text-gray-700 dark:text-gray-300">
                        <p>{milestone.description}</p>
                    </div>
                    {milestone.isAlert && (
                        <div className="mt-2">
                            <span className="inline-flex items-center gap-1 text-sm text-red-600 dark:text-red-400">
                                <Icon icon="heroicons:exclamation-circle" className="h-4 w-4" />
                                Requires attention
                            </span>
                        </div>
                    )}
                </div>
                <div className="flex-shrink-0 self-center">
                    <button className="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
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
                    <ul className="-mb-8">
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
