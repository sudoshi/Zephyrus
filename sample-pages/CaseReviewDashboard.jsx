import React from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { LineChart, Line, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

const CaseReviewDashboard = () => {
  // Helper function to convert minutes to hours and minutes format
  const formatMinutesToTime = (minutes) => {
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    return `${hours} Hours and ${mins} Minutes`;
  };

  // Custom tooltip for the Total Time chart
  const CustomTimeTooltip = ({ active, payload, label }) => {
    if (active && payload && payload.length) {
      return (
        <div className="bg-white p-4 border rounded shadow">
          <p className="text-gray-600">{label}</p>
          <p className="font-medium">Total Time: {payload[0].value}</p>
          <p className="text-sm text-gray-500">{formatMinutesToTime(payload[0].value)}</p>
        </div>
      );
    }
    return null;
  };

  // Sample data from the PDF
  const monthlyData = [
    { month: 'Jan 23', cases: 391, avgDuration: 93, totalTime: 38000 },
    { month: 'Mar 23', cases: 374, avgDuration: 101, totalTime: 44000 },
    { month: 'May 23', cases: 463, avgDuration: 94, totalTime: 39000 },
    { month: 'Jul 23', cases: 413, avgDuration: 94, totalTime: 40000 },
    { month: 'Sep 23', cases: 406, avgDuration: 93, totalTime: 39000 },
    { month: 'Nov 23', cases: 406, avgDuration: 93, totalTime: 40000 },
    { month: 'Jan 24', cases: 427, avgDuration: 95, totalTime: 43000 },
    { month: 'Mar 24', cases: 427, avgDuration: 95, totalTime: 35000 },
    { month: 'May 24', cases: 408, avgDuration: 93, totalTime: 38000 }
  ];

  return (
    <div className="space-y-8">
      <Card>
        <CardHeader>
          <CardTitle>Total # of Cases / Month Trend</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="h-96">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={monthlyData} margin={{ top: 20, right: 30, left: 20, bottom: 20 }}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="month" />
                <YAxis domain={[350, 500]} />
                <Tooltip />
                <Line 
                  type="monotone" 
                  dataKey="cases" 
                  stroke="#2563eb" 
                  strokeWidth={2}
                  dot={{ fill: '#2563eb' }}
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Average Case Duration by Site</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="h-96">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={monthlyData} margin={{ top: 20, right: 30, left: 20, bottom: 20 }}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="month" />
                <YAxis domain={[80, 110]} />
                <Tooltip />
                <Line 
                  type="monotone" 
                  dataKey="avgDuration" 
                  stroke="#16a34a"
                  strokeWidth={2}
                  dot={{ fill: '#16a34a' }}
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Total # of Cases / Month</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="h-96">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={monthlyData} margin={{ top: 20, right: 30, left: 20, bottom: 20 }}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="month" />
                <YAxis domain={[350, 500]} />
                <Tooltip />
                <Bar dataKey="cases" fill="#3b82f6" />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Total Time in OR / Month (Minutes)</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="h-96">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={monthlyData} margin={{ top: 20, right: 30, left: 20, bottom: 20 }}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="month" />
                <YAxis domain={[30000, 45000]} />
                <Tooltip content={<CustomTimeTooltip />} />
                <Bar dataKey="totalTime" fill="#6366f1" />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default CaseReviewDashboard;
