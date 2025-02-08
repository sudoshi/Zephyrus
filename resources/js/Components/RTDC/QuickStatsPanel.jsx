import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const QuickStatsPanel = ({ patient }) => {
    if (!patient) return null;

    const calculateLOS = (admitDate) => {
        const admit = new Date(admitDate);
        const now = new Date();
        const diffTime = Math.abs(now - admit);
        const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));
        const diffHours = Math.floor((diffTime % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        return { days: diffDays, hours: diffHours };
    };

    const los = calculateLOS(patient.admitDate);

    const StatItem = ({ icon, label, value }) => (
        <div className="flex items-center gap-3 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <div className="p-2 bg-indigo-100 dark:bg-indigo-900 rounded-full">
                <Icon icon={icon} className="w-5 h-5 text-indigo-600 dark:text-indigo-400" />
            </div>
            <div>
                <div className="text-sm text-gray-500 dark:text-gray-400">{label}</div>
                <div className="font-semibold text-gray-900 dark:text-gray-100">{value}</div>
            </div>
        </div>
    );

    return (
        <Card>
            <Card.Header>
                <Card.Title>Quick Stats</Card.Title>
            </Card.Header>
            <Card.Content>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <StatItem
                        icon="heroicons:clock"
                        label="Length of Stay"
                        value={`${los.days}d ${los.hours}h`}
                    />
                    <StatItem
                        icon="heroicons:calendar"
                        label="Admission Date"
                        value={new Date(patient.admitDate).toLocaleDateString()}
                    />
                    {patient.clinicalStatus.isolationStatus && (
                        <StatItem
                            icon="heroicons:exclamation-triangle"
                            label="Isolation Status"
                            value={patient.clinicalStatus.isolationStatus}
                        />
                    )}
                    <StatItem
                        icon="heroicons:heart"
                        label="Care Level"
                        value={patient.careJourney.careLevel}
                    />
                </div>
            </Card.Content>
        </Card>
    );
};

export default QuickStatsPanel;
