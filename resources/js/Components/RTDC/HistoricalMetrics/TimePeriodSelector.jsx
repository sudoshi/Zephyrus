import React from 'react';
import { Icon } from '@iconify/react';

const TimePeriodSelector = ({ selectedPeriod, onPeriodChange }) => {
    const periods = [
        { key: '1W', label: '1 Week' },
        { key: '1M', label: '1 Month' },
        { key: '3M', label: '3 Months' },
        { key: '6M', label: '6 Months' },
    ];

    return (
        <div className="flex items-center space-x-2">
            <Icon icon="heroicons:calendar" className="w-5 h-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
            <div className="flex bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg p-1">
                {periods.map(period => (
                    <button
                        key={period.key}
                        onClick={() => onPeriodChange(period.key)}
                        className={`px-3 py-1 text-sm rounded-md transition-colors duration-150 ${
                            selectedPeriod === period.key
                                ? 'bg-healthcare-primary text-white dark:bg-healthcare-primary-dark'
                                : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:bg-healthcare-surface dark:hover:bg-healthcare-surface-dark'
                        }`}
                    >
                        {period.label}
                    </button>
                ))}
            </div>
        </div>
    );
};

export default TimePeriodSelector;
