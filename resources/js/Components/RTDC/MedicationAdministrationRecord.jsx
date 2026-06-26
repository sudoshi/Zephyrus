import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const MedicationAdministrationRecord = ({ medications }) => {
    if (!medications?.length) return null;

    const getRouteIcon = (route) => {
        switch (route) {
            case 'PO':
                return 'heroicons:pill';
            case 'IV':
                return 'heroicons:beaker';
            case 'IM':
                return 'heroicons:syringe';
            case 'SC':
                return 'heroicons:arrow-down-circle';
            default:
                return 'heroicons:variable';
        }
    };

    const getRouteColor = (route) => {
        switch (route) {
            case 'PO':
                return 'text-healthcare-info dark:text-healthcare-info-dark bg-healthcare-info/10 dark:bg-healthcare-info-dark/20';
            case 'IV':
                return 'text-healthcare-success dark:text-healthcare-success-dark bg-healthcare-success/10 dark:bg-healthcare-success-dark/20';
            case 'IM':
                return 'text-purple-600 dark:text-purple-400 bg-purple-100 dark:bg-purple-900';
            case 'SC':
                return 'text-healthcare-warning dark:text-healthcare-warning-dark bg-healthcare-warning/10 dark:bg-healthcare-warning-dark/20';
            default:
                return 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark bg-healthcare-background dark:bg-healthcare-background-dark';
        }
    };

    const MedicationItem = ({ med }) => (
        <div className="flex items-center justify-between p-4 border-b border-healthcare-border dark:border-healthcare-border-dark last:border-0">
            <div className="flex items-center space-x-4">
                <div className={`p-2 rounded-full ${getRouteColor(med.route)}`}>
                    <Icon icon={getRouteIcon(med.route)} className="w-5 h-5" />
                </div>
                <div>
                    <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {med.medication}
                    </div>
                    <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {med.dose} - {med.route}
                    </div>
                </div>
            </div>
            <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {new Date(med.time).toLocaleTimeString()}
            </div>
        </div>
    );

    return (
        <Card>
            <Card.Header>
                <div className="flex justify-between items-center">
                    <Card.Title>Recent Medications</Card.Title>
                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        Last 24 hours
                    </span>
                </div>
            </Card.Header>
            <Card.Content>
                <div className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                    {medications
                        .sort((a, b) => new Date(b.time) - new Date(a.time))
                        .map((med, idx) => (
                            <MedicationItem key={idx} med={med} />
                        ))}
                </div>
                {medications.length === 0 && (
                    <div className="text-center py-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        No medications administered in the last 24 hours
                    </div>
                )}
            </Card.Content>
        </Card>
    );
};

export default MedicationAdministrationRecord;
