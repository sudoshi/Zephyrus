import React from 'react';
import {
    LineChart,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
    ResponsiveContainer,
    ReferenceLine,
} from 'recharts';

const TrendChart = ({ 
    data, 
    series, 
    xAxis = {}, 
    yAxis = {}, 
    height = "100%",
    showLegend = true,
    referenceLines = [],
}) => {
    // Default formatters
    const defaultXAxisFormatter = (value) => {
        if (!value) return '';
        try {
            return new Date(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return value;
        }
    };
    const defaultYAxisFormatter = (value) => `${value} min`;

    // Merge with defaults
    const xAxisConfig = {
        dataKey: 'time',
        type: 'category',
        formatter: defaultXAxisFormatter,
        ...xAxis,
    };

    const yAxisConfig = Array.isArray(yAxis) 
        ? yAxis.map(axis => ({
            formatter: defaultYAxisFormatter,
            ...axis,
        }))
        : [{
            formatter: defaultYAxisFormatter,
            ...yAxis,
        }];

    // Custom tooltip styles
    const CustomTooltip = ({ active, payload, label }) => {
        if (active && payload && payload.length) {
            return (
                <div className="bg-white dark:bg-healthcare-background-dark p-3 border rounded shadow-lg">
                    <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
                        {xAxisConfig.formatter(label)}
                    </p>
                    <div className="space-y-1">
                        {payload.map((entry, index) => {
                            const yAxis = yAxisConfig.find(axis => axis.id === entry.dataKey) || yAxisConfig[0];
                            return (
                                <div key={index} className="flex items-center justify-between space-x-4">
                                    <div className="flex items-center space-x-2">
                                        <div
                                            className="w-3 h-3 rounded-full"
                                            style={{ backgroundColor: entry.color }}
                                        />
                                        <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                            {entry.name}
                                        </span>
                                    </div>
                                    <span className="text-sm font-medium">
                                        {yAxis.formatter(entry.value)}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </div>
            );
        }
        return null;
    };

    return (
        <ResponsiveContainer width="100%" height={height}>
            <LineChart data={data} margin={{ top: 10, right: 30, left: 20, bottom: 10 }}>
                <CartesianGrid 
                    strokeDasharray="3 3" 
                    stroke="#E5E7EB"
                    vertical={false}
                />
                <XAxis
                    dataKey={xAxisConfig.dataKey}
                    type={xAxisConfig.type}
                    tickFormatter={xAxisConfig.formatter}
                    stroke="#9CA3AF"
                    fontSize={12}
                    tickMargin={8}
                />
                {yAxisConfig.map((axis, index) => (
                    <YAxis
                        key={axis.id || index}
                        yAxisId={axis.id || 0}
                        orientation={axis.orientation || 'left'}
                        tickFormatter={axis.formatter}
                        stroke="#9CA3AF"
                        fontSize={12}
                        tickMargin={8}
                        domain={axis.domain || ['auto', 'auto']}
                    />
                ))}
                <Tooltip content={<CustomTooltip />} />
                {showLegend && (
                    <Legend
                        verticalAlign="top"
                        height={36}
                        iconType="circle"
                        iconSize={8}
                        wrapperStyle={{
                            paddingTop: '8px',
                            fontSize: '12px',
                        }}
                    />
                )}
                {/* Reference Lines */}
                {referenceLines.map((line, index) => (
                    <ReferenceLine
                        key={`ref-line-${index}`}
                        y={line.y}
                        stroke={line.color}
                        strokeDasharray={line.strokeDasharray}
                        label={{
                            value: line.label,
                            position: 'right',
                            fill: line.color,
                            fontSize: 12,
                        }}
                    />
                ))}
                {/* Data Lines */}
                {series.map((s, index) => (
                    <Line
                        key={s.dataKey}
                        type="monotone"
                        dataKey={s.dataKey}
                        name={s.name}
                        stroke={s.color}
                        strokeWidth={2}
                        dot={false}
                        yAxisId={s.yAxisId || 0}
                        strokeDasharray={s.strokeDasharray}
                        activeDot={{ r: 4, strokeWidth: 2 }}
                    />
                ))}
            </LineChart>
        </ResponsiveContainer>
    );
};

export default TrendChart;
