import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const CareTeamTimeline = ({ teamCommunication }) => {
    if (!teamCommunication?.length) return null;

    const getActivityIcon = (category) => {
        switch (category) {
            case 'Clinical':
                return 'heroicons:heart';
            case 'Care Coordination':
                return 'heroicons:users';
            case 'Family Communication':
                return 'heroicons:chat-bubble-left-right';
            default:
                return 'heroicons:document-text';
        }
    };

    const TimelineItem = ({ activity }) => (
        <div className="relative pb-8">
            <div className="relative flex items-start space-x-3">
                <div className="relative">
                    <div className="h-8 w-8 rounded-full bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center ring-8 ring-white dark:ring-gray-800">
                        <Icon 
                            icon={getActivityIcon(activity.category)} 
                            className="h-5 w-5 text-indigo-600 dark:text-indigo-400" 
                        />
                    </div>
                </div>
                <div className="min-w-0 flex-1">
                    <div>
                        <div className="text-sm">
                            <span className="font-medium text-gray-900 dark:text-gray-100">
                                {activity.author}
                            </span>
                        </div>
                        <p className="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                            {new Date(activity.timestamp).toLocaleString()}
                        </p>
                    </div>
                    <div className="mt-2 text-sm text-gray-700 dark:text-gray-300">
                        <p>{activity.message}</p>
                    </div>
                    <div className="mt-2">
                        <span className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-indigo-100 dark:bg-indigo-900 text-indigo-800 dark:text-indigo-200">
                            {activity.category}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    );

    return (
        <Card>
            <Card.Header>
                <Card.Title>Care Team Activity</Card.Title>
            </Card.Header>
            <Card.Content>
                <div className="flow-root">
                    <ul className="-mb-8">
                        {teamCommunication
                            .sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp))
                            .map((activity, idx) => (
                                <li key={activity.id || idx}>
                                    <TimelineItem activity={activity} />
                                </li>
                            ))}
                    </ul>
                </div>
            </Card.Content>
        </Card>
    );
};

export default CareTeamTimeline;
