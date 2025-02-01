import React from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

const StackedBarChart = ({ data }) => {
    // Transform data into format needed for recharts
    const chartData = Object.entries(data).map(([service, values]) => ({
        service,
        accurate: values.accurate,
        under: values.under,
        over: values.over
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
                    stackOffset="expand"
                    barSize={30}
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
                        tickFormatter={(value) => `${Math.round(value * 100)}%`}
                    />
                    <Tooltip 
                        formatter={(value, name) => [
                            `${value}%`,
                            name === 'accurate' ? 'Accurate' : 
                            name === 'under' ? 'Underscheduled' : 
                            'Overscheduled'
                        ]}
                        labelFormatter={(label) => `Service: ${label}`}
                    />
                    <Legend 
                        payload={[
                            { value: 'Accurate', type: 'rect', color: '#4CAF50' },
                            { value: 'Underscheduled', type: 'rect', color: '#FF9800' },
                            { value: 'Overscheduled', type: 'rect', color: '#f44336' }
                        ]}
                    />
                    <Bar 
                        dataKey="accurate" 
                        stackId="a" 
                        fill="#4CAF50" 
                    />
                    <Bar 
                        dataKey="under" 
                        stackId="a" 
                        fill="#FF9800" 
                    />
                    <Bar 
                        dataKey="over" 
                        stackId="a" 
                        fill="#f44336" 
                    />
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
};

export default StackedBarChart;
