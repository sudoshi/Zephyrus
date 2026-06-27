import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const InterventionsChecklist = ({ interventions }) => {
    if (!interventions?.length) return null;

    const getStatusColor = (status) => {
        switch (status) {
            case 'Completed':
                return 'bg-healthcare-success/10 dark:bg-healthcare-success/20 text-healthcare-success dark:text-healthcare-success-dark';
            case 'Scheduled':
                return 'bg-healthcare-info/10 dark:bg-healthcare-info/20 text-healthcare-info dark:text-healthcare-info-dark';
            default:
                return 'bg-healthcare-warning/10 dark:bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark';
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
        <div className="flex items-start gap-4 p-4 border border-healthcare-border dark:border-healthcare-border-dark rounded-lg">
            <div className={`p-2 rounded-full ${getStatusColor(intervention.status)} bg-opacity-20`}>
                <Icon icon={getTypeIcon(intervention.type)} className="w-5 h-5" />
            </div>
            <div className="flex-1 min-w-0">
                <div className="flex justify-between items-start">
                    <div>
                        <h4 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                            {intervention.name}
                        </h4>
                        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                            {intervention.type}
                        </p>
                    </div>
                    <span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusColor(intervention.status)}`}>
                        {intervention.status}
                    </span>
                </div>
                <div className="mt-2 flex items-center gap-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    <Icon icon="heroicons:clock" className="w-4 h-4" />
                    {new Date(intervention.scheduledTime).toLocaleString()}
                </div>
                <div className="mt-3 flex items-center gap-3">
                    <button className="text-sm text-healthcare-primary dark:text-healthcare-primary-dark hover:opacity-80 flex items-center gap-1">
                        <Icon icon="heroicons:check-circle" className="w-4 h-4" />
                        Mark Complete
                    </button>
                    <button className="text-sm text-healthcare-primary dark:text-healthcare-primary-dark hover:opacity-80 flex items-center gap-1">
                        <Icon icon="heroicons:calendar" className="w-4 h-4" />
                        Reschedule
                    </button>
                    <button className="text-sm text-healthcare-primary dark:text-healthcare-primary-dark hover:opacity-80 flex items-center gap-1">
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
                    <button className="text-sm text-healthcare-primary dark:text-healthcare-primary-dark hover:opacity-80 flex items-center gap-1">
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
                    <div className="text-center py-6 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        No interventions scheduled
                    </div>
                )}
            </Card.Content>
        </Card>
    );
};

export default InterventionsChecklist;
