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

const StackedBarChart = ({ 
    data,
    height = 300,
    target = 80 // Target percentage for accuracy
}) => {
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
                <div className="bg-white p-3 shadow-lg rounded-lg border border-gray-200">
                    <p className="font-medium text-gray-900">{label}</p>
                    <div className="mt-2 space-y-1">
                        <div>
                            <p className="text-sm text-gray-600">
                                Accurate: 
                                <span className="font-medium ml-1">{data.accurate}%</span>
                                <span className={`ml-2 text-xs ${
                                    data.accurate >= target ? 'text-green-600' : 'text-red-600'
                                }`}>
                                    ({data.accurate >= target ? '✓' : `${target - data.accurate}% below target`})
                                </span>
                            </p>
                        </div>
                        <div>
                            <p className="text-sm text-gray-600">
                                Under Scheduled: 
                                <span className="font-medium ml-1">{data.under}%</span>
                            </p>
                        </div>
                        <div>
                            <p className="text-sm text-gray-600">
                                Over Scheduled: 
                                <span className="font-medium ml-1">{data.over}%</span>
                            </p>
                        </div>
                        <div className="pt-1 border-t">
                            <p className="text-sm text-gray-600">
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
                            <stop offset="0%" stopColor="#EF4444" stopOpacity={0.8}/>
                            <stop offset="100%" stopColor="#EF4444" stopOpacity={0.3}/>
                        </linearGradient>
                        <linearGradient id="accurateGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#10B981" stopOpacity={0.8}/>
                            <stop offset="100%" stopColor="#10B981" stopOpacity={0.3}/>
                        </linearGradient>
                        <linearGradient id="overGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#F59E0B" stopOpacity={0.8}/>
                            <stop offset="100%" stopColor="#F59E0B" stopOpacity={0.3}/>
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
                            value: 'Percentage', 
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
                            { value: 'Under Scheduled', type: 'rect', color: '#EF4444' },
                            { value: 'Accurate', type: 'rect', color: '#10B981' },
                            { value: 'Over Scheduled', type: 'rect', color: '#F59E0B' }
                        ]}
                        wrapperStyle={{ fontSize: '14px' }}
                    />
                    
                    {/* Target line */}
                    <ReferenceLine 
                        y={target} 
                        stroke="#4F46E5"
                        strokeDasharray="3 3"
                        label={{ 
                            value: 'Target', 
                            position: 'right',
                            fill: '#4F46E5',
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
