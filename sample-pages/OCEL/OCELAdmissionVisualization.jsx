import React, { useState, useEffect } from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { LineChart, Line, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

const OCELAdmissionVisualization = () => {
  const [ocelData, setOcelData] = useState(null);
  const [selectedView, setSelectedView] = useState('process');
  const [selectedObject, setSelectedObject] = useState(null);
  const [loading, setLoading] = useState(true);
  const [timeRange, setTimeRange] = useState('24h');

  useEffect(() => {
    const fetchData = async () => {
      try {
        const response = await fetch('/api/admission-ocel');
        const data = await response.json();
        setOcelData(data);
      } catch (error) {
        console.error('Error fetching OCEL data:', error);
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, []);

  const renderProcessView = () => {
    return (
      <div className="space-y-6">
        {/* Admission Timeline */}
        <Card>
          <CardHeader>
            <CardTitle>Admission Timeline</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-[300px]">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart
                  data={processTimelineData()}
                  margin={{ top: 5, right: 30, left: 20, bottom: 5 }}
                >
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="time" />
                  <YAxis />
                  <Tooltip />
                  <Legend />
                  <Line 
                    type="monotone" 
                    dataKey="newAdmissions" 
                    stroke="#8884d8" 
                    name="New Admissions" 
                  />
                  <Line 
                    type="monotone" 
                    dataKey="bedAssignments" 
                    stroke="#82ca9d" 
                    name="Bed Assignments" 
                  />
                  <Line 
                    type="monotone" 
                    dataKey="completedAdmissions" 
                    stroke="#ffc658" 
                    name="Completed" 
                  />
                </LineChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>

        {/* Unit Distribution and Resource Utilization */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Card>
            <CardHeader>
              <CardTitle>Unit Distribution</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="h-[250px]">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart
                    data={getUnitDistribution()}
                    margin={{ top: 5, right: 30, left: 20, bottom: 5 }}
                  >
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis dataKey="unit" />
                    <YAxis />
                    <Tooltip />
                    <Bar dataKey="count" fill="#8884d8" />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Resource Utilization</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="h-[250px]">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart
                    data={getResourceUtilization()}
                    margin={{ top: 5, right: 30, left: 20, bottom: 5 }}
                  >
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis dataKey="resource" />
                    <YAxis />
                    <Tooltip />
                    <Bar dataKey="tasks" fill="#82ca9d" />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Process Metrics */}
        <Card>
          <CardHeader>
            <CardTitle>Process Metrics</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              {getProcessMetrics().map((metric) => (
                <div 
                  key={metric.label} 
                  className="p-4 bg-gray-50 rounded-lg"
                >
                  <div className="text-sm text-gray-600">{metric.label}</div>
                  <div className="text-2xl font-bold mt-1">{metric.value}</div>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      </div>
    );
  };

  const renderObjectView = () => {
    return (
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {/* Object List */}
        <Card>
          <CardHeader>
            <CardTitle>Admission Objects</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-2 max-h-[600px] overflow-y-auto">
              {getAdmissionObjects().map((obj) => (
                <div
                  key={obj.id}
                  className={`p-3 rounded cursor-pointer hover:bg-gray-50 ${
                    selectedObject === obj.id ? 'bg-blue-50' : ''
                  }`}
                  onClick={() => setSelectedObject(obj.id)}
                >
                  <div className="flex justify-between items-center">
                    <div>
                      <span className="font-medium">{obj.id}</span>
                      <span className="ml-2 text-sm text-gray-600">{obj.type}</span>
                    </div>
                    <span className={`px-2 py-1 rounded text-sm ${
                      getStatusColor(obj.status)
                    }`}>
                      {obj.status}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        {/* Object Details */}
        {selectedObject && (
          <Card>
            <CardHeader>
              <CardTitle>Object Details</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-6">
                {/* Attributes */}
                <div>
                  <h4 className="font-semibold mb-2">Attributes</h4>
                  <div className="grid grid-cols-2 gap-2">
                    {getObjectAttributes(selectedObject).map((attr) => (
                      <div key={attr.key} className="p-2 bg-gray-50 rounded">
                        <div className="text-sm text-gray-600">{attr.key}</div>
                        <div className="font-medium">{attr.value}</div>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Related Events Timeline */}
                <div>
                  <h4 className="font-semibold mb-2">Event Timeline</h4>
                  <div className="space-y-2">
                    {getObjectEvents(selectedObject).map((event, index) => (
                      <div 
                        key={index}
                        className="flex items-center p-2 bg-gray-50 rounded"
                      >
                        <div className="w-32 text-sm text-gray-600">
                          {new Date(event.timestamp).toLocaleTimeString()}
                        </div>
                        <div className="flex-1 font-medium">
                          {event.activity}
                        </div>
                        <div className="text-sm text-gray-600">
                          {event.duration}m
                        </div>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Related Objects */}
                <div>
                  <h4 className="font-semibold mb-2">Related Objects</h4>
                  <div className="space-y-2">
                    {getRelatedObjects(selectedObject).map((related) => (
                      <div 
                        key={related.id}
                        className="flex justify-between items-center p-2 bg-gray-50 rounded"
                      >
                        <span className="font-medium">{related.id}</span>
                        <span className="text-sm text-gray-600">{related.type}</span>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    );
  };

  // Helper functions for data processing
  const processTimelineData = () => {
    if (!ocelData) return [];
    
    const events = Object.values(ocelData['ocel:events']);
    const timePoints = [...new Set(events.map(e => e['ocel:timestamp']))].sort();
    
    return timePoints.map(time => {
      const eventsAtTime = events.filter(e => e['ocel:timestamp'] === time);
      return {
        time: new Date(time).toLocaleTimeString(),
        newAdmissions: eventsAtTime.filter(e => e['ocel:activity'] === 'Patient Arrival').length,
        bedAssignments: eventsAtTime.filter(e => e['ocel:activity'] === 'Bed Assignment').length,
        completedAdmissions: eventsAtTime.filter(e => e['ocel:activity'] === 'Unit Arrival').length
      };
    });
  };

  const getUnitDistribution = () => {
    if (!ocelData) return [];
    
    const units = {};
    Object.values(ocelData['ocel:events'])
      .filter(event => event['ocel:activity'] === 'Bed Assignment')
      .forEach(event => {
        const unit = event['ocel:vmap'].unit;
        units[unit] = (units[unit] || 0) + 1;
      });
    
    return Object.entries(units).map(([unit, count]) => ({ unit, count }));
  };

  const getResourceUtilization = () => {
    if (!ocelData) return [];
    
    const resources = {};
    Object.values(ocelData['ocel:events']).forEach(event => {
      const resource = event['ocel:vmap'].resource;
      resources[resource] = (resources[resource] || 0) + 1;
    });
    
    return Object.entries(resources)
      .map(([resource, tasks]) => ({ resource, tasks }))
      .sort((a, b) => b.tasks - a.tasks)
      .slice(0, 10);
  };

  const getProcessMetrics = () => {
    if (!ocelData) return [];
    
    const events = Object.values(ocelData['ocel:events']);
    const admissions = Object.values(ocelData['ocel:objects'])
      .filter(obj => obj['ocel:type'] === 'admission');
    
    return [
      {
        label: 'Total Admissions',
        value: admissions.length
      },
      {
        label: 'Avg Time to Bed',
        value: calculateAverageTimeToBed(events) + 'm'
      },
      {
        label: 'Active Cases',
        value: admissions.filter(a => a['ocel:ovmap'].status === 'in_progress').length
      },
      {
        label: 'Completed Today',
        value: admissions.filter(a => a['ocel:ovmap'].status === 'completed').length
      }
    ];
  };

  const calculateAverageTimeToBed = (events) => {
    const bedTimes = [];
    const arrivals = new Map();
    
    events.forEach(event => {
      if (event['ocel:activity'] === 'Patient Arrival') {
        arrivals.set(event['ocel:omap'][0], event['ocel:timestamp']);
      } else if (event['ocel:activity'] === 'Bed Assignment') {
        const arrivalTime = arrivals.get(event['ocel:omap'][0]);
        if (arrivalTime) {
          const timeDiff = new Date(event['ocel:timestamp']) - new Date(arrivalTime);
          bedTimes.push(timeDiff / (1000 * 60)); // Convert to minutes
        }
      }
    });
    
    return Math.round(bedTimes.reduce((a, b) => a + b, 0) / bedTimes.length);
  };

  const getStatusColor = (status) => {
    switch (status) {
      case 'completed': return 'bg-green-100 text-green-800';
      case 'in_progress': return 'bg-blue-100 text-blue-800';
      case 'pending': return 'bg-yellow-100 text-yellow-800';
      default: return 'bg-gray-100 text-gray-800';
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <p className="text-lg">Loading admission data...</p>
      </div>
    );
  }

  return (
    <div className="w-full max-w-7xl mx-auto p-4">
      <Card>
        <CardHeader>
          <CardTitle>OCEL Admission Process Analysis</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex gap-4 mb-6">
            <select
              className="w-full max-w-xs p-2 border rounded"
              value={selectedView}
              onChange={(e) => setSelectedView(e.target.value)}
            >
              <option value="process">Process View</option>
              <option value="object">Object View</option>
            </select>
            
            <select
              className="w-full max-w-xs p-2 border rounded"
              value={timeRange}
              onChange={(e) => setTimeRange(e.target.value)}
            >
              <option value="24h">Last 24 Hours</option>
              <option value="12h">Last 12 Hours</option>
              <option value="6h">Last 6 Hours</option>
              <option value="1h">Last Hour</option>
            </select>
          </div>

          {selectedView === 'process' ? renderProcessView() : renderObjectView()}
        </CardContent>
      </Card>
    </div>
  );
};

export default OCELAdmissionVisualization;