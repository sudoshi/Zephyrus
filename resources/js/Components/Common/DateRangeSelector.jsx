import React from 'react';
import { Select, Button } from '@heroui/react';
import { Icon } from '@iconify/react';

const DateRangeSelector = ({ startDate, endDate, onDateChange, onQuickSelect }) => {
    const quickRanges = [
        { label: 'Last 7 Days', value: '7d' },
        { label: 'Last 30 Days', value: '30d' },
        { label: 'Last Quarter', value: 'quarter' },
        { label: 'Last Year', value: 'year' },
        { label: 'Year to Date', value: 'ytd' }
    ];

    const handleQuickSelect = (range) => {
        const end = new Date();
        let start = new Date();

        switch (range) {
            case '7d':
                start.setDate(end.getDate() - 7);
                break;
            case '30d':
                start.setDate(end.getDate() - 30);
                break;
            case 'quarter':
                start.setMonth(end.getMonth() - 3);
                break;
            case 'year':
                start.setFullYear(end.getFullYear() - 1);
                break;
            case 'ytd':
                start = new Date(end.getFullYear(), 0, 1);
                break;
            default:
                break;
        }

        onQuickSelect(start.toISOString().split('T')[0], end.toISOString().split('T')[0]);
    };

    return (
        <div className="flex items-center space-x-4 p-4 bg-white dark:bg-gray-800 rounded-lg shadow">
            <div className="flex items-center space-x-2">
                <Icon icon="heroicons:calendar" className="w-5 h-5 text-gray-500 dark:text-gray-300" />
                    <input
                        type="date"
                        value={startDate}
                        onChange={(e) => onDateChange('start', e.target.value)}
                        className="border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark rounded-md shadow-sm focus:border-healthcare-info focus:ring-healthcare-info"
                    />
                <span className="text-gray-500 dark:text-gray-300">to</span>
                    <input
                        type="date"
                        value={endDate}
                        onChange={(e) => onDateChange('end', e.target.value)}
                        className="border-healthcare-border dark:border-healthcare-border-dark bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark rounded-md shadow-sm focus:border-healthcare-info focus:ring-healthcare-info"
                    />
            </div>

            <div className="flex items-center space-x-2">
                {quickRanges.map((range) => (
                    <Button
                        key={range.value}
                        variant="secondary"
                        size="sm"
                        onPress={() => handleQuickSelect(range.value)}
                    >
                        {range.label}
                    </Button>
                ))}
            </div>
        </div>
    );
};

export default DateRangeSelector;
