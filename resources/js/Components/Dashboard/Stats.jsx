import React from 'react';
import { Icon } from '@iconify/react';

const Stats = ({ title, value, description, trend, icon }) => {
    const getTrendColor = () => {
        if (!trend) return '';
        return trend === 'up' ? 'text-red-600' : 'text-green-600';
    };

    return (
        <div className="rounded-lg bg-white p-6 shadow-sm border border-gray-100 hover:border-indigo-100 transition-all duration-200 group">
            <div className="flex items-center justify-between">
                <div className="flex items-center">
                    {icon && (
                        <div className="mr-4 flex h-12 w-12 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 group-hover:bg-indigo-100 transition-colors duration-200">
                            {icon}
                        </div>
                    )}
                    <div>
                        <div>
                            <div className="flex items-center space-x-2">
                                <p className="text-sm font-medium text-gray-600">{title}</p>
                                <div className="relative group/tooltip cursor-help">
                                    <Icon 
                                        icon="heroicons:information-circle" 
                                        className="w-4 h-4 text-gray-400 hover:text-gray-600"
                                    />
                                    <div className="absolute z-10 w-48 p-2 mt-2 text-xs bg-white rounded-lg shadow-lg border border-gray-200 opacity-0 group-hover/tooltip:opacity-100 transition-opacity duration-200 pointer-events-none left-0">
                                        {description || `Details about ${title.toLowerCase()}`}
                                    </div>
                                </div>
                            </div>
                            <p className="mt-1 text-3xl font-semibold text-gray-900">{value}</p>
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
                        <span className="text-xs text-gray-500">vs. last period</span>
                    </div>
                )}
            </div>
            {description && (
                <div className="mt-4 flex items-center space-x-2 text-sm text-gray-500">
                    <Icon icon="heroicons:chart-bar" className="w-4 h-4" />
                    <p>{description}</p>
                </div>
            )}
        </div>
    );
};

export default Stats;
