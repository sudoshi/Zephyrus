import React from 'react';
import { Icon } from '@iconify/react';

const BedTypeBreakdown = ({ bedTypes }) => {
    const getStatusColor = (occupancy) => {
        if (occupancy >= 90) return 'text-healthcare-critical dark:text-healthcare-critical-dark';
        if (occupancy >= 80) return 'text-healthcare-warning dark:text-healthcare-warning-dark';
        return 'text-healthcare-success dark:text-healthcare-success-dark';
    };

    const getBedTypeIcon = (type) => {
        switch (type) {
            case 'icu':
                return 'heroicons:heart';
            case 'medSurg':
                return 'heroicons:user-group';
            case 'telemetry':
                return 'heroicons:signal';
            case 'pediatric':
                return 'heroicons:face-smile';
            case 'maternity':
                return 'heroicons:users';
            default:
                return 'heroicons:home';
        }
    };

    const formatBedTypeName = (type) => {
        const names = {
            icu: 'ICU',
            medSurg: 'Med/Surg',
            telemetry: 'Telemetry',
            pediatric: 'Pediatric',
            maternity: 'Maternity'
        };
        return names[type] || type;
    };

    return (
        <div className="space-y-4">
            {Object.entries(bedTypes).map(([type, data]) => {
                const occupancy = Math.round((data.occupied / data.total) * 100);
                return (
                    <div key={type} className="flex items-center justify-between">
                        <div className="flex items-center space-x-3">
                            <div className="w-8 h-8 flex items-center justify-center rounded-lg bg-healthcare-background-alt dark:bg-healthcare-background-alt-dark">
                                <Icon icon={getBedTypeIcon(type)} className="w-5 h-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                            </div>
                            <div>
                                <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                                    {formatBedTypeName(type)}
                                </div>
                                <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {data.occupied}/{data.total} beds
                                </div>
                            </div>
                        </div>
                        <div className="text-right">
                            <div className={`font-medium ${getStatusColor(occupancy)}`}>
                                {occupancy}%
                            </div>
                            {data.pending > 0 && (
                                <div className="text-sm text-healthcare-warning dark:text-healthcare-warning-dark">
                                    +{data.pending} pending
                                </div>
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
};

export default BedTypeBreakdown;
