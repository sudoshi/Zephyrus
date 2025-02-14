import React from 'react';
import { LineChart, Line, XAxis, YAxis, Tooltip, ResponsiveContainer, Legend, CartesianGrid, LabelList } from 'recharts';
import CustomTooltip from './CustomTooltip';
import { Activity, AlertCircle } from 'lucide-react';

const ChronicCarePanel = ({ data }) => {
  // Time windows for monitoring
  const timeWindows = [7, 15, 30, 45];
  const [selectedTimeframe, setSelectedTimeframe] = React.useState(7);
  const [sortCriteria, setSortCriteria] = React.useState('risk');
  const [sortDirection, setSortDirection] = React.useState('desc');

  const sortOptions = [
    { value: 'risk', label: 'Risk Score' },
    { value: 'readmissionRisk', label: 'Readmission Risk' },
    { value: 'medicationAdherence', label: 'Medication Adherence' },
    { value: 'vitalStability', label: 'Vital Stability' },
    { value: 'followupCompliance', label: 'Follow-up Compliance' },
    { value: 'name', label: 'Condition Name' }
  ];

  // Sort conditions based on selected criteria
  const getSortedConditions = () => {
    const conditions = Object.entries(conditionData)
      .map(([key, condition]) => ({
        key,
        ...condition,
        currentRisk: condition.timeBasedRisk.find(r => r.days === selectedTimeframe)?.risk || 0
      }));

    return conditions.sort((a, b) => {
      let comparison = 0;
      const multiplier = sortDirection === 'desc' ? -1 : 1;

      switch (sortCriteria) {
        case 'risk':
          comparison = a.currentRisk - b.currentRisk;
          break;
        case 'readmissionRisk':
          comparison = a.metrics.readmissionRisk.value - b.metrics.readmissionRisk.value;
          break;
        case 'medicationAdherence':
          comparison = a.metrics.medicationAdherence.value - b.metrics.medicationAdherence.value;
          break;
        case 'vitalStability':
          comparison = a.metrics.vitalStability.value - b.metrics.vitalStability.value;
          break;
        case 'followupCompliance':
          comparison = a.metrics.followupCompliance.value - b.metrics.followupCompliance.value;
          break;
        case 'name':
          comparison = a.name.localeCompare(b.name);
          break;
        default:
          comparison = a.currentRisk - b.currentRisk;
      }

      return comparison * multiplier;
    });
  };

  const toggleSortDirection = () => {
    setSortDirection(prev => prev === 'desc' ? 'asc' : 'desc');
  };
  
  // Synthetic data for chronic conditions
  const conditionData = {
    CHF: {
      name: 'Congestive Heart Failure',
      metrics: {
        readmissionRisk: { value: 18, trend: '+2', status: 'warning' },
        medicationAdherence: { value: 92, trend: '-1', status: 'success' },
        vitalStability: { value: 85, trend: '-3', status: 'warning' },
        followupCompliance: { value: 88, trend: '+4', status: 'success' }
      },
      timeBasedRisk: [
        { days: 7, risk: 12 },
        { days: 15, risk: 15 },
        { days: 30, risk: 18 },
        { days: 45, risk: 22 }
      ]
    },
    AMI: {
      name: 'Acute Myocardial Infarction',
      metrics: {
        readmissionRisk: { value: 15, trend: '-1', status: 'success' },
        medicationAdherence: { value: 95, trend: '+2', status: 'success' },
        vitalStability: { value: 90, trend: '+1', status: 'success' },
        followupCompliance: { value: 94, trend: '+3', status: 'success' }
      },
      timeBasedRisk: [
        { days: 7, risk: 10 },
        { days: 15, risk: 12 },
        { days: 30, risk: 15 },
        { days: 45, risk: 18 }
      ]
    },
    Stroke: {
      name: 'Stroke',
      metrics: {
        readmissionRisk: { value: 20, trend: '+3', status: 'warning' },
        medicationAdherence: { value: 88, trend: '-2', status: 'warning' },
        vitalStability: { value: 82, trend: '-4', status: 'warning' },
        followupCompliance: { value: 85, trend: '+1', status: 'warning' }
      },
      timeBasedRisk: [
        { days: 7, risk: 15 },
        { days: 15, risk: 18 },
        { days: 30, risk: 20 },
        { days: 45, risk: 25 }
      ]
    },
    CKD: {
      name: 'Chronic Kidney Disease',
      metrics: {
        readmissionRisk: { value: 22, trend: '+4', status: 'critical' },
        medicationAdherence: { value: 87, trend: '-3', status: 'warning' },
        vitalStability: { value: 80, trend: '-5', status: 'critical' },
        followupCompliance: { value: 82, trend: '-2', status: 'warning' }
      },
      timeBasedRisk: [
        { days: 7, risk: 18 },
        { days: 15, risk: 20 },
        { days: 30, risk: 22 },
        { days: 45, risk: 28 }
      ]
    },
    Diabetes: {
      name: 'Diabetes',
      metrics: {
        readmissionRisk: { value: 16, trend: '+1', status: 'success' },
        medicationAdherence: { value: 91, trend: '+2', status: 'success' },
        vitalStability: { value: 88, trend: '+1', status: 'success' },
        followupCompliance: { value: 90, trend: '+3', status: 'success' }
      },
      timeBasedRisk: [
        { days: 7, risk: 12 },
        { days: 15, risk: 14 },
        { days: 30, risk: 16 },
        { days: 45, risk: 20 }
      ]
    },
    COPD: {
      name: 'COPD',
      metrics: {
        readmissionRisk: { value: 25, trend: '+5', status: 'critical' },
        medicationAdherence: { value: 84, trend: '-4', status: 'critical' },
        vitalStability: { value: 78, trend: '-6', status: 'critical' },
        followupCompliance: { value: 80, trend: '-3', status: 'critical' }
      },
      timeBasedRisk: [
        { days: 7, risk: 20 },
        { days: 15, risk: 22 },
        { days: 30, risk: 25 },
        { days: 45, risk: 30 }
      ]
    }
  };

  // Transform data for the line chart
  const chartData = timeWindows.map(days => ({
    days,
    CHF: conditionData.CHF.timeBasedRisk.find(r => r.days === days)?.risk,
    AMI: conditionData.AMI.timeBasedRisk.find(r => r.days === days)?.risk,
    Stroke: conditionData.Stroke.timeBasedRisk.find(r => r.days === days)?.risk,
    CKD: conditionData.CKD.timeBasedRisk.find(r => r.days === days)?.risk,
    Diabetes: conditionData.Diabetes.timeBasedRisk.find(r => r.days === days)?.risk,
    COPD: conditionData.COPD.timeBasedRisk.find(r => r.days === days)?.risk,
  }));

  const getStatusColor = (status) => {
    switch (status) {
      case 'critical':
        return 'text-healthcare-critical dark:text-healthcare-critical-dark';
      case 'warning':
        return 'text-healthcare-warning dark:text-healthcare-warning-dark';
      case 'success':
        return 'text-healthcare-success dark:text-healthcare-success-dark';
      default:
        return 'text-healthcare-primary dark:text-healthcare-primary-dark';
    }
  };

  const getTrendIndicator = (trend) => {
    const value = parseFloat(trend);
    if (value > 0) return '↑';
    if (value < 0) return '↓';
    return '→';
  };

  return (
    <div className="space-y-6">
      {/* Control Panel */}
      <div className="flex flex-col sm:flex-row justify-between items-center gap-4 mb-6">
        {/* Timeframe Selector */}
        <div className="inline-flex rounded-md shadow-sm">
          {timeWindows.map((days) => (
            <button
              key={days}
              type="button"
              onClick={() => setSelectedTimeframe(days)}
              className={`
                relative inline-flex items-center px-4 py-2 text-sm font-medium
                ${days === selectedTimeframe
                  ? 'bg-healthcare-primary text-white'
                  : 'bg-healthcare-surface text-healthcare-text-primary hover:bg-healthcare-hover'
                }
                ${days === timeWindows[0] ? 'rounded-l-md' : ''}
                ${days === timeWindows[timeWindows.length - 1] ? 'rounded-r-md' : ''}
                border border-healthcare-border
                focus:z-10 focus:outline-none focus:ring-2 focus:ring-healthcare-primary
              `}
            >
              {days} Days
            </button>
          ))}
        </div>

        {/* Sort Controls */}
        <div className="flex items-center gap-2">
          <select
            value={sortCriteria}
            onChange={(e) => setSortCriteria(e.target.value)}
            className="rounded-md border border-healthcare-border bg-healthcare-surface text-healthcare-text-primary px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-healthcare-primary"
          >
            {sortOptions.map(option => (
              <option key={option.value} value={option.value}>
                Sort by {option.label}
              </option>
            ))}
          </select>

          <button
            onClick={toggleSortDirection}
            className="p-2 rounded-md border border-healthcare-border hover:bg-healthcare-hover focus:outline-none focus:ring-2 focus:ring-healthcare-primary"
            aria-label={`Sort ${sortDirection === 'desc' ? 'descending' : 'ascending'}`}
          >
            {sortDirection === 'desc' ? '↓' : '↑'}
          </button>
        </div>
      </div>

      {/* Risk Trend Chart */}
      <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg">
        <h3 className="text-lg font-semibold mb-4">Readmission Risk Trends</h3>
        <div className="h-[300px]">
          <ResponsiveContainer width="100%" height="100%">
              <LineChart data={chartData}>
                <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.1)" />
                <XAxis 
                  dataKey="days" 
                  label={{ value: 'Days Post-Discharge', position: 'bottom' }}
                  stroke="currentColor"
                  tickLine={false}
                />
                <YAxis 
                  label={{ value: 'Risk Score (%)', angle: -90, position: 'left' }}
                  stroke="currentColor"
                  tickLine={false}
                />
                <Tooltip content={<CustomTooltip />} />
                <Legend
                  layout="horizontal"
                  verticalAlign="top"
                  align="center"
                  wrapperStyle={{
                    paddingBottom: '20px'
                  }}
                  formatter={(value) => {
                    const condition = conditionData[value];
                    const currentRisk = condition.timeBasedRisk.find(r => r.days === selectedTimeframe)?.risk;
                    return (
                      <span className="flex items-center gap-2">
                        <span className="font-medium">{condition.name}</span>
                        <span className={`text-sm ${
                          currentRisk >= 25 ? 'text-healthcare-critical' :
                          currentRisk >= 20 ? 'text-healthcare-warning' :
                          'text-healthcare-success'
                        }`}>
                          ({currentRisk}%)
                        </span>
                      </span>
                    );
                  }}
                />
                {Object.entries(conditionData)
                  .sort((a, b) => {
                    const aRisk = a[1].timeBasedRisk.find(r => r.days === selectedTimeframe)?.risk || 0;
                    const bRisk = b[1].timeBasedRisk.find(r => r.days === selectedTimeframe)?.risk || 0;
                    return bRisk - aRisk;
                  })
                  .map(([key, condition], index) => {
                    const baseColor = `hsl(${index * 60}, 70%, 50%)`;
                    return (
                      <Line
                        key={key}
                        type="monotone"
                        dataKey={key}
                        name={key}
                        stroke={baseColor}
                        strokeWidth={3}
                        dot={{ r: 4, fill: baseColor }}
                        activeDot={{ r: 6, fill: baseColor }}
                      >
                        <LabelList
                          dataKey={key}
                          position="top"
                          content={({ value, x, y }) => {
                            if (value >= 25) {
                              return (
                                <g>
                                  <circle cx={x} cy={y-10} r={4} fill="var(--healthcare-critical)" />
                                </g>
                              );
                            }
                            return null;
                          }}
                        />
                      </Line>
                    );
                  })
                }
              </LineChart>
          </ResponsiveContainer>
        </div>
      </div>

      {/* Condition Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {getSortedConditions().map(({ key, ...condition }) => (
          <div 
            key={key}
            className="bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg"
          >
            <div className="flex items-center justify-between mb-4">
              <h4 className="font-semibold">{condition.name}</h4>
              <Activity className="h-5 w-5 text-healthcare-primary dark:text-healthcare-primary-dark" />
            </div>

            <div className="grid grid-cols-2 gap-4">
              {Object.entries(condition.metrics).map(([metricKey, metric]) => (
                <div key={metricKey} className="space-y-1">
                  <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                    {metricKey.replace(/([A-Z])/g, ' $1').trim()}
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-lg font-semibold">{metric.value}%</span>
                    <span className={`text-sm ${getStatusColor(metric.status)}`}>
                      {getTrendIndicator(metric.trend)} {Math.abs(parseFloat(metric.trend))}%
                    </span>
                  </div>
                </div>
              ))}
            </div>

            {/* Risk Indicators */}
            <div className="mt-4">
              {/* Current Risk Level */}
              <div className="flex items-center justify-between text-sm mb-2">
                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {selectedTimeframe} Day Risk:
                </span>
                <span className={`font-semibold ${
                  condition.currentRisk >= 25 ? 'text-healthcare-critical' :
                  condition.currentRisk >= 20 ? 'text-healthcare-warning' :
                  'text-healthcare-success'
                }`}>
                  {condition.currentRisk}%
                </span>
              </div>

              {/* High Risk Warning */}
              {(condition.currentRisk >= 25 || Object.values(condition.metrics).some(m => m.status === 'critical')) && (
                <div className="flex items-center gap-2 text-healthcare-critical dark:text-healthcare-critical-dark">
                  <AlertCircle className="h-4 w-4" />
                  <span className="text-sm">High Risk - Immediate Action Required</span>
                </div>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

export default ChronicCarePanel;
