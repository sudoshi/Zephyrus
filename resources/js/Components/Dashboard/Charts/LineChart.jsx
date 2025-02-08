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
    target = 80, // Target percentage
    criticalThreshold = 10, // Critical threshold for capacity-demand difference
    warningThreshold = 20, // Warning threshold for capacity-demand difference
    ariaLabel = 'Line chart showing demand and capacity trends'
}) => {
    const [isDarkMode] = useDarkMode();

    // Define colors based on the current theme
    const colors = HEALTHCARE_COLORS[isDarkMode ? 'dark' : 'light'];

    const gridStrokeColor = colors.border;
    const axisTickColor = colors.text.primary;
    const tooltipBgColor = colors.surface;
    const tooltipTextColor = colors.text.primary;
    const backgroundColor = colors.background;
    
    // Calculate thresholds for shaded regions
    const getThresholdRegions = () => {
        if (!isDemandCapacityFormat) return [];
        
        const regions = [];
        data.forEach((point, index) => {
            const diff = point.capacity - point.demand;
            if (diff <= criticalThreshold) {
                regions.push({
                    x1: index,
                    x2: index + 1,
                    fill: `rgba(${colors.critical.replace('rgb(', '').replace(')', '')}, 0.1)`
                });
            } else if (diff <= warningThreshold) {
                regions.push({
                    x1: index,
                    x2: index + 1,
                    fill: `rgba(${colors.warning.replace('rgb(', '').replace(')', '')}, 0.1)`
                });
            }
        });
        return regions;
    };

    if (!data?.length) return null;

    // Check data format
    const isDemandCapacityFormat = 'demand' in data[0] && 'capacity' in data[0];
    const hasSingleValue = 'value' in data[0];
    const isStaffingFormat = 'staffed' in data[0] && 'unstaffed' in data[0];
    const isVitalsFormat = !isDemandCapacityFormat && !hasSingleValue && !isStaffingFormat;

    // Get all data keys except 'month' for vitals format
    const vitalsMetrics = isVitalsFormat 
        ? Object.keys(data[0]).filter(key => key !== 'month')
        : [];

    // Calculate average for single value format
    const average = hasSingleValue 
        ? data.reduce((acc, curr) => acc + curr.value, 0) / data.length
        : null;

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
                    role="tooltip"
                    aria-live="polite"
                >
                    <p className="font-medium">{label}</p>
                    <div className="mt-2 space-y-1">
                        {isVitalsFormat ? (
                            payload.map((entry, index) => (
                                <p key={index} className="text-sm" style={{ color: entry.color }}>
                                    {entry.name}: {entry.value}
                                </p>
                            ))
                        ) : isDemandCapacityFormat ? (
                            payload.map((entry, index) => (
                                <p key={index} className="text-sm" style={{ color: entry.color }}>
                                    {entry.name}: {entry.value}
                                </p>
                            ))
                        ) : isStaffingFormat ? (
                            payload.map((entry, index) => (
                                <p key={index} className="text-sm" style={{ color: entry.color }}>
                                    {entry.name}: {entry.value}%
                                </p>
                            ))
                        ) : hasSingleValue && (
                            <>
                                <p className="text-sm">
                                    Utilization: 
                                    <span className="font-medium ml-1">{data.value}%</span>
                                    <span 
                                        className="ml-2 text-xs"
                                        style={{
                                            color: data.value >= target ? colors.success : colors.critical
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
                            </>
                        )}
                    </div>
                </div>
            );
        }
        return null;
    };

    return (
        <div 
            className="w-full" 
            style={{ height: `${height}px`, backgroundColor }}
            role="img" 
            aria-label={ariaLabel}
        >
            <ResponsiveContainer width="100%" height="100%">
                <RechartsLineChart
                    data={data}
                    margin={{
                        top: 30,
                        right: 40,
                        left: 40,
                        bottom: 80
                    }}
                    role="presentation"
                >
                    <defs>
                        {isDemandCapacityFormat ? (
                            <>
                                <linearGradient id="colorDemand" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stopColor={colors.warning} stopOpacity={0.3}/>
                                    <stop offset="100%" stopColor={colors.warning} stopOpacity={0.1}/>
                                </linearGradient>
                                <linearGradient id="colorCapacity" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stopColor={colors.info} stopOpacity={0.3}/>
                                    <stop offset="100%" stopColor={colors.info} stopOpacity={0.1}/>
                                </linearGradient>
                            </>
                        ) : (
                            <>
                                <linearGradient id="colorValue" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stopColor={colors.info} stopOpacity={0.3}/>
                                    <stop offset="100%" stopColor={colors.info} stopOpacity={0.1}/>
                                </linearGradient>
                                <linearGradient id="colorStaffed" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stopColor={colors.success} stopOpacity={0.3}/>
                                    <stop offset="100%" stopColor={colors.success} stopOpacity={0.1}/>
                                </linearGradient>
                                <linearGradient id="colorUnstaffed" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stopColor={colors.warning} stopOpacity={0.3}/>
                                    <stop offset="100%" stopColor={colors.warning} stopOpacity={0.1}/>
                                </linearGradient>
                            </>
                        )}
                    </defs>
                    <CartesianGrid 
                        strokeDasharray="3 3" 
                        stroke={gridStrokeColor} 
                        strokeOpacity={isDarkMode ? 0.2 : 1}
                    />
                    {isDemandCapacityFormat && getThresholdRegions().map((region, index) => (
                        <rect
                            key={index}
                            x={region.x1}
                            width={region.x2 - region.x1}
                            y={0}
                            height="100%"
                            fill={region.fill}
                            className="threshold-region"
                        />
                    ))}
                    <XAxis 
                        dataKey={isDemandCapacityFormat ? "date" : "month"}
                        angle={-45}
                        textAnchor="end"
                        height={60}
                        interval={0}
                        tick={{ fill: axisTickColor, fontSize: 14 }}
                        tickSize={10}
                    />
                    <YAxis 
                        domain={isVitalsFormat ? ['auto', 'auto'] : ['dataMin - 10', 'dataMax + 10']}
                        tick={{ fill: axisTickColor, fontSize: 14 }}
                        tickSize={10}
                        width={60}
                        label={{ 
                            value: isVitalsFormat ? 'Value' : 'Beds',
                            angle: -90,
                            position: 'insideLeft',
                            style: { fill: axisTickColor }
                        }}
                        role="presentation"
                        aria-label={isVitalsFormat ? "Vital sign values" : "Number of beds"}
                    />
                    <Tooltip 
                        content={<CustomTooltip />}
                        role="tooltip"
                        aria-live="polite"
                    />
                    <Legend 
                        wrapperStyle={{ 
                            fontSize: '14px', 
                            color: tooltipTextColor,
                            paddingBottom: '20px'
                        }}
                        verticalAlign="top"
                        role="list"
                        formatter={(value) => (
                            <span role="listitem" style={{ color: tooltipTextColor }}>
                                {value}
                            </span>
                        )}
                    />
                    
                    {isVitalsFormat && vitalsMetrics.map((metric, index) => {
                        const colors = ['#ef4444', '#3b82f6', '#10b981', '#f59e0b'];
                        return (
                            <Area
                                key={metric}
                                type="monotone"
                                dataKey={metric}
                                name={metric}
                                stroke={colors[index % colors.length]}
                                strokeWidth={2}
                                dot={{ r: 4, strokeWidth: 2 }}
                                activeDot={{ r: 6, strokeWidth: 2 }}
                            />
                        );
                    })}

                    {hasSingleValue && (
                        <>
                            {target && (
                                <ReferenceLine 
                                    y={target} 
                                    stroke={colors.critical}
                                    strokeDasharray="3 3"
                                    label={{ 
                                        value: 'Target', 
                                        position: 'right',
                                        fill: colors.critical,
                                        fontSize: 14
                                    }}
                                />
                            )}
                            {average && (
                                <ReferenceLine 
                                    y={average} 
                                    stroke={colors.info}
                                    strokeDasharray="3 3"
                                    label={{ 
                                        value: 'Average', 
                                        position: 'right',
                                        fill: colors.info,
                                        fontSize: 14
                                    }}
                                />
                            )}
                            <Area
                                type="monotone"
                                dataKey="value"
                                stroke={colors.info}
                                strokeWidth={3}
                                fill="url(#colorValue)"
                                dot={{ r: 6, strokeWidth: 2, fill: colors.info }}
                                activeDot={{ r: 8, strokeWidth: 2, fill: colors.info }}
                            />
                        </>
                    )}
                    
                    {isDemandCapacityFormat && (
                        <>
                            <Area
                                type="monotone"
                                dataKey="demand"
                                name="Predicted Demand"
                                stroke={colors.warning}
                                strokeWidth={3}
                                fill="url(#colorDemand)"
                                dot={{ r: 6, strokeWidth: 2, fill: colors.warning }}
                                activeDot={{ r: 8, strokeWidth: 2, fill: colors.warning }}
                            />
                            <Area
                                type="monotone"
                                dataKey="capacity"
                                name="Available Capacity"
                                stroke={colors.info}
                                strokeWidth={3}
                                fill="url(#colorCapacity)"
                                dot={{ r: 6, strokeWidth: 2, fill: colors.info }}
                                activeDot={{ r: 8, strokeWidth: 2, fill: colors.info }}
                            />
                        </>
                    )}

                    {isStaffingFormat && (
                        <>
                            <Area
                                type="monotone"
                                dataKey="staffed"
                                name="Staffed"
                                stroke={colors.success}
                                strokeWidth={3}
                                fill="url(#colorStaffed)"
                                dot={{ r: 6, strokeWidth: 2, fill: colors.success }}
                                activeDot={{ r: 8, strokeWidth: 2, fill: colors.success }}
                            />
                            <Area
                                type="monotone"
                                dataKey="unstaffed"
                                name="Unstaffed"
                                stroke={colors.warning}
                                strokeWidth={3}
                                fill="url(#colorUnstaffed)"
                                dot={{ r: 6, strokeWidth: 2, fill: colors.warning }}
                                activeDot={{ r: 8, strokeWidth: 2, fill: colors.warning }}
                            />
                        </>
                    )}
                </RechartsLineChart>
            </ResponsiveContainer>
        </div>
    );
};

export default LineChart;
