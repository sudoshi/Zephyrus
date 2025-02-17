import React, { useState, useEffect } from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

const OCELDischargeVisualization = () => {
  const [ocelData, setOcelData] = useState(null);
  const [selectedView, setSelectedView] = useState('process');
  const [selectedObject, setSelectedObject] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      try {
        const response = await fetch('/api/discharge-ocel');
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

  const getObjectRelationships = (objectId) => {
    if (!ocelData) return [];
    
    const relationships = [];
    const events = Object.entries(ocelData['ocel:events']);
    
    events.forEach(([eventId, event]) => {
      if (event['ocel:omap'].includes(objectId)) {
        event['ocel:omap'].forEach(relatedId => {
          if (relatedId !== objectId) {
            relationships.push({
              from: objectId,
              to: relatedId,
              via: event['ocel:activity']
            });
          }
        });
      }
    });
    
    return relationships;
  };

  const renderProcessView = () => {
    return (
      <div className="space-y-4">
        <div className="h-[400px]">
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
                dataKey="events" 
                stroke="#8884d8" 
                name="Events" 
              />
              <Line 
                type="monotone" 
                dataKey="objects" 
                stroke="#82ca9d" 
                name="Objects" 
              />
            </LineChart>
          </ResponsiveContainer>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <Card>
            <CardHeader>
              <CardTitle>Object Types Distribution</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-2">
                {objectTypeDistribution().map(({ type, count }) => (
                  <div 
                    key={type}
                    className="flex justify-between items-center p-2 hover:bg-gray-50 rounded"
                  >
                    <span className="font-medium">{type}</span>
                    <span className="text-gray-600">{count}</span>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Activity Summary</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-2">
                {activitySummary().map(({ activity, count }) => (
                  <div 
                    key={activity}
                    className="flex justify-between items-center p-2 hover:bg-gray-50 rounded"
                  >
                    <span className="font-medium">{activity}</span>
                    <span className="text-gray-600">{count}</span>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    );
  };

  const renderObjectView = () => {
    const objects = ocelData ? Object.entries(ocelData['ocel:objects']) : [];
    
    return (
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Card>
          <CardHeader>
            <CardTitle>Objects</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-2 max-h-[500px] overflow-y-auto">
              {objects.map(([objectId, object]) => (
                <div
                  key={objectId}
                  className={`p-2 rounded cursor-pointer hover:bg-gray-50 ${
                    selectedObject === objectId ? 'bg-blue-50' : ''
                  }`}
                  onClick={() => setSelectedObject(objectId)}
                >
                  <div className="flex justify-between">
                    <span className="font-medium">{objectId}</span>
                    <span className="text-gray-600">{object['ocel:type']}</span>
                  </div>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        {selectedObject && (
          <Card>
            <CardHeader>
              <CardTitle>Object Details</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                <div>
                  <h4 className="font-semibold mb-2">Attributes</h4>
                  {Object.entries(ocelData['ocel:objects'][selectedObject]['ocel:ovmap']).map(([key, value]) => (
                    <div key={key} className="flex justify-between items-center p-1">
                      <span className="text-gray-600">{key}:</span>
                      <span>{value}</span>
                    </div>
                  ))}
                </div>

                <div>
                  <h4 className="font-semibold mb-2">Related Events</h4>
                  <div className="space-y-1">
                    {getObjectEvents(selectedObject).map((event, index) => (
                      <div key={index} className="p-1 text-sm">
                        {event.activity} at {new Date(event.timestamp).toLocaleString()}
                      </div>
                    ))}
                  </div>
                </div>

                <div>
                  <h4 className="font-semibold mb-2">Object Changes</h4>
                  <div className="space-y-1">
                    {getObjectChanges(selectedObject).map((change, index) => (
                      <div key={index} className="p-1 text-sm">
                        {change.change}: {change.value} at {new Date(change.timestamp).toLocaleString()}
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
      const eventsAtTime = events.filter(e => e['ocel:timestamp'] === time).length;
      const objectsAtTime = new Set(
        events
          .filter(e => e['ocel:timestamp'] === time)
          .flatMap(e => e['ocel:omap'])
      ).size;
      
      return {
        time: new Date(time).toLocaleTimeString(),
        events: eventsAtTime,
        objects: objectsAtTime
      };
    });
  };

  const objectTypeDistribution = () => {
    if (!ocelData) return [];
    
    const distribution = {};
    Object.values(ocelData['ocel:objects']).forEach(obj => {
      distribution[obj['ocel:type']] = (distribution[obj['ocel:type']] || 0) + 1;
    });
    
    return Object.entries(distribution).map(([type, count]) => ({ type, count }));
  };

  const activitySummary = () => {
    if (!ocelData) return [];
    
    const summary = {};
    Object.values(ocelData['ocel:events']).forEach(event => {
      summary[event['ocel:activity']] = (summary[event['ocel:activity']] || 0) + 1;
    });
    
    return Object.entries(summary)
      .map(([activity, count]) => ({ activity, count }))
      .sort((a, b) => b.count - a.count);
  };

  const getObjectEvents = (objectId) => {
    if (!ocelData) return [];
    
    return Object.values(ocelData['ocel:events'])
      .filter(event => event['ocel:omap'].includes(objectId))
      .map(event => ({
        activity: event['ocel:activity'],
        timestamp: event['ocel:timestamp']
      }))
      .sort((a, b) => new Date(a.timestamp) - new Date(b.timestamp));
  };

  const getObjectChanges = (objectId) => {
    if (!ocelData) return [];
    
    return ocelData['ocel:object-changes']
      .filter(change => change['ocel:oid'] === objectId)
      .map(change => ({
        change: change['ocel:change'],
        value: change['ocel:value'],
        timestamp: change['ocel:timestamp']
      }))
      .sort((a, b) => new Date(a.timestamp) - new Date(b.timestamp));
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <p className="text-lg">Loading OCEL data...</p>
      </div>
    );
  }

  return (
    <div className="w-full max-w-7xl mx-auto p-4">
      <Card>
        <CardHeader>
          <CardTitle>OCEL Discharge Process Analysis</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="mb-4">
            <select
              className="w-full max-w-xs p-2 border rounded"
              value={selectedView}
              onChange={(e) => setSelectedView(e.target.value)}
            >
              <option value="process">Process View</option>
              <option value="object">Object View</option>
            </select>
          </div>

          {selectedView === 'process' ? renderProcessView() : renderObjectView()}
        </CardContent>
      </Card>
    </div>
  );
};

export default OCELDischargeVisualization;