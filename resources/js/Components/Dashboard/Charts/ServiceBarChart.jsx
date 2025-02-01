import React from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

const ServiceBarChart = ({ data, firstCase = false }) => {
    // Transform data into format needed for recharts
    const chartData = Object.entries(data).map(([service, value]) => ({
        service,
        value: typeof value === 'object' ? value.cases : value
    }));

    return (
        <div className="w-full h-[300px]">
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
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis 
                        dataKey="service"
                        angle={-45}
                        textAnchor="end"
                        height={60}
                        interval={0}
                    />
                    <YAxis 
                        domain={[0, 100]}
                        tickFormatter={(value) => `${value}%`}
                    />
                    <Tooltip 
                        formatter={(value) => [`${value}%`, firstCase ? 'First Case %' : 'All Cases %']}
                        labelFormatter={(label) => `Service: ${label}`}
                    />
                    <Bar 
                        dataKey="value" 
                        fill="#007bff"
                        radius={[4, 4, 0, 0]}
                    />
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
};

export default ServiceBarChart;
