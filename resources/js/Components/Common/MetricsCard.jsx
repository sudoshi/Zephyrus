import React from 'react';
import Card from '@/Components/Dashboard/Card';
import { Icon } from '@iconify/react';
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';

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
    const [isDarkMode] = useDarkMode();
    const colors = HEALTHCARE_COLORS[isDarkMode ? 'dark' : 'light'];

    const getTrendColor = () => {
        if (trend === 'up') return 'text-healthcare-success dark:text-healthcare-success-dark';
        if (trend === 'down') return 'text-healthcare-critical dark:text-healthcare-critical-dark';
        return 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark';
    };

    const getTrendIcon = () => {
        if (trend === 'up') return 'heroicons:arrow-trending-up';
        if (trend === 'down') return 'heroicons:arrow-trending-down';
        return 'heroicons:minus';
    };

    const getIconBackground = () => {
        if (trend === 'up') return 'bg-healthcare-success dark:bg-healthcare-success-dark';
        if (trend === 'down') return 'bg-healthcare-critical dark:bg-healthcare-critical-dark';
        return 'bg-healthcare-info dark:bg-healthcare-info-dark';
    };

    const getIconColor = () => {
        if (trend === 'up') return colors.success;
        if (trend === 'down') return colors.critical;
        return colors.info;
    };

    return (
        <Card className="transition-all duration-300 hover:translate-y-[-2px] hover:shadow-lg">
            <Card.Content className="p-6">
                <div className="flex items-center justify-between">
                    <div 
                        className={`
                            relative p-3 rounded-lg bg-opacity-15 dark:bg-opacity-20
                            ${getIconBackground()}
                            after:content-[''] after:absolute after:inset-0 
                            after:rounded-lg after:bg-gradient-to-b 
                            after:from-white/10 after:to-transparent
                            after:dark:from-black/10 after:dark:to-transparent
                        `}
                        style={{
                            boxShadow: `0 0 20px ${getIconColor()}20`
                        }}
                    >
                        <Icon 
                            icon={icon} 
                            className={`
                                relative z-10 w-6 h-6 transition-transform duration-300
                                transform group-hover:scale-110
                                ${getTrendColor()}
                            `}
                        />
                    </div>
                    {trendValue !== undefined && (
                        <div className={`
                            flex items-center space-x-2 transition-all duration-300
                            ${getTrendColor()}
                        `}>
                            <Icon 
                                icon={getTrendIcon()} 
                                className="w-5 h-5 transition-transform duration-300 transform group-hover:scale-110" 
                            />
                            <span className="text-sm font-bold">
                                {trendFormatter(trendValue)}
                            </span>
                        </div>
                    )}
                </div>
                <div className="mt-5 space-y-2">
                    <h3 className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
                        {title}
                    </h3>
                    <div className="flex items-baseline">
                        <p className="text-2xl font-extrabold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                            {formatter(value)}
                        </p>
                        {comparison && (
                            <p className="ml-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
                                vs {comparison}
                            </p>
                        )}
                    </div>
                    {description && (
                        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
                            {description}
                        </p>
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
