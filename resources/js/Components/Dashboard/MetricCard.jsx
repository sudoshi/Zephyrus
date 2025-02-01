import React, { useState } from 'react';
import Card from '@/Components/Dashboard/Card';
import { Tooltip } from '@heroui/react';
import { Icon } from '@iconify/react';
import arrowUpIcon from '@iconify/icons-solar/alt-arrow-up-line-duotone';
import arrowDownIcon from '@iconify/icons-solar/alt-arrow-down-line-duotone';
import infoIcon from '@iconify/icons-solar/info-circle-line-duotone';
import CircularProgress from './CircularProgress';

const MetricCard = ({ title, value, trend, previousValue, info, date, showAsPercentage = true, size = 'normal' }) => {
    const [showTooltip, setShowTooltip] = useState(false);
    const trendIcon = trend === 'up' ? arrowUpIcon : arrowDownIcon;
    const trendColor = trend === 'up' ? 'text-green-500' : 'text-red-500';
    
    // Extract numeric value if percentage
    const numericValue = typeof value === 'string' ? parseInt(value) : value;
    
    return (
        <Card className={`p-4 bg-white shadow-sm ${size === 'small' ? 'h-24' : 'h-32'}`}>
            <div className="flex items-start justify-between">
                <div className="flex-1">
                    <div className="flex items-center justify-between">
                        <h3 className="text-sm font-medium text-gray-600">{title}</h3>
                        <div className="flex items-center space-x-2">
                            {date && (
                                <span className="text-xs text-gray-500">{date}</span>
                            )}
                            <Tooltip 
                                content={info}
                                open={showTooltip}
                                onOpenChange={setShowTooltip}
                            >
                                <button 
                                    className="focus:outline-none"
                                    onClick={() => setShowTooltip(!showTooltip)}
                                >
                                    <Icon 
                                        icon={infoIcon} 
                                        className="w-4 h-4 text-gray-400 hover:text-gray-600" 
                                    />
                                </button>
                            </Tooltip>
                        </div>
                    </div>
                    <div className="mt-4 flex items-center justify-between">
                        <div className="flex items-center space-x-4">
                            {showAsPercentage ? (
                                <CircularProgress 
                                    value={numericValue} 
                                    size={50} 
                                    color="#007bff"
                                />
                            ) : (
                                <span className="text-2xl font-bold text-gray-900">
                                    {value}
                                </span>
                            )}
                            {previousValue && (
                                <div className="flex items-center">
                                    <Icon 
                                        icon={trendIcon} 
                                        className={`${trendColor} w-4 h-4 mr-1`} 
                                    />
                                    <span className="text-sm text-gray-500">
                                        from {previousValue}
                                    </span>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </Card>
    );
};

export default MetricCard;
