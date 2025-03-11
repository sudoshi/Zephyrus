import React from 'react';
import { 
    BarChart, 
    Bar, 
    XAxis, 
    YAxis, 
    CartesianGrid, 
    Tooltip, 
    Legend, 
    ResponsiveContainer,
    ReferenceLine,
    Cell
} from 'recharts';
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';

const ServiceBarChart = ({ 
    data, 
    firstCase = false,
    target = 80, // Target percentage
    height = 300
}) => {
    const [isDarkMode] = useDarkMode();

    // Define colors based on the current theme
    const colors = HEALTHCARE_COLORS[isDarkMode ? 'dark' : 'light'];

    const gridStrokeColor = colors.border;
    const axisTickColor = colors.text.secondary;
    const targetLineColor = colors.critical;
    const averageLineColor = colors.info;
    const tooltipBgColor = colors.surface;
    const tooltipTextColor = colors.text.primary;
    const positiveVarianceColor = colors.success;
    const negativeVarianceColor = colors.critical;

    // Transform and sort data
    const chartData = Object.entries(data)
        .map(([service, value]) => ({
            service,
            value: typeof value === 'object' ? value.cases : value,
            target
        }))
        .sort((a, b) => b.value - a.value); // Sort by value descending

    // Calculate average for reference
    const average = chartData.reduce((acc, curr) => acc + curr.value, 0) / chartData.length;

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
                    <p className="text-sm">
                        {firstCase ? 'First Case %' : 'All Cases %'}: 
                        <span className="font-medium ml-1">{data.value}%</span>
                    </p>
                    <p className="text-sm">
                        Target: <span className="font-medium">{target}%</span>
                    </p>
                    <p className="text-sm">
                        Variance: 
                        <span 
                            className="font-medium ml-1"
                            style={{ color: data.value >= target ? positiveVarianceColor : negativeVarianceColor }}
                        >
                            {data.value >= target ? '+' : ''}{(data.value - target).toFixed(1)}%
                        </span>
                    </p>
                </div>
            );
        }
        return null;
    };

    return (
        <div className="w-full" style={{ height: `${height}px` }}>
            <ResponsiveContainer width="100%" height="100%">
                <BarChart
                    data={chartData}
                    margin={{
                        top: 30,
                        right: 40,
                        left: 40,
                        bottom: 80
                    }}
                >
                    <defs>
                        <linearGradient id="colorValue" x1="0" y1="0" x2="0" y2="1">
                            <stop 
                                offset="0%" 
                                stopColor={colors.info} 
                                stopOpacity={0.8}
                            />
                            <stop 
                                offset="100%" 
                                stopColor={colors.info} 
                                stopOpacity={0.3}
                            />
                        </linearGradient>
                    </defs>
                    <CartesianGrid 
                        strokeDasharray="3 3" 
                        stroke={gridStrokeColor} 
                    />
                    <XAxis 
                        dataKey="service"
                        angle={-45}
                        textAnchor="end"
                        height={60}
                        interval={0}
                        tick={{ fill: axisTickColor, fontSize: 14 }}
                        tickLine={{ stroke: axisTickColor }}
                        axisLine={{ stroke: axisTickColor }}
                        tickSize={10}
                    />
                    <YAxis 
                        domain={[0, 100]}
                        tickFormatter={(value) => `${value}%`}
                        tick={{ fill: axisTickColor, fontSize: 14 }}
                        tickLine={{ stroke: axisTickColor }}
                        axisLine={{ stroke: axisTickColor }}
                        tickSize={10}
                        width={60}
                    />
                    <Tooltip content={<CustomTooltip />} />
                    <Legend 
                        wrapperStyle={{
                            color: tooltipTextColor
                        }}
                        iconType="circle"
                        verticalAlign="top"
                        align="right"
                    />
                    
                    {/* Target line */}
                    <ReferenceLine 
                        y={target} 
                        stroke={targetLineColor}
                        strokeDasharray="3 3"
                        label={{ 
                            value: 'Target', 
                            position: 'right',
                            fill: tooltipTextColor,
                            fontSize: 12,
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
                            fill: tooltipTextColor,
                            fontSize: 12,
                        }}
                    />
                    
                    <Bar 
                        dataKey="value" 
                        fill="url(#colorValue)"
                        radius={[6, 6, 0, 0]}
                        maxBarSize={80}
                    >
                        {chartData.map((entry, index) => (
                            <Cell 
                                key={`cell-${index}`}
                                fill={entry.value >= target ? 'url(#colorValue)' : negativeVarianceColor}
                            />
                        ))}
                    </Bar>
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
};

export default ServiceBarChart;
