import React, { useState, useEffect } from 'react';
import { BarChart, Bar, LineChart, Line, PieChart, Pie, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, Cell } from 'recharts';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';

const COLORS = ['#0088FE', '#00C49F', '#FFBB28', '#FF8042', '#8884d8'];

const DischargeDashboard = () => {
  const [processData, setProcessData] = useState([]);
  const [statistics, setStatistics] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // Simulate API call to get data from backend
    const fetchData = async () => {
      try {
        const response = await fetch('/api/discharge-operations');
        const data = await response.json();
        
        // Process the data for visualization
        const processedData = processDataForCharts(data);
        setProcessData(processedData);
        
        // Calculate statistics
        setStatistics({
          totalDischarges: 24,
          averageDuration: Math.round(processedData.durationData.reduce((acc, curr) => acc + curr.duration, 0) / 24),
          completedDischarges: processedData.timelineData.reduce((acc, curr) => acc + curr.completed, 0),
          pendingDischarges: processedData.timelineData.reduce((acc, curr) => acc + curr.pending, 0)
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
        <p className="text-lg">Loading discharge dashboard...</p>
      </div>
    );
  }

  return (
    <div className="p-6 max-w-7xl mx-auto">
      <h1 className="text-3xl font-bold mb-6">Discharge Process Dashboard</h1>
      
      {/* Statistics Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <Card>
          <CardHeader>
            <CardTitle>Total Discharges</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold">{statistics?.totalDischarges}</p>
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
            <CardTitle>Completed</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold text-green-500">{statistics?.completedDischarges}</p>
          </CardContent>
        </Card>
        
        <Card>
          <CardHeader>
            <CardTitle>Pending</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-bold text-yellow-500">{statistics?.pendingDischarges}</p>
          </CardContent>
        </Card>
      </div>

      {/* Discharge Timeline */}
      <Card className="mb-6">
        <CardHeader>
          <CardTitle>Discharge Timeline</CardTitle>
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
                  dataKey="initiated" 
                  stroke="#8884d8" 
                  name="Initiated"
                />
                <Line 
                  type="monotone" 
                  dataKey="completed" 
                  stroke="#82ca9d" 
                  name="Completed"
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </CardContent>
      </Card>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        {/* Disposition Distribution */}
        <Card>
          <CardHeader>
            <CardTitle>Discharge Disposition</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-[300px]">
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie
                    data={processData.dispositionData}
                    cx="50%"
                    cy="50%"
                    outerRadius={100}
                    fill="#8884d8"
                    dataKey="value"
                    label={({name, percent}) => `${name} (${(percent * 100).toFixed(0)}%)`}
                  >
                    {processData.dispositionData.map((entry, index) => (
                      <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                    ))}
                  </Pie>
                  <Tooltip />
                  <Legend />
                </PieChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>

        {/* Duration Distribution */}
        <Card>
          <CardHeader>
            <CardTitle>Discharge Duration Distribution</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-[300px]">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={processData.durationData}
                  margin={{
                    top: 5,
                    right: 30,
                    left: 20,
                    bottom: 5,
                  }}
                >
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="range" />
                  <YAxis />
                  <Tooltip />
                  <Bar dataKey="count" fill="#8884d8" name="Number of Discharges" />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
};

// Helper function to process data for charts
const processDataForCharts = (rawData) => {
  // Process timeline data (24-hour view)
  const timelineData = Array.from({length: 24}, (_, i) => ({
    hour: i,
    initiated: rawData.filter(d => 
      new Date(d.timestamp).getHours() === i && 
      d.activity === 'Discharge Order Written'
    ).length,
    completed: rawData.filter(d => 
      new Date(d.timestamp).getHours() === i && 
      d.activity === 'Physical Discharge'
    ).length
  }));

  // Process disposition data
  const dispositionCounts = rawData.reduce((acc, curr) => {
    if (curr.activity === 'Physical Discharge') {
      acc[curr.disposition] = (acc[curr.disposition] || 0) + 1;
    }
    return acc;
  }, {});

  const dispositionData = Object.entries(dispositionCounts).map(([name, value]) => ({
    name,
    value
  }));

  // Process duration data
  const durationRanges = [
    {min: 0, max: 120, label: '0-2 hrs'},
    {min: 120, max: 240, label: '2-4 hrs'},
    {min: 240, max: 360, label: '4-6 hrs'},
    {min: 360, max: 480, label: '6-8 hrs'},
    {min: 480, max: Infinity, label: '8+ hrs'}
  ];

  const durationData = durationRanges.map(range => ({
    range: range.label,
    count: rawData.filter(d => {
      const duration = d.duration_mins;
      return duration >= range.min && duration < range.max;
    }).length
  }));

  return {
    timelineData,
    dispositionData,
    durationData
  };
};

export default DischargeDashboard;