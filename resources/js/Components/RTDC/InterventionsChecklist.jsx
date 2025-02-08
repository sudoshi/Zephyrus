import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const InterventionsChecklist = ({ interventions }) => {
    if (!interventions?.length) return null;

    const getStatusColor = (status) => {
        switch (status) {
            case 'Completed':
                return 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200';
            case 'Scheduled':
                return 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200';
            default:
                return 'bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200';
        }
    };

    const getTypeIcon = (type) => {
        switch (type) {
            case 'Procedure':
                return 'heroicons:heart';
            case 'Therapy':
                return 'heroicons:hand-raised';
            case 'Consultation':
                return 'heroicons:user-group';
            default:
                return 'heroicons:clipboard-document-list';
        }
    };

    const InterventionItem = ({ intervention }) => (
        <div className="flex items-start gap-4 p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
            <div className={`p-2 rounded-full ${getStatusColor(intervention.status)} bg-opacity-20`}>
                <Icon icon={getTypeIcon(intervention.type)} className="w-5 h-5" />
            </div>
            <div className="flex-1 min-w-0">
                <div className="flex justify-between items-start">
                    <div>
                        <h4 className="font-medium text-gray-900 dark:text-gray-100">
                            {intervention.name}
                        </h4>
                        <p className="text-sm text-gray-500 dark:text-gray-400">
                            {intervention.type}
                        </p>
                    </div>
                    <span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusColor(intervention.status)}`}>
                        {intervention.status}
                    </span>
                </div>
                <div className="mt-2 flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <Icon icon="heroicons:clock" className="w-4 h-4" />
                    {new Date(intervention.scheduledTime).toLocaleString()}
                </div>
                <div className="mt-3 flex items-center gap-3">
                    <button className="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1">
                        <Icon icon="heroicons:check-circle" className="w-4 h-4" />
                        Mark Complete
                    </button>
                    <button className="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1">
                        <Icon icon="heroicons:calendar" className="w-4 h-4" />
                        Reschedule
                    </button>
                    <button className="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1">
                        <Icon icon="heroicons:chat-bubble-left-ellipsis" className="w-4 h-4" />
                        Add Note
                    </button>
                </div>
            </div>
        </div>
    );

    return (
        <Card>
            <Card.Header>
                <div className="flex justify-between items-center">
                    <Card.Title>Interventions & Procedures</Card.Title>
                    <button className="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1">
                        <Icon icon="heroicons:plus" className="w-4 h-4" />
                        Add Intervention
                    </button>
                </div>
            </Card.Header>
            <Card.Content>
                <div className="space-y-4">
                    {interventions.map((intervention, index) => (
                        <InterventionItem key={index} intervention={intervention} />
                    ))}
                </div>
                {interventions.length === 0 && (
                    <div className="text-center py-6 text-gray-500 dark:text-gray-400">
                        No interventions scheduled
                    </div>
                )}
            </Card.Content>
        </Card>
    );
};

export default InterventionsChecklist;
