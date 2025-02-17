import React, { useState, useEffect } from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { LineChart, Line, BarChart, Bar, PieChart, Pie, Cell, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, ScatterChart, Scatter } from 'recharts';

const COLORS = ['#0088FE', '#00C49F', '#FFBB28', '#FF8042', '#8884d8'];

const renderCustomizedLabel = ({ cx, cy, midAngle, innerRadius, outerRadius, percent, name }) => {
  const RADIAN = Math.PI / 180;
  const radius = innerRadius + (outerRadius - innerRadius) * 0.5;
  const x = cx + radius * Math.cos(-midAngle * RADIAN);
  const y = cy + radius * Math.sin(-midAngle * RADIAN);

  return (
    <text
      x={x}
      y={y}
      fill="white"
      textAnchor={x > cx ? 'start' : 'end'}
      dominantBaseline="central"
    >
      {`${name} ${(percent * 100).toFixed(0)}%`}
    </text>
  );
};

const EnhancedAdmissionVisualization = () => {
  const [ocelData, setOcelData] = useState(null);
  const [selectedView, setSelectedView] = useState('admissionTypes');
  const [predictiveData, setPredictiveData] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      try {
        const response = await fetch('/api/admission-ocel');
        const data = await response.json();
        setOcelData(data);
        // Generate predictive data based on historical patterns
        setPredictiveData(generatePredictiveData(data));
      } catch (error) {
        console.error('Error fetching OCEL data:', error);
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, []);

  const renderAdmissionTypesView = () => {
    return (
      <div className="space-y-6">
        {/* Admission Type Distribution */}
        <Card>
          <CardHeader>
            <CardTitle>Admission Type Distribution</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="h-[300px]">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie
                      data={getAdmissionTypeDistribution()}
                      cx="50%"
                      cy="50%"
                      labelLine={false}
                      label={renderCustomizedLabel}
                      outerRadius={100}
                      fill="#8884d8"
                      dataKey="value"
                    >
                      {getAdmissionTypeDistribution().map((entry, index) => (
                        <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                      ))}
                    </Pie>
                    <Tooltip />
                    <Legend />
                  </PieChart>
                </ResponsiveContainer>
              </div>
              <div className="space-y-4">
                {getAdmissionTypeMetrics().map((metric) => (
                  <div key={metric.type} className="p-4 bg-gray-50 rounded-lg">
                    <div className="text-sm text-gray-600">{metric.type}</div>
                    <div className="text-xl font-bold mt-1">{metric.count}</div>
                    <div className="text-sm text-gray-500">
                      Avg Time to Bed: {metric.avgTimeToBed}m
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Type-Specific Timelines */}
        <Card>
          <CardHeader>
            <CardTitle>Admission Type Performance</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-[400px]">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart
                  data={getAdmissionTypeTimeline()}
                  margin={{ top: 5, right: 30, left: 20, bottom: 5 }}
                >
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="time" />
                  <YAxis />
                  <Tooltip />
                  <Legend />
                  <Line type="monotone" dataKey="ED" stroke="#0088FE" />
                  <Line type="monotone" dataKey="Direct" stroke="#00C49F" />
                  <Line type="monotone" dataKey="Transfer" stroke="#FFBB28" />
                  <Line type="monotone" dataKey="Surgical" stroke="#FF8042" />
                </LineChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  };

  const renderPredictiveAnalyticsView = () => {
    return (
      <div className="space-y-6">
        {/* Admission Forecast */}
        <Card>
          <CardHeader>
            <CardTitle>24-Hour Admission Forecast</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-[300px]">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart
                  data={predictiveData?.forecast}
                  margin={{ top: 5, right: 30, left: 20, bottom: 5 }}
                >
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="time" />
                  <YAxis />
                  <Tooltip />
                  <Legend />
                  <Line
                    type="monotone"
                    dataKey="actual"
                    stroke="#8884d8"
                    name="Historical"
                  />
                  <Line
                    type="monotone"
                    dataKey="predicted"
                    stroke="#82ca9d"
                    strokeDasharray="5 5"
                    name="Forecast"
                  />
                  <Line
                    type="monotone"
                    dataKey="upperBound"
                    stroke="#ffc658"
                    strokeDasharray="3 3"
                    name="Upper Bound"
                  />
                  <Line
                    type="monotone"
                    dataKey="lowerBound"
                    stroke="#ff7300"
                    strokeDasharray="3 3"
                    name="Lower Bound"
                  />
                </LineChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>

        {/* Resource Demand Prediction */}
        <Card>
          <CardHeader>
            <CardTitle>Predicted Resource Demand</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-[300px]">
              <ResponsiveContainer width="100%" height="100%">
                <ScatterChart
                  margin={{ top: 20, right: 20, bottom: 20, left: 20 }}
                >
                  <CartesianGrid />
                  <XAxis type="number" dataKey="time" name="Hour" />
                  <YAxis type="number" dataKey="demand" name="Demand" />
                  <Tooltip cursor={{ strokeDasharray: '3 3' }} />
                  <Legend />
                  <Scatter
                    name="Predicted Demand"
                    data={predictiveData?.resourceDemand}
                    fill="#8884d8"
                  />
                </ScatterChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>

        {/* Capacity Alert Predictions */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {getPredictedAlerts().map((alert) => (
            <Card key={alert.unit}>
              <CardContent className="pt-6">
                <div className={`p-4 rounded-lg ${getAlertColor(alert.riskLevel)}`}>
                  <div className="font-medium text-lg">{alert.unit}</div>
                  <div className="text-sm mt-1">
                    Risk Level: {alert.riskLevel}
                  </div>
                  <div className="text-sm mt-1">
                    Predicted Capacity: {alert.predictedCapacity}%
                  </div>
                  <div className="text-sm mt-2">
                    {alert.recommendation}
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    );
  };

  const renderResourceAllocationView = () => {
    return (
      <div className="space-y-6">
        {/* Current Resource Utilization */}
        <Card>
          <CardHeader>
            <CardTitle>Resource Utilization by Role</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-[300px]">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={getResourceUtilization()}
                  margin={{ top: 5, right: 30, left: 20, bottom: 5 }}
                >
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="role" />
                  <YAxis />
                  <Tooltip />
                  <Legend />
                  <Bar dataKey="current" name="Current Load" fill="#8884d8" />
                  <Bar dataKey="optimal" name="Optimal Load" fill="#82ca9d" />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>

        {/* Resource Efficiency Matrix */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Card>
            <CardHeader>
              <CardTitle>Staff Efficiency</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-2">
                {getStaffEfficiency().map((staff) => (
                  <div key={staff.id} className="p-3 bg-gray-50 rounded-lg">
                    <div className="flex justify-between items-center">
                      <span className="font-medium">{staff.name}</span>
                      <span className={`px-2 py-1 rounded ${getEfficiencyColor(staff.efficiency)}`}>
                        {staff.efficiency}%
                      </span>
                    </div>
                    <div className="text-sm text-gray-600 mt-1">
                      Tasks: {staff.tasks} | Avg Time: {staff.avgTime}m
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Resource Allocation Recommendations</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-3">
                {getResourceRecommendations().map((rec, index) => (
                  <div key={index} className="p-4 bg-gray-50 rounded-lg">
                    <div className="font-medium text-blue-600">{rec.title}</div>
                    <div className="text-sm mt-1">{rec.description}</div>
                    <div className="text-sm text-gray-600 mt-2">
                      Impact: {rec.impact}
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    );
  };

  // Helper functions for data processing
  const getAdmissionTypeDistribution = () => {
    if (!ocelData) return [];
    
    const distribution = {};
    Object.values(ocelData['ocel:objects'])
      .filter(obj => obj['ocel:type'] === 'admission')
      .forEach(obj => {
        const type = obj['ocel:ovmap'].admission_type;
        distribution[type] = (distribution[type] || 0) + 1;
      });
    
    return Object.entries(distribution).map(([name, value]) => ({
      name,
      value
    }));
  };

  const getAdmissionTypeMetrics = () => {
    // Implementation for admission type metrics
    return [
      {
        type: 'Emergency Department',
        count: 15,
        avgTimeToBed: 45
      },
      {
        type: 'Direct Admit',
        count: 8,
        avgTimeToBed: 30
      },
      // Add more types as needed
    ];
  };

  const generatePredictiveData = (data) => {
    // Implementation for predictive data generation
    return {
      forecast: [
        { time: '00:00', actual: 5, predicted: 6, upperBound: 8, lowerBound: 4 },
        // Add more time points
      ],
      resourceDemand: [
        { time: 1, demand: 30 },
        // Add more demand points
      ]
    };
  };

  const getResourceUtilization = () => {
    // Implementation for resource utilization
    return [
      { role: 'Nurses', current: 85, optimal: 75 },
      { role: 'Physicians', current: 70, optimal: 80 },
      // Add more roles
    ];
  };

  // Utility functions
  const getAlertColor = (riskLevel) => {
    const colors = {
      high: 'bg-red-100 text-red-800',
      medium: 'bg-yellow-100 text-yellow-800',
      low: 'bg-green-100 text-green-800'
    };
    return colors[riskLevel] || 'bg-gray-100 text-gray-800';
  };

  const getEfficiencyColor = (efficiency) => {
    if (efficiency >= 90) return 'bg-green-100 text-green-800';
    if (efficiency >= 70) return 'bg-yellow-100 text-yellow-800';
    return 'bg-red-100 text-red-800';
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <p className="text-lg">Loading enhanced admission data...</p>
      </div>
    );
  }

  return (
    <div className="w-full max-w-7xl mx-auto p-4">
      <Card>
        <CardHeader>
          <CardTitle>Enhanced Admission Analysis</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="mb-6">
            <select
              className="w-full max-w-xs p-2 border rounded"
              value={selectedView}
              onChange={(e) => setSelectedView(e.target.value)}
            >
              <option value="admissionTypes">Admission Types</option>
              <option value="predictive">Predictive Analytics</option>
              <option value="resources">Resource Allocation</option>
            </select>
          </div>

          {selectedView === 'admissionTypes' && renderAdmissionTypesView()}
          {selectedView === 'predictive' && renderPredictiveAnalyticsView()}
          {selectedView === 'resources' && renderResourceAllocationView()}
        </CardContent>
      </Card>
    </div>
  );
};

export default EnhancedAdmissionVisualization;