import React from 'react';

const CircularProgress = ({ value, size = 60, strokeWidth = 5, color = '#4F46E5' }) => {
    const radius = (size - strokeWidth) / 2;
    const circumference = radius * 2 * Math.PI;
    const progress = ((100 - value) / 100) * circumference;

    return (
        <div className="relative inline-flex items-center justify-center">
            <svg
                className="transform -rotate-90"
                width={size}
                height={size}
            >
                <defs>
                    <linearGradient id={`circleGradient-${value}`} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={color} stopOpacity="0.8"/>
                        <stop offset="100%" stopColor={color} stopOpacity="0.3"/>
                    </linearGradient>
                </defs>
                {/* Background circle */}
                <circle
                    className="text-gray-200"
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
                <span className="text-sm font-semibold">{value}%</span>
                {value >= 80 && <span className="text-[8px] text-green-600">✓</span>}
            </div>
        </div>
    );
};

export default CircularProgress;
