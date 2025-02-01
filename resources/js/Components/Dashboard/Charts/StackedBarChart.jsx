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
    accuracyTarget = 80, // Target percentage for accurate scheduling
    maxVariance = 15 // Maximum acceptable variance percentage
}) => {
    // Transform and sort data
    const chartData = Object.entries(data)
        .map(([service, values]) => ({
            service,
            accurate: values.accurate,
            under: values.under,
            over: values.over,
            total: values.accurate + values.under + values.over,
            variance: values.under + values.over
        }))
        .sort((a, b) => b.accurate - a.accurate); // Sort by accuracy

    // Calculate averages
    const avgAccuracy = chartData.reduce((acc, curr) => acc + curr.accurate, 0) / chartData.length;
    const avgVariance = chartData.reduce((acc, curr) => acc + curr.variance, 0) / chartData.length;

    // Custom tooltip component
    const CustomTooltip = ({ active, payload, label }) => {
        if (active && payload && payload.length) {
            const data = payload[0].payload;
            const totalCases = data.total;
            return (
                <div className="bg-white p-3 shadow-lg rounded-lg border border-gray-200">
                    <p className="font-medium text-gray-900">{label}</p>
                    <div className="mt-2 space-y-1">
                        <div>
                            <p className="text-sm text-gray-600">
                                Accurate: 
                                <span className="font-medium ml-1">{data.accurate}%</span>
                                <span className={`ml-2 text-xs ${
                                    data.accurate >= accuracyTarget ? 'text-green-600' : 'text-red-600'
                                }`}>
                                    ({data.accurate >= accuracyTarget ? '✓' : `${accuracyTarget - data.accurate}% below target`})
                                </span>
                            </p>
                        </div>
                        <div>
                            <p className="text-sm text-gray-600">
                                Underscheduled: 
                                <span className="font-medium ml-1">{data.under}%</span>
                                <span className={`ml-2 text-xs ${
                                    data.under <= maxVariance ? 'text-green-600' : 'text-red-600'
                                }`}>
                                    ({data.under <= maxVariance ? '✓' : `${data.under - maxVariance}% over limit`})
                                </span>
                            </p>
                        </div>
                        <div>
                            <p className="text-sm text-gray-600">
                                Overscheduled: 
                                <span className="font-medium ml-1">{data.over}%</span>
                                <span className={`ml-2 text-xs ${
                                    data.over <= maxVariance ? 'text-green-600' : 'text-red-600'
                                }`}>
                                    ({data.over <= maxVariance ? '✓' : `${data.over - maxVariance}% over limit`})
                                </span>
                            </p>
                        </div>
                        <div className="pt-1 border-t">
                            <p className="text-sm text-gray-600">
                                Total Cases: <span className="font-medium">{totalCases}</span>
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
                        top: 20,
                        right: 30,
                        left: 20,
                        bottom: 60
                    }}
                    stackOffset="expand"
                    barSize={30}
                >
                    <defs>
                        <linearGradient id="accurateGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#22C55E" stopOpacity={0.8}/>
                            <stop offset="100%" stopColor="#22C55E" stopOpacity={0.3}/>
                        </linearGradient>
                        <linearGradient id="underGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#F59E0B" stopOpacity={0.8}/>
                            <stop offset="100%" stopColor="#F59E0B" stopOpacity={0.3}/>
                        </linearGradient>
                        <linearGradient id="overGradient" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#EF4444" stopOpacity={0.8}/>
                            <stop offset="100%" stopColor="#EF4444" stopOpacity={0.3}/>
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
                        tickFormatter={(value) => `${Math.round(value * 100)}%`}
                        tick={{ fill: '#6B7280', fontSize: 12 }}
                    />
                    <Tooltip content={<CustomTooltip />} />
                    <Legend 
                        payload={[
                            { value: 'Accurate', type: 'rect', color: '#22C55E' },
                            { value: 'Underscheduled', type: 'rect', color: '#F59E0B' },
                            { value: 'Overscheduled', type: 'rect', color: '#EF4444' }
                        ]}
                    />
                    
                    {/* Target line for accuracy */}
                    <ReferenceLine 
                        y={accuracyTarget / 100} 
                        stroke="#22C55E"
                        strokeDasharray="3 3"
                        label={{ 
                            value: 'Accuracy Target', 
                            position: 'right',
                            fill: '#22C55E',
                            fontSize: 12
                        }}
                    />
                    
                    <Bar 
                        dataKey="accurate" 
                        stackId="a" 
                        fill="url(#accurateGradient)"
                        name="Accurate"
                    />
                    <Bar 
                        dataKey="under" 
                        stackId="a" 
                        fill="url(#underGradient)"
                        name="Underscheduled"
                    />
                    <Bar 
                        dataKey="over" 
                        stackId="a" 
                        fill="url(#overGradient)"
                        name="Overscheduled"
                    />
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
};

export default StackedBarChart;
