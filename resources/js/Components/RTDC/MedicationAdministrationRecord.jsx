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
                return 'text-blue-600 dark:text-blue-400 bg-blue-100 dark:bg-blue-900';
            case 'IV':
                return 'text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-900';
            case 'IM':
                return 'text-purple-600 dark:text-purple-400 bg-purple-100 dark:bg-purple-900';
            case 'SC':
                return 'text-orange-600 dark:text-orange-400 bg-orange-100 dark:bg-orange-900';
            default:
                return 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-900';
        }
    };

    const MedicationItem = ({ med }) => (
        <div className="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700 last:border-0">
            <div className="flex items-center space-x-4">
                <div className={`p-2 rounded-full ${getRouteColor(med.route)}`}>
                    <Icon icon={getRouteIcon(med.route)} className="w-5 h-5" />
                </div>
                <div>
                    <div className="font-medium text-gray-900 dark:text-gray-100">
                        {med.medication}
                    </div>
                    <div className="text-sm text-gray-500 dark:text-gray-400">
                        {med.dose} - {med.route}
                    </div>
                </div>
            </div>
            <div className="text-sm text-gray-500 dark:text-gray-400">
                {new Date(med.time).toLocaleTimeString()}
            </div>
        </div>
    );

    return (
        <Card>
            <Card.Header>
                <div className="flex justify-between items-center">
                    <Card.Title>Recent Medications</Card.Title>
                    <span className="text-sm text-gray-500 dark:text-gray-400">
                        Last 24 hours
                    </span>
                </div>
            </Card.Header>
            <Card.Content>
                <div className="divide-y divide-gray-200 dark:divide-gray-700">
                    {medications
                        .sort((a, b) => new Date(b.time) - new Date(a.time))
                        .map((med, idx) => (
                            <MedicationItem key={idx} med={med} />
                        ))}
                </div>
                {medications.length === 0 && (
                    <div className="text-center py-4 text-gray-500 dark:text-gray-400">
                        No medications administered in the last 24 hours
                    </div>
                )}
            </Card.Content>
        </Card>
    );
};

export default MedicationAdministrationRecord;
