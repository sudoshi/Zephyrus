import React, { useState } from 'react';
import Card from '@/Components/Dashboard/Card';
import { Popover } from '@headlessui/react';
import { Icon } from '@iconify/react';
import CircularProgress from './CircularProgress';
import { LineChart, Line, ResponsiveContainer } from 'recharts';

const MetricCard = ({ 
    title, 
    value, 
    trend, 
    previousValue, 
    info, 
    date, 
    showAsPercentage = true, 
    size = 'normal',
    sparklineData = [], // Array of last 24 data points
    threshold = { warning: 70, critical: 50 }, // Configurable thresholds
    miniStats = [], // Array of {label, value} for additional metrics
    onClick // Callback for drill-down
}) => {
    const [showTooltip, setShowTooltip] = useState(false);
    const [isHovered, setIsHovered] = useState(false);
    
    const trendIcon = trend === 'up' ? 'heroicons:arrow-up' : 'heroicons:arrow-down';
    const trendColor = trend === 'up' ? 'text-green-500' : 'text-red-500';
    
    // Extract numeric value if percentage
    const numericValue = typeof value === 'string' ? parseInt(value) : value;
    
    // Determine status color based on thresholds
    const getStatusColor = (value) => {
        if (value < threshold.critical) return 'bg-red-50 border-red-200';
        if (value < threshold.warning) return 'bg-yellow-50 border-yellow-200';
        return 'bg-green-50 border-green-200';
    };

    // Transform sparkline data for recharts
    const sparklineChartData = sparklineData.map((value, index) => ({
        value
    }));

    return (
        <Card 
            className={`p-4 bg-white shadow-sm border-l-4 transition-all duration-200 ${
                getStatusColor(numericValue)
            } ${size === 'small' ? 'h-32' : 'h-40'} ${
                isHovered ? 'transform scale-[1.02]' : ''
            }`}
            onMouseEnter={() => setIsHovered(true)}
            onMouseLeave={() => setIsHovered(false)}
            onClick={onClick}
        >
            <div className="flex flex-col h-full">
                {/* Header */}
                <div className="flex items-center justify-between mb-2">
                    <h3 className="text-sm font-medium text-gray-600">{title}</h3>
                    <div className="flex items-center space-x-2">
                        {date && (
                            <span className="text-xs text-gray-500">{date}</span>
                        )}
                        <Popover className="relative">
                            <Popover.Button 
                                className="focus:outline-none"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <Icon 
                                    icon="heroicons:information-circle" 
                                    className="w-4 h-4 text-gray-400 hover:text-gray-600" 
                                />
                            </Popover.Button>
                            <Popover.Panel className="absolute z-10 w-64 px-4 py-2 mt-2 -right-2 bg-white rounded-lg shadow-lg border border-gray-200">
                                <p className="text-xs text-gray-600">{info}</p>
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
                                    color={
                                        numericValue < threshold.critical ? '#ef4444' :
                                        numericValue < threshold.warning ? '#f59e0b' :
                                        '#22c55e'
                                    }
                                />
                            ) : (
                                <span className="text-3xl font-bold text-gray-900">
                                    {value}
                                </span>
                            )}
                            {previousValue && (
                                <div className="flex flex-col">
                                    <div className="flex items-center">
                                        <Icon 
                                            icon={trend === 'up' ? 'heroicons:arrow-up' : 'heroicons:arrow-down'} 
                                            className={`${trendColor} w-4 h-4 mr-1`} 
                                        />
                                        <span className="text-sm text-gray-500">
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
                                        <span className="text-gray-500">{stat.label}: </span>
                                        <span className="font-medium text-gray-700">{stat.value}</span>
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
                                            <stop offset="0%" stopColor="#4F46E5" stopOpacity={0.3}/>
                                            <stop offset="100%" stopColor="#4F46E5" stopOpacity={0.1}/>
                                        </linearGradient>
                                    </defs>
                                    <Line 
                                        type="monotone"
                                        dataKey="value"
                                        stroke={
                                            numericValue < threshold.critical ? '#ef4444' :
                                            numericValue < threshold.warning ? '#f59e0b' :
                                            '#4F46E5'
                                        }
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
                    <div className={`text-xs text-blue-600 mt-2 transition-opacity duration-200 ${
                        isHovered ? 'opacity-100' : 'opacity-0'
                    }`}>
                        Click to view details →
                    </div>
                )}
            </div>
        </Card>
    );
};

export default MetricCard;
