import React from 'react';
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';

const CircularProgress = ({ value, size = 60, strokeWidth = 5, color }) => {
    const [isDarkMode] = useDarkMode();
    const colors = HEALTHCARE_COLORS[isDarkMode ? 'dark' : 'light'];
    
    const radius = (size - strokeWidth) / 2;
    const circumference = radius * 2 * Math.PI;
    const progress = ((100 - value) / 100) * circumference;

    const getCheckColor = () => {
        if (value < 70) return colors.critical;
        if (value < 80) return colors.warning;
        return colors.success;
    };

    return (
        <div className="relative inline-flex items-center justify-center">
                <svg
                    className="transform -rotate-90 transition-transform duration-300"
                    width={size}
                    height={size}
                    style={isDarkMode ? { filter: 'drop-shadow(0 0 6px rgba(255, 255, 255, 0.3))' } : {}}
                >
                <defs>
                    <linearGradient id={`circleGradient-${value}`} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={color} stopOpacity={isDarkMode ? "0.6" : "0.8"}/>
                        <stop offset="100%" stopColor={color} stopOpacity={isDarkMode ? "0.2" : "0.3"}/>
                    </linearGradient>
                </defs>
                {/* Background circle */}
                <circle
                    className="text-healthcare-border dark:text-healthcare-border-dark transition-colors duration-300"
                    strokeWidth={strokeWidth}
                    stroke="currentColor"
                    fill="transparent"
                    r={radius}
                    cx={size / 2}
                    cy={size / 2}
                />
                {/* Progress circle */}
                <circle
                    className="transition-all duration-300 ease-in-out"
                    strokeWidth={strokeWidth}
                    strokeDasharray={circumference}
                    strokeDashoffset={progress}
                    strokeLinecap="round"
                    stroke={`url(#circleGradient-${value})`}
                    fill="transparent"
                    r={radius}
                    cx={size / 2}
                    cy={size / 2}
                />
            </svg>
            <div className="absolute flex flex-col items-center">
                <span className="text-sm font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark transition-colors duration-300">
                    {value}%
                </span>
                {value >= 80 && (
                    <span 
                        className="text-[8px] transition-colors duration-300"
                        style={{ color: getCheckColor() }}
                    >
                        ✓
                    </span>
                )}
            </div>
        </div>
    );
};

export default CircularProgress;
