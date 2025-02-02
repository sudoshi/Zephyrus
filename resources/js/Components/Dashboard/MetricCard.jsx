import React, { useState } from 'react';
import Card from '@/Components/Dashboard/Card';
import { Popover } from '@headlessui/react';
import { Icon } from '@iconify/react';
import CircularProgress from './CircularProgress';
import { LineChart, Line, ResponsiveContainer } from 'recharts';
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';

const MetricCard = ({ 
    title, 
    value, 
    trend, 
    previousValue, 
    info, 
    date, 
    showAsPercentage = true, 
    size = 'normal',
    sparklineData = [],
    threshold = { warning: 70, critical: 50 },
    miniStats = [],
    onClick
}) => {
    const [isHovered, setIsHovered] = useState(false);
    const [isDarkMode] = useDarkMode();
    const colors = HEALTHCARE_COLORS[isDarkMode ? 'dark' : 'light'];
    
    const trendIcon = trend === 'up' ? 'heroicons:arrow-up' : 'heroicons:arrow-down';
    const trendColor = trend === 'up' ? colors.success : colors.critical;
    
    const numericValue = typeof value === 'string' ? parseInt(value) : value;
    
    const getStatusColor = (value) => {
if (value < threshold.critical) {
    return isDarkMode 
        ? 'bg-healthcare-critical-dark bg-opacity-40 border-healthcare-critical-dark border-opacity-40'
        : 'bg-healthcare-critical bg-opacity-5 border-healthcare-critical border-opacity-20';
}
if (value < threshold.warning) {
    return isDarkMode
        ? 'bg-healthcare-warning-dark bg-opacity-40 border-healthcare-warning-dark border-opacity-40'
        : 'bg-healthcare-warning bg-opacity-5 border-healthcare-warning border-opacity-20';
}
return isDarkMode
    ? 'bg-healthcare-success-dark bg-opacity-40 border-healthcare-success-dark border-opacity-40'
    : 'bg-healthcare-success bg-opacity-5 border-healthcare-success border-opacity-20';
    };

    const sparklineChartData = sparklineData.map((value, index) => ({
        value
    }));

    const getSparklineColor = () => {
        if (numericValue < threshold.critical) return colors.critical;
        if (numericValue < threshold.warning) return colors.warning;
        return colors.info;
    };

    return (
        <Card 
            className={`
                p-4 shadow-sm border-l-4 transition-all duration-300
                ${getStatusColor(numericValue)}
                ${size === 'small' ? 'h-32' : 'h-40'}
                ${isHovered ? 'transform scale-[1.02]' : ''}
                hover:shadow-lg dark:hover:shadow-[0_4px_12px_rgba(0,0,0,0.25)]
            `}
            onMouseEnter={() => setIsHovered(true)}
            onMouseLeave={() => setIsHovered(false)}
            onClick={onClick}
        >
            <div className="flex flex-col h-full">
                {/* Header */}
                <div className="flex items-center justify-between mb-2">
<h3 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
    {title}
</h3>
                    <div className="flex items-center space-x-2">
                        {date && (
                            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
                                {date}
                            </span>
                        )}
                        <Popover className="relative">
                            <Popover.Button 
                                className="focus:outline-none"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <Icon 
                                    icon="heroicons:information-circle" 
                                    className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark transition-colors duration-300" 
                                />
                            </Popover.Button>
                            <Popover.Panel className="absolute z-10 w-64 px-4 py-2 mt-2 -right-2 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-lg border border-healthcare-border dark:border-healthcare-border-dark transition-all duration-300">
                                <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                    {info}
                                </p>
                            </Popover.Panel>
                        </Popover>
                    </div>
                </div>

                {/* Main Content */}
                <div className="flex-1 flex">
                    {/* Left side - Main metric */}
                    <div className="flex-1 flex flex-col justify-between">
                        <div className="flex items-center space-x-4">
                            {showAsPercentage ? (
                                <CircularProgress 
                                    value={numericValue} 
                                    size={50} 
                                    color={getSparklineColor()}
                                />
                            ) : (
                                <span className="text-3xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                    {value}
                                </span>
                            )}
                            {previousValue && (
                                <div className="flex flex-col">
                                    <div className="flex items-center">
                                        <Icon 
                                            icon={trendIcon} 
                                            className="w-4 h-4 mr-1 transition-colors duration-300"
                                            style={{ color: trendColor }}
                                        />
                                        <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300">
                                            from {previousValue}
                                        </span>
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* Mini Stats */}
                        {miniStats.length > 0 && (
                            <div className="grid grid-cols-2 gap-2 mt-2">
                                {miniStats.map((stat, index) => (
                                    <div key={index} className="text-xs">
<span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
    {stat.label}:
</span>
                                        <span className="font-medium ml-1 text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                                            {stat.value}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Right side - Sparkline */}
                    {sparklineData.length > 0 && (
                        <div className="w-24 h-16 ml-4">
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart data={sparklineChartData}>
                                    <defs>
                                        <linearGradient id={`sparklineGradient-${title.replace(/\s+/g, '-').toLowerCase()}`} x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" stopColor={getSparklineColor()} stopOpacity={0.3}/>
                                            <stop offset="100%" stopColor={getSparklineColor()} stopOpacity={0.1}/>
                                        </linearGradient>
                                    </defs>
                                    <Line 
                                        type="monotone"
                                        dataKey="value"
                                        stroke={getSparklineColor()}
                                        fill={`url(#sparklineGradient-${title.replace(/\s+/g, '-').toLowerCase()})`}
                                        strokeWidth={1.5}
                                        dot={false}
                                        isAnimationActive={false}
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                    )}
                </div>

                {/* Hover Indicator */}
                {onClick && (
                    <div className={`
                        text-xs text-healthcare-info dark:text-healthcare-info-dark mt-2
                        transition-all duration-300
                        ${isHovered ? 'opacity-100' : 'opacity-0'}
                    `}>
                        Click to view details →
                    </div>
                )}
            </div>
        </Card>
    );
};

export default MetricCard;
