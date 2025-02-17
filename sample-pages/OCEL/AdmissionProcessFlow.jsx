import React, { useState, useEffect } from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';

const AdmissionProcessFlow = () => {
  const [selectedCase, setSelectedCase] = useState(null);
  const [processData, setProcessData] = useState([]);
  const [loading, setLoading] = useState(true);
  const [selectedFilter, setSelectedFilter] = useState('all');

  const processSteps = [
    'Patient Arrival',
    'Registration',
    'Initial Triage',
    'Vital Signs',
    'Provider Assessment',
    'Admission Decision',
    'Bed Request',
    'Bed Assignment',
    'Unit Notification',
    'Patient Transport',
    'Unit Arrival'
  ];

  useEffect(() => {
    const fetchData = async () => {
      try {
        const response = await fetch('/api/admission-operations');
        const data = await response.json();
        setProcessData(data);
      } catch (error) {
        console.error('Error fetching data:', error);
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, []);

  const calculateNodePosition = (index, total) => {
    const radius = 350;
    const angle = (index * 2 * Math.PI) / total - Math.PI / 2;
    const x = radius * Math.cos(angle) + radius;
    const y = radius * Math.sin(angle) + radius;
    return { x, y };
  };

  const calculateTimeDifference = (startTime, endTime) => {
    return Math.round((new Date(endTime) - new Date(startTime)) / (1000 * 60));
  };

  const getStepStatus = (step, caseId) => {
    if (!selectedCase) return 'pending';
    
    const event = processData.find(
      d => d.case_id === caseId && d.activity === step
    );
    
    if (!event) return 'pending';
    
    const currentTime = new Date();
    const eventTime = new Date(event.timestamp);
    const timeDiff = calculateTimeDifference(eventTime, currentTime);
    
    if (timeDiff > event.duration_mins * 1.5) return 'delayed';
    return 'completed';
  };

  const getStatusColor = (status) => {
    switch (status) {
      case 'completed': return '#4CAF50';
      case 'delayed': return '#FFA726';
      case 'pending': return '#9E9E9E';
      default: return '#9E9E9E';
    }
  };

  const calculateTotalTime = (data, caseId) => {
    const caseEvents = data.filter(d => d.case_id === caseId);
    if (caseEvents.length === 0) return 0;
    
    const startTime = new Date(Math.min(...caseEvents.map(e => new Date(e.timestamp))));
    const endTime = new Date(Math.max(...caseEvents.map(e => new Date(e.timestamp))));
    
    return Math.round((endTime - startTime) / (1000 * 60));
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
      <Card className="mb-6">
        <CardHeader>
          <CardTitle>Admission Process Flow</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex gap-4 mb-4">
            <select 
              className="w-full max-w-xs p-2 border rounded"
              onChange={(e) => setSelectedCase(e.target.value)}
              value={selectedCase || ''}
            >
              <option value="">Select an admission case...</option>
              {processData.map(data => (
                <option key={data.case_id} value={data.case_id}>
                  Case {data.case_id} - {data.admission_type}
                </option>
              ))}
            </select>
            
            <select
              className="w-full max-w-xs p-2 border rounded"
              onChange={(e) => setSelectedFilter(e.target.value)}
              value={selectedFilter}
            >
              <option value="all">All Admission Types</option>
              <option value="Direct Admit">Direct Admits</option>
              <option value="Emergency Department">ED Admits</option>
              <option value="Transfer">Transfers</option>
              <option value="Surgical Admission">Surgical Admits</option>
            </select>
          </div>

          <div className="relative h-[800px] w-[800px] mx-auto">
            <svg width="800" height="800" className="mx-auto">
              {processSteps.map((_, index) => {
                if (index < processSteps.length - 1) {
                  const start = calculateNodePosition(index, processSteps.length);
                  const end = calculateNodePosition(index + 1, processSteps.length);
                  return (
                    <line
                      key={`line-${index}`}
                      x1={start.x}
                      y1={start.y}
                      x2={end.x}
                      y2={end.y}
                      stroke="#ddd"
                      strokeWidth="2"
                      strokeDasharray="4"
                    />
                  );
                }
                return null;
              })}

              {processSteps.map((step, index) => {
                const pos = calculateNodePosition(index, processSteps.length);
                const status = selectedCase ? getStepStatus(step, selectedCase) : 'pending';
                
                return (
                  <g key={`node-${index}`} transform={`translate(${pos.x},${pos.y})`}>
                    <circle
                      r="35"
                      fill={getStatusColor(status)}
                      className="transition-colors duration-300"
                      filter="url(#shadow)"
                    />
                    <text
                      textAnchor="middle"
                      dominantBaseline="middle"
                      fill="white"
                      fontSize="14"
                      fontWeight="bold"
                    >
                      {index + 1}
                    </text>
                    <text
                      y="50"
                      textAnchor="middle"
                      dominantBaseline="middle"
                      fill="black"
                      fontSize="12"
                      transform={`rotate(${(index * 360) / processSteps.length})`}
                    >
                      {step}
                    </text>
                  </g>
                );
              })}
              
              <defs>
                <filter id="shadow" x="-20%" y="-20%" width="140%" height="140%">
                  <feDropShadow dx="2" dy="2" stdDeviation="2" floodOpacity="0.3"/>
                </filter>
              </defs>
            </svg>
          </div>

          {selectedCase && (
            <div className="mt-4">
              <h3 className="text-lg font-semibold mb-2">Process Timeline</h3>
              <div className="space-y-2">
                {processData
                  .filter(d => d.case_id === selectedCase)
                  .sort((a, b) => new Date(a.timestamp) - new Date(b.timestamp))
                  .map((event, index, array) => {
                    const nextEvent = array[index + 1];
                    const duration = nextEvent ? 
                      calculateTimeDifference(event.timestamp, nextEvent.timestamp) : 0;
                    
                    return (
                      <div key={index} className="flex items-center space-x-4 p-2 hover:bg-gray-50 rounded">
                        <div className="w-48">
                          <span className="font-medium">{event.activity}</span>
                        </div>
                        <div className="w-32">
                          <span className="text-gray-600">
                            {new Date(event.timestamp).toLocaleTimeString()}
                          </span>
                        </div>
                        <div className="w-32">
                          <span className="text-gray-600">{event.resource}</span>
                        </div>
                        {duration > 0 && (
                          <div className="flex items-center">
                            <span className="text-gray-500">→</span>
                            <span className="ml-2 text-gray-600">{duration} mins</span>
                            {duration > event.duration_mins * 1.5 && (
                              <span className="ml-2 text-orange-500">
                                (Delayed)
                              </span>
                            )}
                          </div>
                        )}
                      </div>
                    );
                  })}
              </div>
              
              <div className="mt-6 p-4 bg-gray-50 rounded">
                <h4 className="font-semibold mb-2">Admission Summary</h4>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <span className="text-gray-600">Admission Type:</span>
                    <span className="ml-2 font-medium">
                      {processData.find(d => d.case_id === selectedCase)?.admission_type}
                    </span>
                  </div>
                  <div>
                    <span className="text-gray-600">Destination Unit:</span>
                    <span className="ml-2 font-medium">
                      {processData.find(d => d.case_id === selectedCase)?.unit}
                    </span>
                  </div>
                  <div>
                    <span className="text-gray-600">Total Time:</span>
                    <span className="ml-2 font-medium">
                      {calculateTotalTime(processData, selectedCase)} mins
                    </span>
                  </div>
                </div>
              </div>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

export default AdmissionProcessFlow;