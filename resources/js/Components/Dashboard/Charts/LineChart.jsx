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
    target = 85, // Target utilization percentage
    minAcceptable = 70 // Minimum acceptable utilization
}) => {
    // Calculate averages
    const staffedAvg = data.reduce((acc, curr) => acc + curr.staffed, 0) / data.length;
    const unstaffedAvg = data.reduce((acc, curr) => acc + curr.unstaffed, 0) / data.length;

    // Custom tooltip component
    const CustomTooltip = ({ active, payload, label }) => {
        if (active && payload && payload.length) {
            return (
                <div className="bg-white p-3 shadow-lg rounded-lg border border-gray-200">
                    <p className="font-medium text-gray-900">{label}</p>
                    <div className="mt-2 space-y-1">
                        <div>
                            <p className="text-sm text-gray-600">
                                Staffed Utilization: 
                                <span className="font-medium ml-1">{payload[0].value}%</span>
                                <span className={`ml-2 text-xs ${
                                    payload[0].value >= target ? 'text-green-600' : 
                                    payload[0].value >= minAcceptable ? 'text-yellow-600' : 
                                    'text-red-600'
                                }`}>
                                    {payload[0].value >= target ? '✓' : 
                                     payload[0].value >= minAcceptable ? '⚠' : 
                                     '✗'}
                                </span>
                            </p>
                        </div>
                        <div>
                            <p className="text-sm text-gray-600">
                                Unstaffed Utilization: 
                                <span className="font-medium ml-1">{payload[1].value}%</span>
                            </p>
                        </div>
                        <div className="pt-1 border-t">
                            <p className="text-xs text-gray-500">
                                Target: {target}% | Minimum: {minAcceptable}%
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
                <RechartsLineChart
                    data={data}
                    margin={{
                        top: 20,
                        right: 30,
                        left: 20,
                        bottom: 20
                    }}
                >
                    <defs>
                        <linearGradient id="staffedGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#4F46E5" stopOpacity={0.3}/>
                            <stop offset="100%" stopColor="#4F46E5" stopOpacity={0.1}/>
                        </linearGradient>
                        <linearGradient id="unstaffedGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#EC4899" stopOpacity={0.3}/>
                            <stop offset="100%" stopColor="#EC4899" stopOpacity={0.1}/>
                        </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="#E5E7EB" />
                    <XAxis 
                        dataKey="month"
                        angle={-45}
                        textAnchor="end"
                        height={60}
                        interval={0}
                        tick={{ fill: '#6B7280', fontSize: 12 }}
                    />
                    <YAxis 
                        domain={[50, 100]}
                        tickFormatter={(value) => `${value}%`}
                        tick={{ fill: '#6B7280', fontSize: 12 }}
                    />
                    <Tooltip content={<CustomTooltip />} />
                    <Legend 
                        payload={[
                            { value: 'Staffed Utilization', type: 'line', color: '#4F46E5' },
                            { value: 'Unstaffed Utilization', type: 'line', color: '#EC4899' }
                        ]}
                    />
                    
                    {/* Target line */}
                    <ReferenceLine 
                        y={target} 
                        stroke="#22C55E"
                        strokeDasharray="3 3"
                        label={{ 
                            value: 'Target', 
                            position: 'right',
                            fill: '#22C55E',
                            fontSize: 12
                        }}
                    />
                    
                    {/* Minimum acceptable line */}
                    <ReferenceLine 
                        y={minAcceptable} 
                        stroke="#DC2626"
                        strokeDasharray="3 3"
                        label={{ 
                            value: 'Minimum', 
                            position: 'right',
                            fill: '#DC2626',
                            fontSize: 12
                        }}
                    />
                    
                    {/* Area fills */}
                    <Area
                        type="monotone"
                        dataKey="staffed"
                        stroke="none"
                        fill="url(#staffedGradient)"
                    />
                    <Area
                        type="monotone"
                        dataKey="unstaffed"
                        stroke="none"
                        fill="url(#unstaffedGradient)"
                    />
                    
                    {/* Lines */}
                    <Line 
                        type="monotone" 
                        dataKey="staffed" 
                        stroke="#4F46E5" 
                        strokeWidth={2}
                        dot={{ r: 4, fill: '#4F46E5' }}
                        activeDot={{ r: 6, fill: '#4F46E5' }}
                        name="Staffed Utilization"
                    />
                    <Line 
                        type="monotone" 
                        dataKey="unstaffed" 
                        stroke="#EC4899" 
                        strokeWidth={2}
                        dot={{ r: 4, fill: '#EC4899' }}
                        activeDot={{ r: 6, fill: '#EC4899' }}
                        name="Unstaffed Utilization"
                    />
                </RechartsLineChart>
            </ResponsiveContainer>
        </div>
    );
};

export default LineChart;
