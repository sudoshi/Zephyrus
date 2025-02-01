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

const DualBarChart = ({ 
    data,
    height = 300,
    roomTarget = 45, // Target minutes for room turnover
    procedureTarget = 30 // Target minutes for procedure turnover
}) => {
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
                <div className="bg-white p-3 shadow-lg rounded-lg border border-gray-200">
                    <p className="font-medium text-gray-900">{label}</p>
                    <div className="mt-2 space-y-1">
                        <div>
                            <p className="text-sm text-gray-600">
                                Room Turnover: 
                                <span className="font-medium ml-1">{data.room} min</span>
                                <span className={`ml-2 text-xs ${
                                    data.room <= roomTarget ? 'text-green-600' : 'text-red-600'
                                }`}>
                                    ({data.room <= roomTarget ? '✓' : `+${data.room - roomTarget}`})
                                </span>
                            </p>
                            <p className="text-xs text-gray-500">Target: {roomTarget} min</p>
                        </div>
                        <div>
                            <p className="text-sm text-gray-600">
                                Procedure Turnover: 
                                <span className="font-medium ml-1">{data.procedure} min</span>
                                <span className={`ml-2 text-xs ${
                                    data.procedure <= procedureTarget ? 'text-green-600' : 'text-red-600'
                                }`}>
                                    ({data.procedure <= procedureTarget ? '✓' : `+${data.procedure - procedureTarget}`})
                                </span>
                            </p>
                            <p className="text-xs text-gray-500">Target: {procedureTarget} min</p>
                        </div>
                        <div className="pt-1 border-t">
                            <p className="text-sm text-gray-600">
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
                            <stop offset="0%" stopColor="#4F46E5" stopOpacity={0.8}/>
                            <stop offset="100%" stopColor="#4F46E5" stopOpacity={0.3}/>
                        </linearGradient>
                        <linearGradient id="procGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#EC4899" stopOpacity={0.8}/>
                            <stop offset="100%" stopColor="#EC4899" stopOpacity={0.3}/>
                        </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="#E5E7EB" />
                    <XAxis 
                        dataKey="service"
                        angle={-45}
                        textAnchor="end"
                        height={60}
                        interval={0}
                        tick={{ fill: '#6B7280', fontSize: 14 }}
                        tickSize={10}
                    />
                    <YAxis 
                        label={{ 
                            value: 'Minutes', 
                            angle: -90, 
                            position: 'insideLeft',
                            fill: '#6B7280',
                            fontSize: 14
                        }}
                        tick={{ fill: '#6B7280', fontSize: 14 }}
                        tickSize={10}
                        width={60}
                    />
                    <Tooltip content={<CustomTooltip />} />
                    <Legend 
                        payload={[
                            { value: 'Room Turnover', type: 'rect', color: '#4F46E5' },
                            { value: 'Procedure Turnover', type: 'rect', color: '#EC4899' }
                        ]}
                        wrapperStyle={{ fontSize: '14px' }}
                    />
                    
                    {/* Room turnover target */}
                    <ReferenceLine 
                        y={roomTarget} 
                        stroke="#DC2626"
                        strokeDasharray="3 3"
                        label={{ 
                            value: 'Room Target', 
                            position: 'right',
                            fill: '#DC2626',
                            fontSize: 14
                        }}
                    />
                    
                    {/* Procedure turnover target */}
                    <ReferenceLine 
                        y={procedureTarget} 
                        stroke="#EA580C"
                        strokeDasharray="3 3"
                        label={{ 
                            value: 'Proc Target', 
                            position: 'right',
                            fill: '#EA580C',
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
