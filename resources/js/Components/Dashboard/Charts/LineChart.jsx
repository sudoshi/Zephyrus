import React from 'react';
import { 
    LineChart as RechartsLineChart, 
    Line, 
    XAxis, 
    YAxis, 
    CartesianGrid, 
    Tooltip, 
    Legend, 
    ResponsiveContainer,
    ReferenceLine,
    Area
} from 'recharts';
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';

const LineChart = ({ 
    data,
    height = 300,
    target = 80 // Target percentage
}) => {
    const [isDarkMode] = useDarkMode();

    // Define colors based on the current theme
    const colors = HEALTHCARE_COLORS[isDarkMode ? 'dark' : 'light'];

    const gridStrokeColor = colors.border;
    const axisTickColor = colors.text.primary; // Changed for better contrast
    const targetLineColor = colors.critical;
    const averageLineColor = colors.info;
    const tooltipBgColor = colors.surface;
    const tooltipTextColor = colors.text.primary;
    const positiveVarianceColor = colors.success;
    const negativeVarianceColor = colors.critical;
    const backgroundColor = colors.background; // Added for chart background

    // Calculate average
    const average = data.reduce((acc, curr) => acc + curr.value, 0) / data.length;

    // Custom tooltip component
    const CustomTooltip = ({ active, payload, label }) => {
        if (active && payload && payload.length) {
            const data = payload[0].payload;
            return (
                <div 
                    className="p-3 shadow-lg rounded-lg border"
                    style={{
                        backgroundColor: tooltipBgColor,
                        color: tooltipTextColor,
                        borderColor: colors.border
                    }}
                >
                    <p className="font-medium">{label}</p>
                    <div className="mt-2 space-y-1">
                        <p className="text-sm">
                            Utilization: 
                            <span className="font-medium ml-1">{data.value}%</span>
                            <span 
                                className="ml-2 text-xs"
                                style={{
                                    color: data.value >= target ? positiveVarianceColor : negativeVarianceColor
                                }}
                            >
                                ({data.value >= target ? '✓' : `${(target - data.value).toFixed(1)}% below target`})
                            </span>
                        </p>
                        <p className="text-xs">Target: {target}%</p>
                        {data.breakdown && (
                            <div className="mt-2 pt-2 border-t" style={{ borderColor: colors.border }}>
                                <p className="text-xs font-medium mb-1">Breakdown:</p>
                                {Object.entries(data.breakdown).map(([key, value]) => (
                                    <p key={key} className="text-xs">
                                        {key}: <span className="font-medium">{value}%</span>
                                    </p>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            );
        }
        return null;
    };

    return (
        <div className="w-full" style={{ height: `${height}px`, backgroundColor }}>
            <ResponsiveContainer width="100%" height="100%">
                <RechartsLineChart
                    data={data}
                    margin={{
                        top: 30,
                        right: 40,
                        left: 40,
                        bottom: 80
                    }}
                >
                    <defs>
                        <linearGradient id="colorValue" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor={colors.info} stopOpacity={0.3}/>
                            <stop offset="100%" stopColor={colors.info} stopOpacity={0.1}/>
                        </linearGradient>
                    </defs>
                    <CartesianGrid 
                        strokeDasharray="3 3" 
                        stroke={gridStrokeColor} 
                        strokeOpacity={isDarkMode ? 0.2 : 1} // Reduced opacity in dark mode
                    />
                    <XAxis 
                        dataKey="date"
                        angle={-45}
                        textAnchor="end"
                        height={60}
                        interval={0}
                        tick={{ fill: axisTickColor, fontSize: 14 }}
                        tickSize={10}
                    />
                    <YAxis 
                        domain={[0, 100]}
                        tickFormatter={(value) => `${value}%`}
                        tick={{ fill: axisTickColor, fontSize: 14 }}
                        tickSize={10}
                        width={60}
                    />
                    <Tooltip content={<CustomTooltip />} />
                    <Legend wrapperStyle={{ fontSize: '14px', color: tooltipTextColor }} />
                    
                    {/* Target line */}
                    <ReferenceLine 
                        y={target} 
                        stroke={targetLineColor}
                        strokeDasharray="3 3"
                        label={{ 
                            value: 'Target', 
                            position: 'right',
                            fill: targetLineColor,
                            fontSize: 14
                        }}
                    />
                    
                    {/* Average line */}
                    <ReferenceLine 
                        y={average} 
                        stroke={averageLineColor}
                        strokeDasharray="3 3"
                        label={{ 
                            value: 'Average', 
                            position: 'right',
                            fill: averageLineColor,
                            fontSize: 14
                        }}
                    />
                    
                    <Area
                        type="monotone"
                        dataKey="value"
                        stroke={colors.info}
                        strokeWidth={3}
                        fill="url(#colorValue)"
                        dot={{ r: 6, strokeWidth: 2, fill: colors.info }}
                        activeDot={{ r: 8, strokeWidth: 2, fill: colors.info }}
                    />
                </RechartsLineChart>
            </ResponsiveContainer>
        </div>
    );
};

export default LineChart;
