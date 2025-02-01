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

const ServiceBarChart = ({ 
    data, 
    firstCase = false,
    target = 80, // Target percentage
    height = 300
}) => {
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

    // Custom gradient definition
    const gradientOffset = () => {
        const dataMax = Math.max(...chartData.map(item => item.value));
        const dataMin = Math.min(...chartData.map(item => item.value));
        
        if (dataMax <= 0) return 0;
        if (dataMin >= 0) return 1;
        
        return dataMax / (dataMax - dataMin);
    };

    // Custom tooltip component
    const CustomTooltip = ({ active, payload, label }) => {
        if (active && payload && payload.length) {
            const data = payload[0].payload;
            return (
                <div className="bg-white p-3 shadow-lg rounded-lg border border-gray-200">
                    <p className="font-medium text-gray-900">{label}</p>
                    <p className="text-sm text-gray-600">
                        {firstCase ? 'First Case %' : 'All Cases %'}: 
                        <span className="font-medium ml-1">{data.value}%</span>
                    </p>
                    <p className="text-sm text-gray-600">
                        Target: <span className="font-medium">{target}%</span>
                    </p>
                    <p className="text-sm text-gray-600">
                        Variance: 
                        <span className={`font-medium ml-1 ${
                            data.value >= target ? 'text-green-600' : 'text-red-600'
                        }`}>
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
                        top: 20,
                        right: 30,
                        left: 20,
                        bottom: 60
                    }}
                >
                    <defs>
                        <linearGradient id="colorValue" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#4F46E5" stopOpacity={0.8}/>
                            <stop offset="100%" stopColor="#4F46E5" stopOpacity={0.3}/>
                        </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="#E5E7EB" />
                    <XAxis 
                        dataKey="service"
                        angle={-45}
                        textAnchor="end"
                        height={60}
                        interval={0}
                        tick={{ fill: '#6B7280', fontSize: 12 }}
                    />
                    <YAxis 
                        domain={[0, 100]}
                        tickFormatter={(value) => `${value}%`}
                        tick={{ fill: '#6B7280', fontSize: 12 }}
                    />
                    <Tooltip content={<CustomTooltip />} />
                    <Legend />
                    
                    {/* Target line */}
                    <ReferenceLine 
                        y={target} 
                        stroke="#DC2626"
                        strokeDasharray="3 3"
                        label={{ 
                            value: 'Target', 
                            position: 'right',
                            fill: '#DC2626'
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
                            fill: '#2563EB'
                        }}
                    />
                    
                    <Bar 
                        dataKey="value" 
                        fill="url(#colorValue)"
                        radius={[4, 4, 0, 0]}
                    >
                        {chartData.map((entry, index) => (
                            <Cell 
                                key={`cell-${index}`}
                                fill={entry.value >= target ? 'url(#colorValue)' : '#F87171'}
                            />
                        ))}
                    </Bar>
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
};

export default ServiceBarChart;
