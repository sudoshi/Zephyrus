import React, { useState, useEffect } from 'react';
import { BarChart, Bar, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';

const NursingDashboard = () => {
  const [processData, setProcessData] = useState([]);
  const [statistics, setStatistics] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // Simulate API call to get data from backend
    const fetchData = async () => {
      try {
        // In production, this would be your API endpoint
        const response = await fetch('/api/nursing-operations');
        const data = await response.json();
        
        // Process the data for visualization
        const processedData = processDataForCharts(data);
        setProcessData(processedData);
        
        // Calculate statistics
        setStatistics({
          totalPatients: 28,
          averageDuration: Math.round(data.reduce((acc, curr) => acc + curr.duration_mins, 0) / data.length),
          urgentCases: 3,
          delayedCases: 4
        });
        
      } catch (error) {
        console.error('Error fetching data:', error);
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, []);

  if (loading) {
    return (
      <div className="flex items-center justify-center h-screen">
        <p className="text-lg">Loading dashboard...</p>
      </div>
    );
  }

  return (
    <div className="p-6 max-w-7xl mx-auto">
      <h1 className="text-3xl font-bold mb-6">Nursing Operations Dashboard</h1>
      
      {/* Statistics Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <Card>
          <CardHeader>
            <CardTitle>Total Patients</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold">{statistics?.totalPatients}</p>
          </CardContent>
        </Card>
        
        <Card>
          <CardHeader>
            <CardTitle>Avg Duration</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold">{statistics?.averageDuration} mins</p>
          </CardContent>
        </Card>
        
        <Card>
          <CardHeader>
            <CardTitle>Urgent Cases</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold text-red-500">{statistics?.urgentCases}</p>
          </CardContent>
        </Card>
        
        <Card>
          <CardHeader>
            <CardTitle>Delayed Cases</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold text-yellow-500">{statistics?.delayedCases}</p>
          </CardContent>
        </Card>
      </div>

      {/* Activity Timeline */}
      <Card className="mb-6">
        <CardHeader>
          <CardTitle>Activity Timeline</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="h-[300px]">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart
                data={processData.timelineData}
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
              </LineChart>
            </ResponsiveContainer>
          </div>
        </CardContent>
      </Card>

      {/* Activity Distribution */}
      <Card>
        <CardHeader>
          <CardTitle>Activity Distribution</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="h-[300px]">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart
                data={processData.activityData}
                margin={{
                  top: 5,
                  right: 30,
                  left: 20,
                  bottom: 5,
                }}
              >
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="activity" />
                <YAxis />
                <Tooltip />
                <Legend />
                <Bar dataKey="count" fill="#82ca9d" name="Activity Count" />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

// Helper function to process data for charts
const processDataForCharts = (rawData) => {
  // Process timeline data
  const timelineData = Array.from({length: 24}, (_, i) => ({
    hour: i,
    activePatients: rawData.filter(d => 
      new Date(d.timestamp).getHours() === i
    ).length
  }));

  // Process activity data
  const activityCounts = rawData.reduce((acc, curr) => {
    acc[curr.activity] = (acc[curr.activity] || 0) + 1;
    return acc;
  }, {});

  const activityData = Object.entries(activityCounts).map(([activity, count]) => ({
    activity,
    count
  }));

  return {
    timelineData,
    activityData
  };
};

export default ProcessDashboard;