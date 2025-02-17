import React, { useState, useEffect } from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';

const DischargeProcessFlow = () => {
  const [selectedCase, setSelectedCase] = useState(null);
  const [processData, setProcessData] = useState([]);
  const [loading, setLoading] = useState(true);

  // Define the core discharge process steps
  const processSteps = [
    'Discharge Order Written',
    'Discharge Planning Assessment',
    'Medication Reconciliation',
    'Patient Education',
    'Final Nursing Assessment',
    'Discharge Summary Documentation',
    'Prescriptions Provided',
    'Patient Transport Arranged',
    'Physical Discharge'
  ];

  useEffect(() => {
    const fetchData = async () => {
      try {
        const response = await fetch('/api/discharge-operations');
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
    const radius = 300; // Size of our circular layout
    const angle = (index * 2 * Math.PI) / total;
    const x = radius * Math.cos(angle) + radius;
    const y = radius * Math.sin(angle) + radius;
    return { x, y };
  };

  const calculateTimeDifference = (startTime, endTime) => {
    return Math.round((new Date(endTime) - new Date(startTime)) / (1000 * 60)); // in minutes
  };

  return (
    <div className="w-full max-w-7xl mx-auto p-4">
      <Card className="mb-6">
        <CardHeader>
          <CardTitle>Discharge Process Flow</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="mb-4">
            <select 
              className="w-full max-w-xs p-2 border rounded"
              onChange={(e) => setSelectedCase(e.target.value)}
              value={selectedCase || ''}
            >
              <option value="">Select a discharge case...</option>
              {processData.map(data => (
                <option key={data.case_id} value={data.case_id}>
                  Case {data.case_id} - {data.disposition}
                </option>
              ))}
            </select>
          </div>

          <div className="relative h-[800px] w-[800px] mx-auto">
            <svg width="800" height="800" className="mx-auto">
              {/* Draw connecting lines between nodes */}
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
                    />
                  );
                }
                return null;
              })}

              {/* Draw nodes for each process step */}
              {processSteps.map((step, index) => {
                const pos = calculateNodePosition(index, processSteps.length);
                const completed = selectedCase && processData.some(
                  d => d.case_id === selectedCase && d.activity === step
                );
                
                return (
                  <g key={`node-${index}`} transform={`translate(${pos.x},${pos.y})`}>
                    <circle
                      r="30"
                      fill={completed ? "#4CAF50" : "#ccc"}
                      className="transition-colors duration-300"
                    />
                    <text
                      textAnchor="middle"
                      dominantBaseline="middle"
                      fill="white"
                      fontSize="12"
                    >
                      {index + 1}
                    </text>
                    <text
                      y="45"
                      textAnchor="middle"
                      dominantBaseline="middle"
                      fill="black"
                      fontSize="12"
                      transform="rotate(45)"
                    >
                      {step}
                    </text>
                  </g>
                );
              })}
            </svg>
          </div>

          {selectedCase && (
            <div className="mt-4">
              <h3 className="text-lg font-semibold mb-2">Process Details</h3>
              <div className="space-y-2">
                {processData
                  .filter(d => d.case_id === selectedCase)
                  .sort((a, b) => new Date(a.timestamp) - new Date(b.timestamp))
                  .map((event, index, array) => {
                    const nextEvent = array[index + 1];
                    const duration = nextEvent ? 
                      calculateTimeDifference(event.timestamp, nextEvent.timestamp) : 0;
                    
                    return (
                      <div key={index} className="flex items-center space-x-4">
                        <div className="w-48">
                          <span className="font-medium">{event.activity}</span>
                        </div>
                        <div className="w-32">
                          <span className="text-gray-600">
                            {new Date(event.timestamp).toLocaleTimeString()}
                          </span>
                        </div>
                        {duration > 0 && (
                          <div className="flex items-center">
                            <span className="text-gray-500">→</span>
                            <span className="ml-2 text-gray-600">{duration} mins</span>
                          </div>
                        )}
                      </div>
                    );
                  })}
              </div>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

export default DischargeProcessFlow;