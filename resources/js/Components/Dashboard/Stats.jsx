import React from 'react';
import { Icon } from '@iconify/react';

const Stats = ({ title, value, description, trend, icon }) => {
    const getTrendColor = () => {
        if (!trend) return '';
        return trend === 'up' ? 'text-healthcare-critical dark:text-healthcare-critical-dark' : 'text-healthcare-success dark:text-healthcare-success-dark';
    };

    return (
        <div className="rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 shadow-sm border border-healthcare-border dark:border-healthcare-border-dark hover:border-indigo-100 transition-all duration-200 group">
            <div className="flex items-center justify-between">
                <div className="flex items-center">
                    {icon && (
                        <div className="mr-3 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 group-hover:bg-indigo-100 transition-colors duration-200">
                            {icon}
                        </div>
                    )}
                    <div>
                        <div>
                            <div className="flex items-center space-x-2 text-sm">
                                <p className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{title}</p>
                                <div className="relative group/tooltip cursor-help">
                                    <Icon 
                                        icon="heroicons:information-circle" 
                                        className="panel-label-icon text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-secondary dark:hover:text-healthcare-text-secondary-dark"
                                    />
                                    <div className="absolute z-10 w-48 p-2 mt-2 text-xs bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-lg border border-healthcare-border dark:border-healthcare-border-dark opacity-0 group-hover/tooltip:opacity-100 transition-opacity duration-200 pointer-events-none left-0">
                                        {description || `Details about ${title.toLowerCase()}`}
                                    </div>
                                </div>
                            </div>
                            <p className="mt-1 text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</p>
                        </div>
                    </div>
                </div>
                {trend && (
                    <div className="flex flex-col items-end space-y-1">
                        <div className={`flex items-center space-x-1 ${getTrendColor()}`}>
                            <Icon 
                                icon={trend === 'up' ? 'heroicons:arrow-up' : 'heroicons:arrow-down'} 
                                className="h-5 w-5"
                            />
                            <span className="text-sm font-medium">
                                {trend === 'up' ? '+' : '-'}5%
                            </span>
                        </div>
                        <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">vs. last period</span>
                    </div>
                )}
            </div>
            {description && (
                <div className="mt-4 flex items-center space-x-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    <Icon icon="heroicons:chart-bar" className="w-4 h-4" />
                    <p>{description}</p>
                </div>
            )}
        </div>
    );
};

export default Stats;
