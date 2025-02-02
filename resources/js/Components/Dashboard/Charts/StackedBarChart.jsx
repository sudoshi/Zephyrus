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
    ReferenceLine
} from 'recharts';
import { useDarkMode, HEALTHCARE_COLORS } from '@/hooks/useDarkMode';

const StackedBarChart = ({ 
    data,
    height = 300,
    target = 80 // Target percentage for accuracy
}) => {
    const [isDarkMode] = useDarkMode();
    const colors = HEALTHCARE_COLORS[isDarkMode ? 'dark' : 'light'];

    const gridStrokeColor = colors.border;
    const axisTickColor = colors.text.secondary;
    const tooltipBgColor = colors.surface;
    const tooltipTextColor = colors.text.primary;
    const targetLineColor = colors.info;
    const positiveVarianceColor = colors.success;
    const negativeVarianceColor = colors.critical;
    const warningColor = colors.warning;

    // Transform and sort data
    const chartData = Object.entries(data)
        .map(([service, values]) => ({
            service,
            under: values.under,
            accurate: values.accurate,
            over: values.over,
            total: values.under + values.accurate + values.over
        }))
        .sort((a, b) => b.accurate - a.accurate); // Sort by accuracy

    // Calculate average accuracy
    const avgAccuracy = chartData.reduce((acc, curr) => acc + curr.accurate, 0) / chartData.length;

    // Custom tooltip component
    const CustomTooltip = ({ active, payload, label }) => {
        if (active && payload && payload.length) {
            const data = payload[0].payload;
            const total = data.under + data.accurate + data.over;
            
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
                        <div>
                            <p className="text-sm">
                                Accurate: 
                                <span className="font-medium ml-1">{data.accurate}%</span>
                                <span 
                                    className="ml-2 text-xs"
                                    style={{
                                        color: data.accurate >= target ? positiveVarianceColor : negativeVarianceColor
                                    }}
                                >
                                    ({data.accurate >= target ? '✓' : `${(target - data.accurate).toFixed(1)}% below target`})
                                </span>
                            </p>
                        </div>
                        <div>
                            <p className="text-sm">
                                Under Scheduled: 
                                <span className="font-medium ml-1">{data.under}%</span>
                            </p>
                        </div>
                        <div>
                            <p className="text-sm">
                                Over Scheduled: 
                                <span className="font-medium ml-1">{data.over}%</span>
                            </p>
                        </div>
                        <div className="pt-1 border-t" style={{ borderColor: colors.border }}>
                            <p className="text-sm">
                                Total: 
                                <span className="font-medium ml-1">{total}%</span>
                            </p>
                        </div>
                    </div>
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
                        <linearGradient id="underGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor={negativeVarianceColor} stopOpacity={0.8}/>
                            <stop offset="100%" stopColor={negativeVarianceColor} stopOpacity={0.3}/>
                        </linearGradient>
                        <linearGradient id="accurateGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor={positiveVarianceColor} stopOpacity={0.8}/>
                            <stop offset="100%" stopColor={positiveVarianceColor} stopOpacity={0.3}/>
                        </linearGradient>
                        <linearGradient id="overGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor={warningColor} stopOpacity={0.8}/>
                            <stop offset="100%" stopColor={warningColor} stopOpacity={0.3}/>
                        </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke={gridStrokeColor} />
                    <XAxis 
                        dataKey="service"
                        angle={-45}
                        textAnchor="end"
                        height={60}
                        interval={0}
                        tick={{ fill: axisTickColor, fontSize: 14 }}
                        tickSize={10}
                    />
                    <YAxis 
                        label={{ 
                            value: 'Percentage', 
                            angle: -90, 
                            position: 'insideLeft',
                            fill: axisTickColor,
                            fontSize: 14
                        }}
                        tick={{ fill: axisTickColor, fontSize: 14 }}
                        tickSize={10}
                        width={60}
                    />
                    <Tooltip content={<CustomTooltip />} />
                    <Legend 
                        payload={[
                            { value: 'Under Scheduled', type: 'rect', color: negativeVarianceColor },
                            { value: 'Accurate', type: 'rect', color: positiveVarianceColor },
                            { value: 'Over Scheduled', type: 'rect', color: warningColor }
                        ]}
                        wrapperStyle={{ fontSize: '14px', color: tooltipTextColor }}
                    />
                    
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
                    
                    <Bar 
                        dataKey="under" 
                        stackId="a" 
                        fill="url(#underGradient)" 
                        radius={[6, 6, 0, 0]}
                        maxBarSize={60}
                        name="Under Scheduled"
                    />
                    <Bar 
                        dataKey="accurate" 
                        stackId="a" 
                        fill="url(#accurateGradient)" 
                        radius={[0, 0, 0, 0]}
                        maxBarSize={60}
                        name="Accurate"
                    />
                    <Bar 
                        dataKey="over" 
                        stackId="a" 
                        fill="url(#overGradient)" 
                        radius={[0, 0, 0, 0]}
                        maxBarSize={60}
                        name="Over Scheduled"
                    />
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
};

export default StackedBarChart;
