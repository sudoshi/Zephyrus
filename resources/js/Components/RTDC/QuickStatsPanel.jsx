import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';
import { formatDurationSeconds } from '@/lib/duration';

const QuickStatsPanel = ({ patient }) => {
    if (!patient) return null;

    const calculateLOS = (admitDate) => {
        const admit = new Date(admitDate);
        const now = new Date();
        return formatDurationSeconds(Math.abs(now - admit) / 1000);
    };

    const los = calculateLOS(patient.admitDate);

    const StatItem = ({ icon, label, value }) => (
        <div className="flex items-center gap-3 p-3 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
            <div className="p-2 bg-healthcare-primary/10 dark:bg-healthcare-primary-dark/20 rounded-full">
                <Icon icon={icon} className="w-5 h-5 text-healthcare-primary dark:text-healthcare-primary-dark" />
            </div>
            <div>
                <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</div>
                <div className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</div>
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
                        value={los}
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
