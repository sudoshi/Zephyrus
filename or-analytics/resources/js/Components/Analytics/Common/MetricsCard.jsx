import React from 'react';
import { Card } from '@heroui/react';
import { Icon } from '@iconify/react';

const MetricsCard = ({ 
    title, 
    value, 
    trend, 
    trendValue, 
    icon, 
    description, 
    color = 'indigo',
    formatter = (val) => val,
    trendFormatter = (val) => val,
    comparison = 'previous period'
}) => {
    const getTrendColor = () => {
        if (trend === 'up') return 'text-green-600';
        if (trend === 'down') return 'text-red-600';
        return 'text-gray-600';
    };

    const getTrendIcon = () => {
        if (trend === 'up') return 'heroicons:arrow-trending-up';
        if (trend === 'down') return 'heroicons:arrow-trending-down';
        return 'heroicons:minus';
    };

    return (
        <Card>
            <Card.Content>
                <div className="flex items-center justify-between">
                    <div className={`p-2 rounded-lg bg-${color}-100`}>
                        <Icon icon={icon} className={`w-5 h-5 text-${color}-600`} />
                    </div>
                    {trendValue !== undefined && (
                        <div className={`flex items-center space-x-1 ${getTrendColor()}`}>
                            <Icon icon={getTrendIcon()} className="w-4 h-4" />
                            <span className="text-sm font-medium">
                                {trendFormatter(trendValue)}
                            </span>
                        </div>
                    )}
                </div>
                <div className="mt-4">
                    <h3 className="text-sm font-medium text-gray-500">{title}</h3>
                    <div className="flex items-baseline mt-1">
                        <p className="text-2xl font-semibold">{formatter(value)}</p>
                        {comparison && (
                            <p className="ml-2 text-sm text-gray-500">vs {comparison}</p>
                        )}
                    </div>
                    {description && (
                        <p className="mt-1 text-sm text-gray-500">{description}</p>
                    )}
                </div>
            </Card.Content>
        </Card>
    );
};

export const MetricsCardGroup = ({ children, cols = 4 }) => {
    return (
        <div className={`grid grid-cols-1 md:grid-cols-${cols} gap-6`}>
            {children}
        </div>
    );
};

export default MetricsCard;
