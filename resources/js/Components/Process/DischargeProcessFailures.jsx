import React from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend } from 'recharts';
import { Filter, AlertCircle, Clock, FileX, Users, MessageSquare } from 'lucide-react';

const DischargeProcessFailures = () => {
  const [activeTab, setActiveTab] = React.useState('administrative');
  const [selectedFilters, setSelectedFilters] = React.useState({
    failureType: 'all',
    severity: 'all',
    unit: 'all',
    impact: 'all'
  });

  const tabs = [
    { id: 'administrative', label: 'Administrative' },
    { id: 'clinical', label: 'Clinical' },
    { id: 'logistical', label: 'Logistical' },
    { id: 'communication', label: 'Communication' }
  ];

  // Mock data for process failures
  const failureData = {
    administrative: {
      '24h': [
        { type: 'Insurance Authorization', count: 8, severity: 'high', impact: 'Extended LOS' },
        { type: 'Missing Documentation', count: 12, severity: 'medium', impact: 'Delayed Discharge' },
        { type: 'Incomplete Orders', count: 6, severity: 'medium', impact: 'Workflow Disruption' }
      ],
      '36h': [
        { type: 'Insurance Authorization', count: 15, severity: 'high', impact: 'Extended LOS' },
        { type: 'Missing Documentation', count: 18, severity: 'medium', impact: 'Delayed Discharge' },
        { type: 'Incomplete Orders', count: 10, severity: 'medium', impact: 'Workflow Disruption' }
      ],
      '48h': [
        { type: 'Insurance Authorization', count: 22, severity: 'high', impact: 'Extended LOS' },
        { type: 'Missing Documentation', count: 25, severity: 'medium', impact: 'Delayed Discharge' },
        { type: 'Incomplete Orders', count: 14, severity: 'medium', impact: 'Workflow Disruption' }
      ]
    },
    clinical: {
      '24h': [
        { type: 'Medication Reconciliation', count: 10, severity: 'high', impact: 'Patient Safety' },
        { type: 'Care Plan Updates', count: 7, severity: 'medium', impact: 'Care Continuity' },
        { type: 'Clinical Assessment', count: 5, severity: 'high', impact: 'Care Quality' }
      ],
      '36h': [
        { type: 'Medication Reconciliation', count: 16, severity: 'high', impact: 'Patient Safety' },
        { type: 'Care Plan Updates', count: 12, severity: 'medium', impact: 'Care Continuity' },
        { type: 'Clinical Assessment', count: 8, severity: 'high', impact: 'Care Quality' }
      ],
      '48h': [
        { type: 'Medication Reconciliation', count: 24, severity: 'high', impact: 'Patient Safety' },
        { type: 'Care Plan Updates', count: 18, severity: 'medium', impact: 'Care Continuity' },
        { type: 'Clinical Assessment', count: 12, severity: 'high', impact: 'Care Quality' }
      ]
    },
    logistical: {
      '24h': [
        { type: 'Transport Coordination', count: 9, severity: 'medium', impact: 'Discharge Timing' },
        { type: 'Equipment Availability', count: 6, severity: 'high', impact: 'Resource Utilization' },
        { type: 'Bed Management', count: 8, severity: 'high', impact: 'Patient Flow' }
      ],
      '36h': [
        { type: 'Transport Coordination', count: 14, severity: 'medium', impact: 'Discharge Timing' },
        { type: 'Equipment Availability', count: 10, severity: 'high', impact: 'Resource Utilization' },
        { type: 'Bed Management', count: 13, severity: 'high', impact: 'Patient Flow' }
      ],
      '48h': [
        { type: 'Transport Coordination', count: 20, severity: 'medium', impact: 'Discharge Timing' },
        { type: 'Equipment Availability', count: 15, severity: 'high', impact: 'Resource Utilization' },
        { type: 'Bed Management', count: 19, severity: 'high', impact: 'Patient Flow' }
      ]
    },
    communication: {
      '24h': [
        { type: 'Team Handoff', count: 11, severity: 'high', impact: 'Care Coordination' },
        { type: 'Family Communication', count: 8, severity: 'medium', impact: 'Patient Experience' },
        { type: 'Provider Updates', count: 7, severity: 'medium', impact: 'Care Planning' }
      ],
      '36h': [
        { type: 'Team Handoff', count: 17, severity: 'high', impact: 'Care Coordination' },
        { type: 'Family Communication', count: 13, severity: 'medium', impact: 'Patient Experience' },
        { type: 'Provider Updates', count: 11, severity: 'medium', impact: 'Care Planning' }
      ],
      '48h': [
        { type: 'Team Handoff', count: 25, severity: 'high', impact: 'Care Coordination' },
        { type: 'Family Communication', count: 19, severity: 'medium', impact: 'Patient Experience' },
        { type: 'Provider Updates', count: 16, severity: 'medium', impact: 'Care Planning' }
      ]
    }
  };

  const filterOptions = {
    failureType: [
      { value: 'all', label: 'All Failure Types' },
      { value: 'administrative', label: 'Administrative' },
      { value: 'clinical', label: 'Clinical' },
      { value: 'logistical', label: 'Logistical' },
      { value: 'communication', label: 'Communication' }
    ],
    severity: [
      { value: 'all', label: 'All Severities' },
      { value: 'high', label: 'High' },
      { value: 'medium', label: 'Medium' },
      { value: 'low', label: 'Low' }
    ],
    unit: [
      { value: 'all', label: 'All Units' },
      { value: 'medical', label: 'Medical' },
      { value: 'surgical', label: 'Surgical' },
      { value: 'icu', label: 'ICU' }
    ],
    impact: [
      { value: 'all', label: 'All Impacts' },
      { value: 'los', label: 'Length of Stay' },
      { value: 'safety', label: 'Patient Safety' },
      { value: 'flow', label: 'Patient Flow' }
    ]
  };

  // Calculate summary metrics
  const calculateSummaryMetrics = () => {
    let totalFailures = 0;
    let highSeverity = 0;
    let impactedLOS = 0;
    let communicationIssues = 0;

    Object.values(failureData).forEach(category => {
      category['24h'].forEach(failure => {
        totalFailures += failure.count;
        if (failure.severity === 'high') highSeverity += failure.count;
        if (failure.impact === 'Extended LOS') impactedLOS += failure.count;
        if (failure.type.includes('Communication')) communicationIssues += failure.count;
      });
    });

    return { totalFailures, highSeverity, impactedLOS, communicationIssues };
  };

  const summaryMetrics = calculateSummaryMetrics();

  // Transform data for charts
  const getChartData = () => {
    const periods = ['24h', '36h', '48h'];
    const categories = Object.keys(failureData);
    
    return periods.map(period => {
      const dataPoint = { period };
      categories.forEach(category => {
        dataPoint[category] = failureData[category][period].reduce((sum, item) => sum + item.count, 0);
      });
      return dataPoint;
    });
  };

  const chartData = getChartData();

  const CustomTooltip = ({ active, payload, label }) => {
    if (!active || !payload || !payload.length) return null;

    return (
      <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-lg shadow-lg p-3">
        <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
          Past {label}
        </p>
        <div className="space-y-1">
          {payload.map((entry, index) => (
            <div key={index} className="flex items-center justify-between gap-4">
              <span className="text-sm capitalize">{entry.name}</span>
              <span className="text-sm font-medium">{entry.value} failures</span>
            </div>
          ))}
        </div>
      </div>
    );
  };

  return (
    <div className="space-y-6">
      {/* Filters */}
      <div className="flex flex-wrap gap-4 items-center">
        <div className="flex items-center gap-2">
          <Filter className="h-5 w-5 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
          <span className="text-sm font-medium">Analyze By:</span>
        </div>
        {Object.entries(filterOptions).map(([key, options]) => (
          <select
            key={key}
            value={selectedFilters[key]}
            onChange={(e) => setSelectedFilters(prev => ({ ...prev, [key]: e.target.value }))}
            className="rounded-md border border-healthcare-border bg-healthcare-surface text-healthcare-text-primary px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-healthcare-primary"
          >
            {options.map(option => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        ))}
      </div>

      {/* Summary Stats */}
      <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
        <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg">
          <div className="flex items-center gap-2 mb-2">
            <AlertCircle className="h-5 w-5 text-healthcare-critical" />
            <h3 className="text-sm font-medium">Total Failures (24h)</h3>
          </div>
          <p className="text-2xl font-semibold">{summaryMetrics.totalFailures}</p>
        </div>
        <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg">
          <div className="flex items-center gap-2 mb-2">
            <Clock className="h-5 w-5 text-healthcare-warning" />
            <h3 className="text-sm font-medium">High Severity</h3>
          </div>
          <p className="text-2xl font-semibold">{summaryMetrics.highSeverity}</p>
        </div>
        <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg">
          <div className="flex items-center gap-2 mb-2">
            <FileX className="h-5 w-5 text-healthcare-primary" />
            <h3 className="text-sm font-medium">Extended LOS</h3>
          </div>
          <p className="text-2xl font-semibold">{summaryMetrics.impactedLOS}</p>
        </div>
        <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg">
          <div className="flex items-center gap-2 mb-2">
            <MessageSquare className="h-5 w-5 text-healthcare-success" />
            <h3 className="text-sm font-medium">Communication Issues</h3>
          </div>
          <p className="text-2xl font-semibold">{summaryMetrics.communicationIssues}</p>
        </div>
      </div>

      {/* Trend Chart */}
      <div className="h-[300px]">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={chartData} barSize={40}>
            <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.1)" />
            <XAxis 
              dataKey="period" 
              stroke="currentColor"
              tickLine={false}
            />
            <YAxis
              stroke="currentColor"
              tickLine={false}
              label={{ value: 'Number of Failures', angle: -90, position: 'insideLeft' }}
            />
            <Tooltip content={<CustomTooltip />} />
            <Legend />
            <Bar 
              dataKey="administrative" 
              stackId="a" 
              fill="#4f46e5" 
              name="Administrative"
              activeBar={{ fill: '#3730a3' }}
            />
            <Bar 
              dataKey="clinical" 
              stackId="a" 
              fill="#06b6d4" 
              name="Clinical"
              activeBar={{ fill: '#0891b2' }}
            />
            <Bar 
              dataKey="logistical" 
              stackId="a" 
              fill="#8b5cf6" 
              name="Logistical"
              activeBar={{ fill: '#6d28d9' }}
            />
            <Bar 
              dataKey="communication" 
              stackId="a" 
              fill="#f59e0b" 
              name="Communication"
              activeBar={{ fill: '#d97706' }}
            />
          </BarChart>
        </ResponsiveContainer>
      </div>

      {/* Tab Navigation */}
      <div className="border-b border-healthcare-border dark:border-healthcare-border-dark">
        <nav className="flex space-x-4" aria-label="Failure Types">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`py-2 px-4 text-sm font-medium border-b-2 -mb-px transition-colors ${
                activeTab === tab.id
                  ? 'border-healthcare-primary text-healthcare-primary'
                  : 'border-transparent text-healthcare-text-secondary hover:text-healthcare-primary hover:border-healthcare-primary'
              }`}
              aria-current={activeTab === tab.id ? 'page' : undefined}
            >
              {tab.label}
            </button>
          ))}
        </nav>
      </div>

      {/* Tab Content */}
      <div className="mt-6">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          {Object.entries(failureData[activeTab]).map(([timeframe, failures]) => (
            <div 
              key={timeframe}
              className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg"
            >
              <h4 className="text-sm font-medium mb-4">Past {timeframe}</h4>
              <div className="space-y-3">
                {failures.map((failure, index) => (
                  <div key={index} className="space-y-2">
                    <div className="flex items-center justify-between">
                      <span className="text-sm">{failure.type}</span>
                      <span className={`text-sm font-medium ${
                        failure.severity === 'high' 
                          ? 'text-healthcare-critical' 
                          : 'text-healthcare-warning'
                      }`}>
                        {failure.count}
                      </span>
                    </div>
                    <div className="flex items-center justify-between text-xs text-healthcare-text-secondary">
                      <span>Impact: {failure.impact}</span>
                      <span className="capitalize">{failure.severity} severity</span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

export default DischargeProcessFailures;
