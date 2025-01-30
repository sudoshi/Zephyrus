import React from 'react';

const Stats = ({ title, value, description, trend, icon }) => {
    const getTrendColor = () => {
        if (!trend) return '';
        return trend === 'up' ? 'text-red-600' : 'text-green-600';
    };

    return (
        <div className="rounded-lg bg-white p-6 shadow-sm">
            <div className="flex items-center justify-between">
                <div className="flex items-center">
                    {icon && (
                        <div className="mr-4 flex h-12 w-12 items-center justify-center rounded-full bg-indigo-100 text-indigo-600">
                            {icon}
                        </div>
                    )}
                    <div>
                        <p className="text-sm font-medium text-gray-600">{title}</p>
                        <p className="mt-1 text-3xl font-semibold text-gray-900">{value}</p>
                    </div>
                </div>
                {trend && (
                    <div className={`flex items-center ${getTrendColor()}`}>
                        {trend === 'up' ? (
                            <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fillRule="evenodd" d="M5.293 7.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 5.414V17a1 1 0 11-2 0V5.414L6.707 7.707a1 1 0 01-1.414 0z" clipRule="evenodd" />
                            </svg>
                        ) : (
                            <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fillRule="evenodd" d="M14.707 12.293a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L9 14.586V3a1 1 0 012 0v11.586l2.293-2.293a1 1 0 011.414 0z" clipRule="evenodd" />
                            </svg>
                        )}
                    </div>
                )}
            </div>
            {description && (
                <p className="mt-2 text-sm text-gray-600">{description}</p>
            )}
        </div>
    );
};

export default Stats;
