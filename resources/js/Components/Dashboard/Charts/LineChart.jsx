import React from 'react';
import { LineChart as RechartsLineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

const LineChart = ({ data }) => {
    return (
        <div className="w-full h-[300px]">
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
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis 
                        dataKey="month"
                        angle={-45}
                        textAnchor="end"
                        height={60}
                        interval={0}
                    />
                    <YAxis 
                        domain={[50, 100]}
                        tickFormatter={(value) => `${value}%`}
                    />
                    <Tooltip 
                        formatter={(value) => [`${value}%`]}
                        labelFormatter={(label) => `Month: ${label}`}
                    />
                    <Legend 
                        payload={[
                            { value: 'Primetime Room Utilization with Setup/Cleanup - Organization', type: 'line', color: '#ff4081' },
                            { value: 'Primetime Staffed Room Utilization with Setup/Cleanup - Organization', type: 'line', color: '#007bff' }
                        ]}
                    />
                    <Line 
                        type="monotone" 
                        dataKey="staffed" 
                        stroke="#007bff" 
                        strokeWidth={2}
                        dot={{ r: 4 }}
                        activeDot={{ r: 6 }}
                    />
                    <Line 
                        type="monotone" 
                        dataKey="unstaffed" 
                        stroke="#ff4081" 
                        strokeWidth={2}
                        dot={{ r: 4 }}
                        activeDot={{ r: 6 }}
                    />
                </RechartsLineChart>
            </ResponsiveContainer>
        </div>
    );
};

export default LineChart;
