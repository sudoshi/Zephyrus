import React from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { BarChart, Bar, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { Users, AlertTriangle, Activity, Share2, Clock } from 'lucide-react';

/* ------------------------------------------------------------------
   1) RESOURCE STRESS ANALYSIS
   ------------------------------------------------------------------ */
function ResourceStressAnalysis() {
  // Example resource stress data
  const resourceData = {
    staffing: {
      current: {
        nurses: { assigned: 18, required: 22, utilization: 0.92 },
        physicians: { assigned: 8, required: 10, utilization: 0.88 },
        techs: { assigned: 12, required: 15, utilization: 0.85 }
      },
      weight: 0.4, // 40% of total score
      thresholds: {
        critical: 0.9,
        high: 0.8,
        medium: 0.7
      }
    },
    equipment: {
      current: {
        beds: { available: 28, total: 32, utilization: 0.85 },
        monitors: { available: 22, total: 25, utilization: 0.82 },
        ventilators: { available: 8, total: 10, utilization: 0.75 }
      },
      weight: 0.3, // 30% of total score
      thresholds: {
        critical: 0.85,
        high: 0.75,
        medium: 0.65
      }
    },
    space: {
      current: {
        ED: { occupied: 38, capacity: 40, utilization: 0.95 },
        triage: { occupied: 8, capacity: 10, utilization: 0.80 },
        fastTrack: { occupied: 12, capacity: 15, utilization: 0.85 }
      },
      weight: 0.3, // 30% of total score
      thresholds: {
        critical: 0.95,
        high: 0.85,
        medium: 0.75
      }
    },
    hourlyPattern: {
      peak: { start: 10, end: 18, multiplier: 1.2 },
      normal: { multiplier: 1.0 },
      low: { start: 2, end: 6, multiplier: 0.8 }
    }
  };

  const calculateResourceScore = () => {
    // Calculate weighted scores for each resource type
    const staffScore = calculateTypeScore(resourceData.staffing);
    const equipScore = calculateTypeScore(resourceData.equipment);
    const spaceScore = calculateTypeScore(resourceData.space);

    // Calculate total score (max 20)
    const totalScore = Math.round(
      (staffScore * resourceData.staffing.weight +
       equipScore * resourceData.equipment.weight +
       spaceScore * resourceData.space.weight) * 20
    );

    return {
      totalScore,
      componentScores: {
        staffing: Math.round(staffScore * 20),
        equipment: Math.round(equipScore * 20),
        space: Math.round(spaceScore * 20)
      }
    };
  };

  const calculateTypeScore = (data) => {
    const resources = Object.values(data.current);
    const avgUtilization = resources.reduce((sum, res) => sum + res.utilization, 0) / resources.length;
    
    // Apply penalties for exceeding thresholds
    let score = 1.0;
    if (avgUtilization >= data.thresholds.critical) {
      score *= 0.6;
    } else if (avgUtilization >= data.thresholds.high) {
      score *= 0.8;
    } else if (avgUtilization >= data.thresholds.medium) {
      score *= 0.9;
    }

    return score;
  };

  const getUtilizationData = () => {
    const data = [];
    const colorSchemes = {
      Staff: {
        normal: '#60a5fa',    // bright blue
        warning: '#f97316',   // orange
        critical: '#ef4444'   // red
      },
      Equipment: {
        normal: '#a78bfa',    // purple
        warning: '#f59e0b',   // amber
        critical: '#dc2626'   // darker red
      },
      Space: {
        normal: '#34d399',    // emerald
        warning: '#eab308',   // yellow
        critical: '#b91c1c'   // darkest red
      }
    };
    
    // Staff utilization
    Object.entries(resourceData.staffing.current).forEach(([role, stats]) => {
      data.push({
        resource: role,
        type: 'Staff',
        utilization: Math.round(stats.utilization * 100),
        threshold: Math.round(resourceData.staffing.thresholds.high * 100),
        critical: Math.round(resourceData.staffing.thresholds.critical * 100),
        colorScheme: colorSchemes.Staff
      });
    });

    // Equipment utilization
    Object.entries(resourceData.equipment.current).forEach(([item, stats]) => {
      data.push({
        resource: item,
        type: 'Equipment',
        utilization: Math.round(stats.utilization * 100),
        threshold: Math.round(resourceData.equipment.thresholds.high * 100),
        critical: Math.round(resourceData.equipment.thresholds.critical * 100),
        colorScheme: colorSchemes.Equipment
      });
    });

    // Space utilization
    Object.entries(resourceData.space.current).forEach(([area, stats]) => {
      data.push({
        resource: area,
        type: 'Space',
        utilization: Math.round(stats.utilization * 100),
        threshold: Math.round(resourceData.space.thresholds.high * 100),
        critical: Math.round(resourceData.space.thresholds.critical * 100),
        colorScheme: colorSchemes.Space
      });
    });

    return data;
  };

  // Generate historical trend data
  const generateTrendData = () => {
    const weeks = 12; // 12 weeks of historical data
    const trendData = [];
    
    // Generate weekly data points
    for (let week = weeks - 1; week >= 0; week--) {
      const weekData = {
        week: `Week ${weeks - week}`,
        timestamp: new Date(Date.now() - (week * 7 * 24 * 60 * 60 * 1000)),
        staffing: calculateWeeklyAverage('staffing', week),
        equipment: calculateWeeklyAverage('equipment', week),
        space: calculateWeeklyAverage('space', week)
      };
      trendData.push(weekData);
    }
    
    return trendData;
  };

  const calculateWeeklyAverage = (resourceType, weekOffset) => {
    // Simulate historical patterns with some randomization
    const baseUtilization = {
      staffing: 0.85,
      equipment: 0.80,
      space: 0.90
    }[resourceType];
    
    // Add slight variations and seasonal patterns
    const seasonalFactor = Math.sin((weekOffset / 12) * Math.PI) * 0.05;
    const randomFactor = (Math.random() - 0.5) * 0.05;
    
    return Math.min(1, Math.max(0.5, baseUtilization + seasonalFactor + randomFactor));
  };

  const getHourlyStressData = () => {
    return Array.from({ length: 24 }, (_, hour) => {
      let multiplier = resourceData.hourlyPattern.normal.multiplier;
      if (hour >= resourceData.hourlyPattern.peak.start && 
          hour <= resourceData.hourlyPattern.peak.end) {
        multiplier = resourceData.hourlyPattern.peak.multiplier;
      } else if (hour >= resourceData.hourlyPattern.low.start && 
                 hour <= resourceData.hourlyPattern.low.end) {
        multiplier = resourceData.hourlyPattern.low.multiplier;
      }

      const baseScore = calculateResourceScore().totalScore;
      return {
        hour: `${hour.toString().padStart(2, '0')}:00`,
        stressScore: Math.round(baseScore * multiplier),
        multiplier
      };
    });
  };

  const scoreDetails = calculateResourceScore();
  const utilizationData = getUtilizationData();
  const hourlyData = getHourlyStressData();

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Users className="h-6 w-6" />
          Resource Stress Analysis
        </CardTitle>
      </CardHeader>
      <CardContent>
        <div className="space-y-6">
          {/* Score Overview */}
          <div className="grid grid-cols-2 gap-4">
            <div className="p-4 bg-blue-50 rounded">
              <h3 className="font-bold text-blue-700">
                Resource Stress Score: {scoreDetails.totalScore}/20 points
              </h3>
              <div className="mt-4">
                <p className="text-sm font-medium">Component Scores:</p>
                <ul className="mt-2 text-sm space-y-1">
                  <li>Staff (40%): {scoreDetails.componentScores.staffing}/20</li>
                  <li>Equipment (30%): {scoreDetails.componentScores.equipment}/20</li>
                  <li>Space (30%): {scoreDetails.componentScores.space}/20</li>
                </ul>
              </div>
            </div>

            <div className="p-4 bg-red-50 rounded">
              <h3 className="font-bold text-red-700 flex items-center gap-2">
                <AlertTriangle className="h-5 w-5" />
                Critical Resource Alerts
              </h3>
              <div className="mt-2 space-y-2">
                {utilizationData.filter(d => d.utilization >= d.critical).map(resource => (
                  <div key={resource.resource} className="text-sm">
                    <p className="font-medium">{resource.resource}:</p>
                    <p className="text-red-600">
                      {resource.utilization}% utilization (Critical: {resource.critical}%)
                    </p>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* Resource Utilization Chart */}
          <div className="mt-6">
            <h3 className="font-bold mb-4">Resource Utilization</h3>
            <div className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={utilizationData}
                  margin={{
                    top: 20,
                    right: 30,
                    left: 20,
                    bottom: 5,
                  }}
                >
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="resource" />
                  <YAxis domain={[0, 100]} label={{ value: 'Utilization %', angle: -90, position: 'insideLeft' }} />
                  <Tooltip 
                    content={({ active, payload, label }) => {
                      if (active && payload && payload.length) {
                        const data = payload[0].payload;
                        return (
                          <div className="bg-white p-2 border rounded shadow">
                            <p className="font-semibold">{label}</p>
                            <p>Type: {data.type}</p>
                            <p>Utilization: {data.utilization}%</p>
                            <p>High Threshold: {data.threshold}%</p>
                            <p>Critical: {data.critical}%</p>
                          </div>
                        );
                      }
                      return null;
                    }}
                  />
                  <Legend />
                  <Bar 
                    name="Staff Resources" 
                    dataKey={(data) => data.type === 'Staff' ? data.utilization : 0}
                    fill="#60a5fa"
                  />
                  <Bar 
                    name="Equipment" 
                    dataKey={(data) => data.type === 'Equipment' ? data.utilization : 0}
                    fill="#a78bfa"
                  />
                  <Bar 
                    name="Space" 
                    dataKey={(data) => data.type === 'Space' ? data.utilization : 0}
                    fill="#34d399"
                  />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </div>

          {/* 24-Hour Stress Pattern */}
          <div className="mt-6">
            <h3 className="font-bold mb-4 flex items-center gap-2">
              <Activity className="h-5 w-5" />
              24-Hour Stress Pattern
            </h3>
            <div className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart
                  data={hourlyData}
                  margin={{
                    top: 20,
                    right: 30,
                    left: 20,
                    bottom: 5,
                  }}
                >
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="hour" interval={2} />
                  <YAxis domain={[0, 20]} />
                  <Tooltip 
                    content={({ active, payload, label }) => {
                      if (active && payload && payload.length) {
                        const data = payload[0].payload;
                        return (
                          <div className="bg-white p-2 border rounded shadow">
                            <p className="font-semibold">{label}</p>
                            <p>Stress Score: {data.stressScore}/20</p>
                            <p>Load Multiplier: {data.multiplier}x</p>
                          </div>
                        );
                      }
                      return null;
                    }}
                  />
                  <Line 
                    type="monotone" 
                    dataKey="stressScore" 
                    stroke="#8884d8" 
                    strokeWidth={2}
                  />
                </LineChart>
              </ResponsiveContainer>
            </div>
          </div>

          {/* Threshold Analysis */}
          <div className="mt-4 p-4 bg-gray-50 rounded">
            <h3 className="font-bold text-gray-700">Utilization Thresholds</h3>
            <div className="mt-2 grid grid-cols-3 gap-4">
              <div>
                <h4 className="font-medium">Staff</h4>
                <p className="text-sm">Critical: {resourceData.staffing.thresholds.critical * 100}%</p>
                <p className="text-sm">High: {resourceData.staffing.thresholds.high * 100}%</p>
                <p className="text-sm">Medium: {resourceData.staffing.thresholds.medium * 100}%</p>
              </div>
              <div>
                <h4 className="font-medium">Equipment</h4>
                <p className="text-sm">Critical: {resourceData.equipment.thresholds.critical * 100}%</p>
                <p className="text-sm">High: {resourceData.equipment.thresholds.high * 100}%</p>
                <p className="text-sm">Medium: {resourceData.equipment.thresholds.medium * 100}%</p>
              </div>
              <div>
                <h4 className="font-medium">Space</h4>
                <p className="text-sm">Critical: {resourceData.space.thresholds.critical * 100}%</p>
                <p className="text-sm">High: {resourceData.space.thresholds.high * 100}%</p>
                <p className="text-sm">Medium: {resourceData.space.thresholds.medium * 100}%</p>
              </div>
            </div>
          </div>

          {/* Historical Trend Analysis */}
          <div className="mt-6">
            <h3 className="font-bold mb-4">12-Week Resource Utilization Trends</h3>
            <div className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart
                  data={generateTrendData()}
                  margin={{
                    top: 20,
                    right: 30,
                    left: 20,
                    bottom: 5,
                  }}
                >
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis 
                    dataKey="week"
                    interval={1}
                    angle={-45}
                    textAnchor="end"
                    height={60}
                  />
                  <YAxis 
                    domain={[0.5, 1]} 
                    tickFormatter={(value) => `${Math.round(value * 100)}%`}
                    label={{ value: 'Utilization %', angle: -90, position: 'insideLeft' }}
                  />
                  <Tooltip 
                    content={({ active, payload, label }) => {
                      if (active && payload && payload.length) {
                        return (
                          <div className="bg-white p-2 border rounded shadow">
                            <p className="font-semibold">{label}</p>
                            {payload.map((entry, index) => (
                              <p key={index} style={{ color: entry.stroke }}>
                                {entry.name}: {Math.round(entry.value * 100)}%
                              </p>
                            ))}
                          </div>
                        );
                      }
                      return null;
                    }}
                  />
                  <Legend />
                  <Line 
                    type="monotone" 
                    dataKey="staffing" 
                    name="Staff Utilization"
                    stroke="#4f46e5" 
                    strokeWidth={2}
                    dot={false}
                  />
                  <Line 
                    type="monotone" 
                    dataKey="equipment" 
                    name="Equipment Utilization"
                    stroke="#0891b2" 
                    strokeWidth={2}
                    dot={false}
                  />
                  <Line 
                    type="monotone" 
                    dataKey="space" 
                    name="Space Utilization"
                    stroke="#15803d" 
                    strokeWidth={2}
                    dot={false}
                  />
                </LineChart>
              </ResponsiveContainer>
            </div>
            
            <div className="mt-4 p-4 bg-gray-50 rounded">
              <h4 className="font-bold text-gray-700 mb-2">Trend Analysis Insights</h4>
              <div className="grid grid-cols-3 gap-4 text-sm">
                <div>
                  <h5 className="font-medium text-indigo-700">Staff Trends</h5>
                  <p>• Consistent high utilization</p>
                  <p>• Peak periods correlate with seasonal demand</p>
                  <p>
                    • Average: {
                      Math.round(generateTrendData()
                        .reduce((acc, week) => acc + week.staffing, 0) / 12 * 100)
                    }%
                  </p>
                </div>
                <div>
                  <h5 className="font-medium text-cyan-700">Equipment Trends</h5>
                  <p>• More stable utilization patterns</p>
                  <p>• Lower variation week-to-week</p>
                  <p>
                    • Average: {
                      Math.round(generateTrendData()
                        .reduce((acc, week) => acc + week.equipment, 0) / 12 * 100)
                    }%
                  </p>
                </div>
                <div>
                  <h5 className="font-medium text-green-700">Space Trends</h5>
                  <p>• Highest average utilization</p>
                  <p>• Most frequent critical thresholds</p>
                  <p>
                    • Average: {
                      Math.round(generateTrendData()
                        .reduce((acc, week) => acc + week.space, 0) / 12 * 100)
                    }%
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

/* ------------------------------------------------------------------
   2) CASCADE IMPACT ANALYSIS
   ------------------------------------------------------------------ */
function CascadeAnalysis() {
  // Example cascade impact data
  const cascadeData = {
    primaryProcess: "ED Admission",
    affectedProcesses: [
      {
        name: "Bed Management",
        severity: 0.8,
        timeImpact: 45, // minutes
        resourceImpact: 0.7,
        affectedVolume: 85, // percentage of cases
        dependencies: ["Nursing Assignment", "Room Cleaning"],
        type: "critical"
      },
      {
        name: "Staff Scheduling",
        severity: 0.6,
        timeImpact: 30,
        resourceImpact: 0.5,
        affectedVolume: 60,
        dependencies: ["Shift Planning"],
        type: "operational"
      },
      {
        name: "Medication Administration",
        severity: 0.7,
        timeImpact: 25,
        resourceImpact: 0.6,
        affectedVolume: 70,
        dependencies: ["Pharmacy", "Nursing"],
        type: "clinical"
      },
      {
        name: "Discharge Planning",
        severity: 0.5,
        timeImpact: 35,
        resourceImpact: 0.4,
        affectedVolume: 45,
        dependencies: ["Case Management"],
        type: "support"
      }
    ]
  };

  const calculateCascadeScore = () => {
    const processes = cascadeData.affectedProcesses;
    
    // Calculate weighted impact
    const impactScores = processes.map(proc => ({
      name: proc.name,
      severityScore: Math.round(proc.severity * 5),         // 5 points max
      volumeScore: Math.round((proc.affectedVolume / 100) * 5), 
      resourceScore: Math.round(proc.resourceImpact * 5),
      timeScore: Math.round((Math.min(proc.timeImpact, 60) / 60) * 5)
    }));

    // The sum of each process’s sub-scores, then average them => max 20
    const totalScore = Math.min(20, Math.round(
      impactScores.reduce((sum, p) => 
        sum + (p.severityScore + p.volumeScore + p.resourceScore + p.timeScore) / 4
      , 0)
    ));

    return {
      totalScore,
      processScores: impactScores
    };
  };

  const getProcessTypeData = () => {
    const typeCount = {
      critical: 0,
      operational: 0,
      clinical: 0,
      support: 0
    };

    cascadeData.affectedProcesses.forEach(proc => {
      typeCount[proc.type]++;
    });

    return Object.entries(typeCount).map(([type, count]) => ({
      type,
      count,
      percentage: (count / cascadeData.affectedProcesses.length) * 100
    }));
  };

  const scoreDetails = calculateCascadeScore();
  const processTypes = getProcessTypeData();

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Share2 className="h-6 w-6" />
          Cascading Impact Analysis
        </CardTitle>
      </CardHeader>
      <CardContent>
        <div className="space-y-6">
          {/* Score Overview */}
          <div className="grid grid-cols-2 gap-4">
            <div className="p-4 bg-blue-50 rounded">
              <h3 className="font-bold text-blue-700">
                Cascade Impact Score: {scoreDetails.totalScore}/20 points
              </h3>
              <div className="mt-4">
                <p className="text-sm font-medium">Primary Process: {cascadeData.primaryProcess}</p>
                <p className="text-sm mt-2">
                  Affected Processes: {cascadeData.affectedProcesses.length}
                </p>
                <div className="mt-4">
                  <p className="text-sm font-medium">Process Types:</p>
                  {processTypes.map(type => (
                    <p key={type.type} className="text-sm">
                      • {type.type}: {type.count} ({Math.round(type.percentage)}%)
                    </p>
                  ))}
                </div>
              </div>
            </div>

            <div className="p-4 bg-yellow-50 rounded">
              <h3 className="font-bold text-yellow-700 flex items-center gap-2">
                <AlertTriangle className="h-5 w-5" />
                Critical Dependencies
              </h3>
              <div className="mt-2 space-y-2">
                {cascadeData.affectedProcesses.map(process => (
                  <div key={process.name} className="text-sm">
                    <p className="font-medium">{process.name}:</p>
                    <p className="text-gray-600">
                      Depends on: {process.dependencies.join(", ")}
                    </p>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* Impact Metrics Chart */}
          <div className="mt-6">
            <h3 className="font-bold mb-4">Process Impact Metrics</h3>
            <div className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={cascadeData.affectedProcesses}
                  margin={{
                    top: 20,
                    right: 30,
                    left: 20,
                    bottom: 5,
                  }}
                >
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="name" />
                  <YAxis />
                  <Tooltip 
                    content={({ active, payload, label }) => {
                      if (active && payload && payload.length) {
                        const data = payload[0].payload;
                        return (
                          <div className="bg-white p-2 border rounded shadow">
                            <p className="font-semibold">{label}</p>
                            <p>Severity: {Math.round(data.severity * 100)}%</p>
                            <p>Time Impact: {data.timeImpact} min</p>
                            <p>Resource Impact: {Math.round(data.resourceImpact * 100)}%</p>
                            <p>Volume Affected: {data.affectedVolume}%</p>
                          </div>
                        );
                      }
                      return null;
                    }}
                  />
                  <Legend />
                  <Bar dataKey="severity" name="Impact Severity" fill="#8884d8" />
                  <Bar dataKey="resourceImpact" name="Resource Impact" fill="#82ca9d" />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </div>

          {/* Time Impact Analysis */}
          <div className="mt-6">
            <h3 className="font-bold mb-4 flex items-center gap-2">
              <Clock className="h-5 w-5" />
              Time Impact Distribution
            </h3>
            <div className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={cascadeData.affectedProcesses}
                  margin={{
                    top: 20,
                    right: 30,
                    left: 20,
                    bottom: 5,
                  }}
                >
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="name" />
                  <YAxis label={{ value: 'Minutes Delayed', angle: -90, position: 'insideLeft' }} />
                  <Tooltip />
                  <Bar 
                    dataKey="timeImpact" 
                    name="Process Delay (minutes)" 
                    fill="#ffc658"
                  />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </div>

          {/* Score Breakdown */}
          <div className="mt-4 p-4 bg-gray-50 rounded">
            <h3 className="font-bold text-gray-700">Score Components</h3>
            <div className="mt-2 space-y-2">
              {scoreDetails.processScores.map(p => (
                <div key={p.name} className="grid grid-cols-5 gap-2 text-sm">
                  <div className="font-medium">{p.name}:</div>
                  <div>Severity: {p.severityScore}/5</div>
                  <div>Volume: {p.volumeScore}/5</div>
                  <div>Resource: {p.resourceScore}/5</div>
                  <div>Time: {p.timeScore}/5</div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

/* ------------------------------------------------------------------
   3) WAIT TIME ANALYSIS
   ------------------------------------------------------------------ */
function WaitTimeAnalysis() {
  // Example wait time data
  const waitTimeData = {
    current: {
      registration: 15,
      triage: 25,
      bedAssignment: 45,
      physicianInitial: 35,
      nurseAssessment: 25
    },
    benchmark: {
      registration: 10,
      triage: 15,
      bedAssignment: 20,
      physicianInitial: 30,
      nurseAssessment: 20
    },
    peakMultipliers: {
      morning: 1.3,   // 8-11am
      afternoon: 1.5, // 2-5pm
      evening: 1.2,   // 6-9pm
      night: 1.0      // 10pm-7am
    }
  };

  const calculateWaitTimeScore = () => {
    const steps = Object.keys(waitTimeData.current);
    let totalDeviation = 0;
    let criticalDeviations = [];

    steps.forEach(step => {
      const current = waitTimeData.current[step];
      const benchmark = waitTimeData.benchmark[step];
      const deviation = (current - benchmark) / benchmark;
      totalDeviation += deviation;

      if (deviation > 0.5) { // More than 50% over benchmark
        criticalDeviations.push({
          step,
          deviation: Math.round(deviation * 100)
        });
      }
    });

    const avgDeviation = totalDeviation / steps.length;
    // Max 25 points; subtract for average deviation
    const baseScore = Math.min(25, Math.round((1 - avgDeviation) * 25));

    return {
      baseScore,
      avgDeviation,
      criticalDeviations
    };
  };

  const getComparisonData = () => {
    return Object.keys(waitTimeData.current).map(step => ({
      step,
      current: waitTimeData.current[step],
      benchmark: waitTimeData.benchmark[step],
      deviation: Math.round(((waitTimeData.current[step] - waitTimeData.benchmark[step]) / waitTimeData.benchmark[step]) * 100)
    }));
  };

  const getHourlyData = () => {
    return Array.from({ length: 24 }, (_, hour) => {
      let multiplier = waitTimeData.peakMultipliers.night; // default
      if (hour >= 8 && hour <= 11) multiplier = waitTimeData.peakMultipliers.morning;
      if (hour >= 14 && hour <= 17) multiplier = waitTimeData.peakMultipliers.afternoon;
      if (hour >= 18 && hour <= 21) multiplier = waitTimeData.peakMultipliers.evening;
      
      return {
        hour: `${hour.toString().padStart(2, '0')}:00`,
        multiplier: multiplier,
        adjustedScore: Math.round(calculateWaitTimeScore().baseScore * multiplier)
      };
    });
  };

  const scoreDetails = calculateWaitTimeScore();
  const comparisonData = getComparisonData();
  const hourlyData = getHourlyData();

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Clock className="h-6 w-6" />
          Wait Time Analysis
        </CardTitle>
      </CardHeader>
      <CardContent>
        <div className="space-y-6">
          {/* Current Score Overview */}
          <div className="grid grid-cols-2 gap-4">
            <div className="p-4 bg-blue-50 rounded">
              <h3 className="font-bold text-blue-700">
                Base Wait Time Score: {scoreDetails.baseScore}/25 points
              </h3>
              <p className="mt-2 text-sm">
                Average Deviation: {Math.round(scoreDetails.avgDeviation * 100)}% from benchmark
              </p>
              <div className="mt-4">
                <p className="text-sm font-medium">Peak Time Multipliers:</p>
                <ul className="mt-2 text-sm space-y-1">
                  <li>Morning (8-11am): {waitTimeData.peakMultipliers.morning}x</li>
                  <li>Afternoon (2-5pm): {waitTimeData.peakMultipliers.afternoon}x</li>
                  <li>Evening (6-9pm): {waitTimeData.peakMultipliers.evening}x</li>
                  <li>Night (10pm-7am): {waitTimeData.peakMultipliers.night}x</li>
                </ul>
              </div>
            </div>

            {/* Critical Deviations Alert */}
            <div className="p-4 bg-red-50 rounded">
              <h3 className="font-bold text-red-700 flex items-center gap-2">
                <AlertTriangle className="h-5 w-5" />
                Critical Deviations
              </h3>
              {scoreDetails.criticalDeviations.length > 0 ? (
                <div className="mt-2 space-y-2">
                  {scoreDetails.criticalDeviations.map(({ step, deviation }) => (
                    <div key={step} className="text-sm">
                      <p className="font-medium">{step}:</p>
                      <p className="text-red-600">{deviation}% above benchmark</p>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="mt-2 text-sm">No critical deviations detected</p>
              )}
            </div>
          </div>

          {/* Wait Time Comparison Chart */}
          <div className="mt-6">
            <h3 className="font-bold mb-4">Current vs Benchmark Wait Times</h3>
            <div className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={comparisonData}
                  margin={{
                    top: 20,
                    right: 30,
                    left: 20,
                    bottom: 5,
                  }}
                >
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="step" />
                  <YAxis label={{ value: 'Minutes', angle: -90, position: 'insideLeft' }} />
                  <Tooltip 
                    content={({ active, payload, label }) => {
                      if (active && payload && payload.length) {
                        const d = payload[0].payload;
                        return (
                          <div className="bg-white p-2 border rounded shadow">
                            <p className="font-semibold">{label}</p>
                            <p>Current: {d.current} min</p>
                            <p>Benchmark: {d.benchmark} min</p>
                            <p className={d.deviation > 0 ? "text-red-500" : "text-green-500"}>
                              {d.deviation > 0 ? '+' : ''}{d.deviation}% deviation
                            </p>
                          </div>
                        );
                      }
                      return null;
                    }}
                  />
                  <Legend />
                  <Bar dataKey="current" name="Current Wait (min)" fill="#8884d8" />
                  <Bar dataKey="benchmark" name="Benchmark (min)" fill="#82ca9d" />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </div>

          {/* 24-hour Score Variation */}
          <div className="mt-6">
            <h3 className="font-bold mb-4">24-Hour Score Variation</h3>
            <div className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart
                  data={hourlyData}
                  margin={{
                    top: 20,
                    right: 30,
                    left: 20,
                    bottom: 5,
                  }}
                >
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="hour" interval={2} />
                  <YAxis domain={[0, 25]} label={{ value: 'Adjusted Score', angle: -90, position: 'insideLeft' }} />
                  <Tooltip 
                    content={({ active, payload, label }) => {
                      if (active && payload && payload.length) {
                        const d = payload[0].payload;
                        return (
                          <div className="bg-white p-2 border rounded shadow">
                            <p className="font-semibold">{label}</p>
                            <p>Multiplier: {d.multiplier}x</p>
                            <p>Adjusted Score: {d.adjustedScore}</p>
                          </div>
                        );
                      }
                      return null;
                    }}
                  />
                  <Line type="monotone" dataKey="adjustedScore" stroke="#8884d8" strokeWidth={2} />
                </LineChart>
              </ResponsiveContainer>
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

/* ------------------------------------------------------------------
   4) ACUITY (PATIENT MIX) ANALYSIS
   ------------------------------------------------------------------ */
function ScoreCalculator() {
  // Example bottleneck data
  const bottleneckData = {
    patientVolume: {
      count: 42,
      totalDailyPatients: 120,
      acuityBreakdown: {
        high: 15,
        medium: 20,
        low: 7
      }
    },
    expectedAcuityMix: {
      high: 0.25,    // 25% expected high acuity
      medium: 0.50,  // 50% expected medium acuity
      low: 0.25
    }
  };

  // Acuity weights normalized to 0-1 scale
  const acuityWeights = {
    high: 1.0,
    medium: 0.6,
    low: 0.2
  };

  const calculateAcuityScore = () => {
    const totalPatients = bottleneckData.patientVolume.count;
    
    // Actual percentages
    const acuityPercentages = {
      high: bottleneckData.patientVolume.acuityBreakdown.high / totalPatients,
      medium: bottleneckData.patientVolume.acuityBreakdown.medium / totalPatients,
      low: bottleneckData.patientVolume.acuityBreakdown.low / totalPatients
    };

    // Weighted sum => max 15 points
    const weightedScore = (
      acuityPercentages.high * acuityWeights.high +
      acuityPercentages.medium * acuityWeights.medium +
      acuityPercentages.low * acuityWeights.low
    );

    return {
      score: Math.round(weightedScore * 15),
      percentages: acuityPercentages
    };
  };

  const getAcuityDistributionData = () => {
    const { percentages } = calculateAcuityScore();

    return [
      {
        category: 'High Acuity',
        actual: Math.round(percentages.high * 100),
        expected: Math.round(0.25 * 100),
        weight: 1.0
      },
      {
        category: 'Medium Acuity',
        actual: Math.round(percentages.medium * 100),
        expected: Math.round(0.50 * 100),
        weight: 0.6
      },
      {
        category: 'Low Acuity',
        actual: Math.round(percentages.low * 100),
        expected: Math.round(0.25 * 100),
        weight: 0.2
      }
    ];
  };

  const acuityScoreDetails = calculateAcuityScore();
  const distData = getAcuityDistributionData();

  return (
    <Card>
      <CardHeader>
        <CardTitle>Acuity Mix Analysis</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-4">
            <div className="p-4 bg-blue-50 rounded">
              <h3 className="font-bold text-blue-700">
                Acuity Score: {acuityScoreDetails.score}/15 points
              </h3>
              <div className="mt-4">
                <p className="text-sm font-medium">Score Calculation:</p>
                <ul className="mt-2 text-sm space-y-1">
                  {distData.map(level => (
                    <li key={level.category}>
                      • {level.category}: {level.actual}% × {level.weight.toFixed(1)} weight
                    </li>
                  ))}
                </ul>
              </div>
            </div>

            <div className="p-4 bg-gray-50 rounded">
              <h3 className="font-bold text-gray-700">Distribution Statistics</h3>
              <div className="mt-2 text-sm space-y-2">
                <p>Total Patients: {bottleneckData.patientVolume.count}</p>
                <p>High: {bottleneckData.patientVolume.acuityBreakdown.high} patients</p>
                <p>Medium: {bottleneckData.patientVolume.acuityBreakdown.medium} patients</p>
                <p>Low: {bottleneckData.patientVolume.acuityBreakdown.low} patients</p>
              </div>
            </div>
          </div>

          <div className="mt-6">
            <h3 className="font-bold mb-4">Acuity Distribution Comparison</h3>
            <div className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={distData}
                  margin={{
                    top: 20,
                    right: 30,
                    left: 20,
                    bottom: 5,
                  }}
                >
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="category" />
                  <YAxis label={{ value: 'Percentage of Patients', angle: -90, position: 'insideLeft' }} />
                  <Tooltip 
                    content={({ active, payload, label }) => {
                      if (active && payload && payload.length) {
                        return (
                          <div className="bg-white p-2 border rounded shadow">
                            <p className="font-semibold">{label}</p>
                            <p>Actual: {payload[0].value}%</p>
                            <p>Expected: {payload[1].value}%</p>
                            <p>Weight: {distData.find(d => d.category === label)?.weight.toFixed(1)}</p>
                          </div>
                        );
                      }
                      return null;
                    }}
                  />
                  <Legend />
                  <Bar dataKey="actual" name="Actual %" fill="#8884d8" />
                  <Bar dataKey="expected" name="Expected %" fill="#82ca9d" />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </div>

          <div className="mt-4 p-4 bg-yellow-50 rounded">
            <h3 className="font-bold text-yellow-700">Distribution Analysis</h3>
            <div className="mt-2 space-y-2">
              {distData.map(level => {
                const diff = level.actual - level.expected;
                return (
                  <div key={level.category} className="text-sm">
                    <p className="font-medium">{level.category}:</p>
                    <p>
                      {diff > 0 
                        ? `${diff}% above expected - higher resource demand`
                        : diff < 0
                          ? `${Math.abs(diff)}% below expected - lower resource demand`
                          : 'Matching expected distribution'
                      }
                    </p>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

/* ------------------------------------------------------------------
   5) DAILY BOTTLENECK REPORT (AGGREGATOR + TOP 5)
   ------------------------------------------------------------------ */
function DailyBottleneckReport() {
  /*
    Here we show how you might do a “Top 5 Bottlenecks” summary 
    by pulling final scores from each of the four analyses.

    In a real app, you’d unify data sources so that “resourceStressScore”, 
    “cascadeScore”, “waitTimeScore”, and “acuityScore” 
    are computed from the same day’s data logs. 
  */

  // -- Simple aggregator: We'll replicate the final score computations 
  // -- from each subcomponent in minimal form and then produce a 
  // -- “bottlenecks” array. 
  // -- (In a real scenario, you’d tie them to shared datasets or global state.)

  // 1) Resource Stress (max 20)
  const getResourceStressScore = () => {
    // This is demonstration-only. In reality, you'd call the same logic 
    // or share the same data used in <ResourceStressAnalysis />.
    return 14; 
  };

  // 2) Cascade Impact (max 20)
  const getCascadeScore = () => {
    return 16; 
  };

  // 3) Wait Time (max 25)
  const getWaitTimeScore = () => {
    return 18;
  };

  // 4) Acuity (max 15)
  const getAcuityScore = () => {
    return 10;
  };

  // Example: we might define each “bottleneck candidate” with partial scores 
  // for each dimension. In real usage, these would come from your process logs.
  const bottlenecks = [
    {
      id: 1,
      name: 'Triage Overload',
      resourceStress: 12,
      cascade: 8,
      waitTime: 20,
      acuity: 6,
    },
    {
      id: 2,
      name: 'Bed Assignment Delays',
      resourceStress: 15,
      cascade: 12,
      waitTime: 16,
      acuity: 8,
    },
    {
      id: 3,
      name: 'Radiology Queue',
      resourceStress: 10,
      cascade: 5,
      waitTime: 17,
      acuity: 9,
    },
    {
      id: 4,
      name: 'Nurse Staffing Gap',
      resourceStress: 18,
      cascade: 7,
      waitTime: 12,
      acuity: 10,
    },
    {
      id: 5,
      name: 'High-Acuity Surge',
      resourceStress: 14,
      cascade: 15,
      waitTime: 10,
      acuity: 12,
    },
    {
      id: 6,
      name: 'Operating Room Bottleneck',
      resourceStress: 13,
      cascade: 17,
      waitTime: 8,
      acuity: 10,
    },
  ];

  /*
    We can combine these partial scores into a single “bottleneck severity” 
    using a weighting scheme. For example:

    - resourceStressWeight = 0.30 (out of 20)
    - cascadeWeight        = 0.25 (out of 20)
    - waitTimeWeight       = 0.30 (out of 25)
    - acuityWeight         = 0.15 (out of 15)

    Then we scale each dimension to 0–1, multiply by the weight, sum up, 
    and maybe map that sum back to 0–100 or similar.
  */
  const resourceStressWeight = 0.30;
  const cascadeWeight = 0.25;
  const waitTimeWeight = 0.30;
  const acuityWeight = 0.15;

  const computeSeverity = (b) => {
    // Convert each raw score to [0–1] by dividing by the dimension max
    const resourceNormalized = b.resourceStress / 20;
    const cascadeNormalized = b.cascade / 20;
    const waitTimeNormalized = b.waitTime / 25;
    const acuityNormalized = b.acuity / 15;

    // Weighted sum => final 0–1
    const weightedSum = 
      resourceNormalized * resourceStressWeight +
      cascadeNormalized * cascadeWeight +
      waitTimeNormalized * waitTimeWeight +
      acuityNormalized * acuityWeight;

    // We'll map that 0–1 up to 100 for readability
    return Math.round(weightedSum * 100);
  };

  // Add a “severityScore” to each bottleneck
  const scoredBottlenecks = bottlenecks.map(b => ({
    ...b,
    severityScore: computeSeverity(b)
  }));

  // Sort descending by severity
  scoredBottlenecks.sort((a, b) => b.severityScore - a.severityScore);

  // Take top 5
  const topFive = scoredBottlenecks.slice(0, 5);

  return (
    <div className="space-y-6">
      {/* ------------------------ 
          TOP 5 BOTTLENECK SUMMARY
      --------------------------- */}
      <Card>
        <CardHeader>
          <CardTitle>Daily Bottlenecks: Top 5</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <table className="w-full text-sm border-collapse">
              <thead>
                <tr className="bg-gray-100">
                  <th className="p-2 text-left">Rank</th>
                  <th className="p-2 text-left">Bottleneck</th>
                  <th className="p-2">Resource (max 20)</th>
                  <th className="p-2">Cascade (max 20)</th>
                  <th className="p-2">Wait (max 25)</th>
                  <th className="p-2">Acuity (max 15)</th>
                  <th className="p-2">Severity (0–100)</th>
                </tr>
              </thead>
              <tbody>
                {topFive.map((b, idx) => (
                  <tr key={b.id} className="border-b last:border-none">
                    <td className="p-2">{idx + 1}</td>
                    <td className="p-2">{b.name}</td>
                    <td className="p-2 text-center">{b.resourceStress}</td>
                    <td className="p-2 text-center">{b.cascade}</td>
                    <td className="p-2 text-center">{b.waitTime}</td>
                    <td className="p-2 text-center">{b.acuity}</td>
                    <td className="p-2 text-center font-semibold">
                      {b.severityScore}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-4 p-4 bg-blue-50 rounded text-sm">
            <p className="font-bold text-blue-700 mb-1">Scoring Logic</p>
            <p className="mb-1">
              • Resource Stress, Cascade Impact, Wait Time, and Acuity Mix are normalized 
              to a 0–1 range based on their respective maximum values.
            </p>
            <p className="mb-1">
              • Weighted sum is computed as:
            </p>
            <ul className="list-disc list-inside mb-2">
              <li>Resource Stress Weight: 0.30 (max 20)</li>
              <li>Cascade Weight: 0.25 (max 20)</li>
              <li>Wait Time Weight: 0.30 (max 25)</li>
              <li>Acuity Weight: 0.15 (max 15)</li>
            </ul>
            <p className="mb-1">
              • Final severity score mapped to 0–100 for ease of interpretation.
            </p>
            <p>
              • Top 5 are displayed based on descending “Severity” scores.
            </p>
          </div>
        </CardContent>
      </Card>

      {/* ---------------------------------------------------------
          Include your four sub-components below as daily metrics
      --------------------------------------------------------- */}
      <ResourceStressAnalysis />
      <CascadeAnalysis />
      <WaitTimeAnalysis />
      <ScoreCalculator />
    </div>
  );
}

export default DailyBottleneckReport;
