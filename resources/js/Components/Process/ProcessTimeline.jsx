import React from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/Components/ui/Card';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

const ProcessTimeline = ({ data }) => {
  if (!data || data.length === 0) return null;

  return (
    <Card className="mb-6">
      <CardHeader>
        <CardTitle>Activity Timeline</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="h-[300px]">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart
              data={data}
              margin={{
                top: 5,
                right: 30,
                left: 20,
                bottom: 5,
              }}
            >
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="hour" />
              <YAxis />
              <Tooltip />
              <Legend />
              <Line 
                type="monotone" 
                dataKey="activePatients" 
                stroke="#8884d8" 
                name="Active Patients"
              />
              <Line 
                type="monotone" 
                dataKey="duration" 
                stroke="#82ca9d" 
                name="Process Duration"
              />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </CardContent>
    </Card>
  );
};

export default ProcessTimeline;
