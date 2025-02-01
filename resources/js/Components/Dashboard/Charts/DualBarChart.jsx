import React from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

const DualBarChart = ({ data }) => {
    // Transform data into format needed for recharts
    const chartData = Object.entries(data).map(([service, values]) => ({
        service,
        room: values.room,
        procedure: values.procedure
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
                        label={{ value: 'Minutes', angle: -90, position: 'insideLeft' }}
                    />
                    <Tooltip 
                        formatter={(value, name) => [
                            `${value} min`, 
                            name === 'room' ? 'Room Turnover' : 'Procedure Turnover'
                        ]}
                        labelFormatter={(label) => `Service: ${label}`}
                    />
                    <Legend 
                        payload={[
                            { value: 'Room Turnover', type: 'rect', color: '#007bff' },
                            { value: 'Procedure Turnover', type: 'rect', color: '#ff4081' }
                        ]}
                    />
                    <Bar 
                        dataKey="room" 
                        fill="#007bff" 
                        radius={[4, 4, 0, 0]}
                    />
                    <Bar 
                        dataKey="procedure" 
                        fill="#ff4081" 
                        radius={[4, 4, 0, 0]}
                    />
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
};

export default DualBarChart;
