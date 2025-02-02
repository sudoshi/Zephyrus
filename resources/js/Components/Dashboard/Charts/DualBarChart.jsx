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

const DualBarChart = ({ 
    data,
    height = 300,
    roomTarget = 45, // Target minutes for room turnover
    procedureTarget = 30 // Target minutes for procedure turnover
}) => {
    const [isDarkMode] = useDarkMode();

    // Define colors based on the current theme
    const colors = HEALTHCARE_COLORS[isDarkMode ? 'dark' : 'light'];

    const gridStrokeColor = colors.border;
    const axisTickColor = colors.text.secondary;
    const tooltipBgColor = colors.surface;
    const tooltipTextColor = colors.text.primary;
    const targetLineColorRoom = colors.critical;
    const targetLineColorProcedure = colors.warning;
    const positiveVarianceColor = colors.success;
    const negativeVarianceColor = colors.critical;

    // Transform and sort data
    const chartData = Object.entries(data)
        .map(([service, values]) => ({
            service,
            room: values.room,
            procedure: values.procedure,
            total: values.room + values.procedure
        }))
        .sort((a, b) => b.total - a.total); // Sort by total time

    // Calculate averages
    const roomAvg = chartData.reduce((acc, curr) => acc + curr.room, 0) / chartData.length;
    const procAvg = chartData.reduce((acc, curr) => acc + curr.procedure, 0) / chartData.length;

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
                        <div>
                            <p className="text-sm">
                                Room Turnover: 
                                <span className="font-medium ml-1">{data.room} min</span>
                                <span
                                    className={`ml-2 text-xs ${
                                        data.room <= roomTarget
                                            ? 'text-green-600'
                                            : 'text-red-600'
                                    }`}
                                    style={{
                                        color: data.room <= roomTarget
                                            ? positiveVarianceColor
                                            : negativeVarianceColor
                                    }}
                                >
                                    ({data.room <= roomTarget ? '✓' : `+${data.room - roomTarget}`})
                                </span>
                            </p>
                            <p className="text-xs">Target: {roomTarget} min</p>
                        </div>
                        <div>
                            <p className="text-sm">
                                Procedure Turnover: 
                                <span className="font-medium ml-1">{data.procedure} min</span>
                                <span
                                    className={`ml-2 text-xs ${
                                        data.procedure <= procedureTarget
                                            ? 'text-green-600'
                                            : 'text-red-600'
                                    }`}
                                    style={{
                                        color: data.procedure <= procedureTarget
                                            ? positiveVarianceColor
                                            : negativeVarianceColor
                                    }}
                                >
                                    ({data.procedure <= procedureTarget ? '✓' : `+${data.procedure - procedureTarget}`})
                                </span>
                            </p>
                            <p className="text-xs">Target: {procedureTarget} min</p>
                        </div>
                        <div className="pt-1 border-t" style={{ borderColor: colors.border }}>
                            <p className="text-sm">
                                Total Time: 
                                <span className="font-medium ml-1">{data.total} min</span>
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
                        <linearGradient id="roomGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor={colors.info} stopOpacity={0.8}/>
                            <stop offset="100%" stopColor={colors.info} stopOpacity={0.3}/>
                        </linearGradient>
                        <linearGradient id="procGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor={colors.warning} stopOpacity={0.8}/>
                            <stop offset="100%" stopColor={colors.warning} stopOpacity={0.3}/>
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
                            value: 'Minutes', 
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
                            { value: 'Room Turnover', type: 'rect', color: colors.info },
                            { value: 'Procedure Turnover', type: 'rect', color: colors.warning }
                        ]}
                        wrapperStyle={{ fontSize: '14px', color: tooltipTextColor }}
                    />
                    
                    {/* Room turnover target */}
                    <ReferenceLine 
                        y={roomTarget} 
                        stroke={targetLineColorRoom}
                        strokeDasharray="3 3"
                        label={{ 
                            value: 'Room Target', 
                            position: 'right',
                            fill: targetLineColorRoom,
                            fontSize: 14
                        }}
                    />
                    
                    {/* Procedure turnover target */}
                    <ReferenceLine 
                        y={procedureTarget} 
                        stroke={targetLineColorProcedure}
                        strokeDasharray="3 3"
                        label={{ 
                            value: 'Proc Target', 
                            position: 'right',
                            fill: targetLineColorProcedure,
                            fontSize: 14
                        }}
                    />
                    
                    <Bar 
                        dataKey="room" 
                        fill="url(#roomGradient)" 
                        radius={[6, 6, 0, 0]}
                        maxBarSize={60}
                        name="Room Turnover"
                    />
                    <Bar 
                        dataKey="procedure" 
                        fill="url(#procGradient)" 
                        radius={[6, 6, 0, 0]}
                        maxBarSize={60}
                        name="Procedure Turnover"
                    />
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
};

export default DualBarChart;
