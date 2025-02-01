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

const LineChart = ({ 
    data,
    height = 300,
    target = 80 // Target percentage
}) => {
    // Calculate average
    const average = data.reduce((acc, curr) => acc + curr.value, 0) / data.length;

    // Custom tooltip component
    const CustomTooltip = ({ active, payload, label }) => {
        if (active && payload && payload.length) {
            const data = payload[0].payload;
            return (
                <div className="bg-white p-3 shadow-lg rounded-lg border border-gray-200">
                    <p className="font-medium text-gray-900">{label}</p>
                    <div className="mt-2 space-y-1">
                        <p className="text-sm text-gray-600">
                            Utilization: 
                            <span className="font-medium ml-1">{data.value}%</span>
                            <span className={`ml-2 text-xs ${
                                data.value >= target ? 'text-green-600' : 'text-red-600'
                            }`}>
                                ({data.value >= target ? '✓' : `${target - data.value}% below target`})
                            </span>
                        </p>
                        <p className="text-xs text-gray-500">Target: {target}%</p>
                        {data.breakdown && (
                            <div className="mt-2 pt-2 border-t">
                                <p className="text-xs font-medium text-gray-700 mb-1">Breakdown:</p>
                                {Object.entries(data.breakdown).map(([key, value]) => (
                                    <p key={key} className="text-xs text-gray-600">
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
        <div className="w-full" style={{ height: `${height}px` }}>
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
                            <stop offset="0%" stopColor="#4F46E5" stopOpacity={0.3}/>
                            <stop offset="100%" stopColor="#4F46E5" stopOpacity={0.1}/>
                        </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="#E5E7EB" />
                    <XAxis 
                        dataKey="date"
                        angle={-45}
                        textAnchor="end"
                        height={60}
                        interval={0}
                        tick={{ fill: '#6B7280', fontSize: 14 }}
                        tickSize={10}
                    />
                    <YAxis 
                        domain={[0, 100]}
                        tickFormatter={(value) => `${value}%`}
                        tick={{ fill: '#6B7280', fontSize: 14 }}
                        tickSize={10}
                        width={60}
                    />
                    <Tooltip content={<CustomTooltip />} />
                    <Legend wrapperStyle={{ fontSize: '14px' }} />
                    
                    {/* Target line */}
                    <ReferenceLine 
                        y={target} 
                        stroke="#DC2626"
                        strokeDasharray="3 3"
                        label={{ 
                            value: 'Target', 
                            position: 'right',
                            fill: '#DC2626',
                            fontSize: 14
                        }}
                    />
                    
                    {/* Average line */}
                    <ReferenceLine 
                        y={average} 
                        stroke="#2563EB"
                        strokeDasharray="3 3"
                        label={{ 
                            value: 'Average', 
                            position: 'right',
                            fill: '#2563EB',
                            fontSize: 14
                        }}
                    />
                    
                    <Area
                        type="monotone"
                        dataKey="value"
                        stroke="#4F46E5"
                        strokeWidth={3}
                        fill="url(#colorValue)"
                        dot={{ r: 6, strokeWidth: 2 }}
                        activeDot={{ r: 8, strokeWidth: 2 }}
                    />
                </RechartsLineChart>
            </ResponsiveContainer>
        </div>
    );
};

export default LineChart;
