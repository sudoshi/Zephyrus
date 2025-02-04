import React from 'react';
import Card from '@/Components/Dashboard/Card';
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
        if (trend === 'up') return 'text-healthcare-success dark:text-healthcare-success-dark transition-colors duration-300';
        if (trend === 'down') return 'text-healthcare-critical dark:text-healthcare-critical-dark transition-colors duration-300';
        return 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300';
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
                    <div className={`
                        p-3 rounded-lg 
                        ${trend === 'up' ? 'bg-healthcare-success bg-opacity-10 dark:bg-opacity-20' : 
                          trend === 'down' ? 'bg-healthcare-critical bg-opacity-10 dark:bg-opacity-20' : 
                          'bg-healthcare-info bg-opacity-10 dark:bg-opacity-20'} 
                        transition-colors duration-300
                    `}>
                        <Icon 
                            icon={icon} 
                            className={`
                                w-5 h-5 
                                ${trend === 'up' ? 'text-healthcare-success dark:text-healthcare-success-dark' : 
                                  trend === 'down' ? 'text-healthcare-critical dark:text-healthcare-critical-dark' : 
                                  'text-healthcare-info dark:text-healthcare-info-dark'}
                                transition-colors duration-300
                            `}
                        />
                    </div>
                    {trendValue !== undefined && (
                        <div className={`flex items-center space-x-1 ${getTrendColor()}`}>
                            <Icon 
                                icon={getTrendIcon()} 
                                className="w-4 h-4 transition-transform duration-300 transform hover:scale-110" 
                            />
                            <span className="text-sm font-medium">
                                {trendFormatter(trendValue)}
                            </span>
                        </div>
                    )}
                </div>
                <div className="mt-4 space-y-1">
                    <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">{title}</h3>
                    <div className="flex items-baseline">
                        <p className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">{formatter(value)}</p>
                        {comparison && (
                            <p className="ml-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">vs {comparison}</p>
                        )}
                    </div>
                    {description && (
                        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">{description}</p>
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
