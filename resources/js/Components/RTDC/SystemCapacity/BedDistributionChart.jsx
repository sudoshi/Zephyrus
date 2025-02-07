import React from 'react';
import { Icon } from '@iconify/react';
import { useDarkMode } from '@/hooks/useDarkMode';

const BedDistributionChart = ({ distribution }) => {
    const [isDarkMode] = useDarkMode();

    // Determine critical states
    const isLowCapacity = distribution.zeroBeds > 3;
    const hasLimitedBeds = distribution.oneBed < 2;

    // Tooltips
    const tooltips = {
        zeroBeds: "Units with no available beds - may indicate capacity constraints",
        oneBed: "Units with exactly one bed available - limited flexibility",
        twoPlusBeds: "Units with two or more beds - optimal capacity"
    };

    const getStatusColor = (type) => {
        switch (type) {
            case 'zeroBeds':
                return isLowCapacity 
                    ? 'text-healthcare-critical dark:text-healthcare-critical-dark' 
                    : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark';
            case 'oneBed':
                return hasLimitedBeds
                    ? 'text-healthcare-warning dark:text-healthcare-warning-dark'
                    : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark';
            case 'twoPlusBeds':
                return 'text-healthcare-success dark:text-healthcare-success-dark';
            default:
                return 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark';
        }
    };

    const getStatusPattern = (type) => {
        switch (type) {
            case 'zeroBeds':
                return '■'; // Square for zero beds
            case 'oneBed':
                return '▲'; // Triangle for one bed
            case 'twoPlusBeds':
                return '●'; // Circle for two plus beds
            default:
                return '';
        }
    };

    const DistributionBox = ({ title, value, type, tooltip }) => (
        <div 
            className={`
                flex-1 flex flex-col items-center p-4 
                bg-healthcare-background dark:bg-healthcare-background-dark 
                rounded-lg border-2 transition-all duration-300
                ${type === 'zeroBeds' && isLowCapacity ? 'border-healthcare-critical dark:border-healthcare-critical-dark animate-pulse' : 'border-transparent'}
                ${type === 'oneBed' && hasLimitedBeds ? 'border-healthcare-warning dark:border-healthcare-warning-dark' : 'border-transparent'}
                hover:shadow-lg
            `}
            role="status"
            aria-label={`${title}: ${value} units`}
            title={tooltip}
        >
            <div className={`flex items-center space-x-2 mb-2`}>
                <span 
                    className={`text-3xl font-bold ${getStatusColor(type)}`}
                    aria-hidden="true"
                >
                    {getStatusPattern(type)}
                </span>
                <span className={`text-3xl font-bold ${getStatusColor(type)}`}>
                    {value}
                </span>
            </div>
            <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark text-center">
                {title}
            </div>
            {type === 'zeroBeds' && isLowCapacity && (
                <div className="mt-2 text-xs text-healthcare-critical dark:text-healthcare-critical-dark flex items-center">
                    <Icon icon="heroicons:exclamation-circle" className="w-4 h-4 mr-1" />
                    Critical
                </div>
            )}
            {type === 'oneBed' && hasLimitedBeds && (
                <div className="mt-2 text-xs text-healthcare-warning dark:text-healthcare-warning-dark flex items-center">
                    <Icon icon="heroicons:exclamation-triangle" className="w-4 h-4 mr-1" />
                    Limited
                </div>
            )}
        </div>
    );

    return (
        <div className="space-y-4">
            <div className="flex justify-between items-center gap-4">
                <DistributionBox 
                    title="0 Beds"
                    value={distribution.zeroBeds}
                    type="zeroBeds"
                    tooltip={tooltips.zeroBeds}
                />
                <DistributionBox 
                    title="1 Bed"
                    value={distribution.oneBed}
                    type="oneBed"
                    tooltip={tooltips.oneBed}
                />
                <DistributionBox 
                    title="2+ Beds"
                    value={distribution.twoPlusBeds}
                    type="twoPlusBeds"
                    tooltip={tooltips.twoPlusBeds}
                />
            </div>

            {/* Screen reader only summary */}
            <div className="sr-only" role="status" aria-live="polite">
                {isLowCapacity && "Alert: High number of units with zero beds available."}
                {hasLimitedBeds && "Warning: Limited number of units with one bed available."}
            </div>

            {/* Screen reader only legend */}
            <div className="sr-only">
                <h3>Status Indicators:</h3>
                <ul>
                    <li>Square (■): Units with zero beds</li>
                    <li>Triangle (▲): Units with one bed</li>
                    <li>Circle (●): Units with two or more beds</li>
                </ul>
            </div>
        </div>
    );
};

export default BedDistributionChart;
