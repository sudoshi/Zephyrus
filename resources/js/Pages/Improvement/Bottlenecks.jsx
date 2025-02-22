import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';
import { 
  AlertCircle, Clock, TrendingUp, TrendingDown,
  AlertTriangle, Activity
} from 'lucide-react';

const Bottlenecks = () => {
  // Mock data for bottlenecks analysis
  // Bottleneck data from the last 24 hours
  const bottleneckData = [
    {
      rank: 1,
      type: 'Discharge Documentation Delays',
      location: 'Med-Surg 3W',
      avgDelay: '9.2 hrs',
      patientsAffected: 14,
      stressScore: 3,
      cascadingImpact: 'ICU Backlog (4 patients), ED Boarding (8 patients)',
      impactScore: 76.6,
      trend: '+12% vs last week',
      keyFactors: ['Medication reconciliation delays', 'Care coordination gaps', 'Limited pharmacy staffing']
    },
    {
      rank: 2,
      type: 'OR to PACU Handoff',
      location: 'Surgical Services',
      avgDelay: '42 mins',
      patientsAffected: 11,
      stressScore: 3,
      cascadingImpact: 'OR Schedule Delays, Extended PACU Hours',
      impactScore: 68.4,
      trend: '+8% vs last week',
      keyFactors: ['Complex post-op orders', 'Staff shift changes', 'Documentation system issues']
    },
    {
      rank: 3,
      type: 'ICU to Step-Down Transfer',
      location: 'ICU → 4E',
      avgDelay: '5.1 hrs',
      patientsAffected: 8,
      stressScore: 2,
      cascadingImpact: 'PACU Holding (3 patients), OR Delays (4 cases)',
      impactScore: 45.3,
      trend: '-5% vs last week',
      keyFactors: ['Telemetry bed availability', 'Staffing ratios', 'Care team rounding timing']
    },
    {
      rank: 4,
      type: 'ED to Inpatient Admission',
      location: 'ED → Med-Surg',
      avgDelay: '4.8 hrs',
      patientsAffected: 12,
      stressScore: 2,
      cascadingImpact: 'Increased ED LOS, Ambulance Diversion Risk',
      impactScore: 41.9,
      trend: '+15% vs last week',
      keyFactors: ['Bed assignment delays', 'Transport team availability', 'Specialty consult timing']
    },
    {
      rank: 5,
      type: 'Radiology TAT',
      location: 'CT/MRI',
      avgDelay: '2.3 hrs',
      patientsAffected: 16,
      stressScore: 2,
      cascadingImpact: 'ED/Inpatient Discharge Delays',
      impactScore: 38.7,
      trend: '-2% vs last week',
      keyFactors: ['Equipment downtime', 'After-hours staffing', 'Order prioritization']
    }
  ];

  // Resource utilization data
  const resourceData = [
    {
      resource: 'EVS (Bed Turnover)',
      peakTime: '10 AM – 2 PM',
      utilization: 93,
      target: 75,
      insight: 'Shift non-urgent bed cleaning to off-peak hours',
      staffingGap: '-4 FTEs',
      responseTime: '42 mins avg',
      completionRate: '82%',
      criticalAreas: ['ED', 'ICU', 'Med-Surg 3W'],
      recommendations: [
        'Implement zone-based cleaning teams',
        'Stagger shift starts to cover peaks',
        'Add evening shift coverage'
      ]
    },
    {
      resource: 'Transport Teams',
      peakTime: '11 AM – 3 PM',
      utilization: 87,
      target: 80,
      insight: 'Optimize discharge transport scheduling',
      staffingGap: '-2 FTEs',
      responseTime: '28 mins avg',
      completionRate: '88%',
      criticalAreas: ['Radiology', 'PACU', 'ED'],
      recommendations: [
        'Implement predictive transport needs model',
        'Cross-train support staff for transport',
        'Optimize transport routes'
      ]
    },
    {
      resource: 'Nursing (Discharges)',
      peakTime: '2 PM – 6 PM',
      utilization: 91,
      target: 85,
      insight: 'High discharge documentation workload',
      staffingGap: '-6 FTEs',
      responseTime: '3.2 hrs avg',
      completionRate: '76%',
      criticalAreas: ['Med-Surg', 'Telemetry', 'Observation'],
      recommendations: [
        'Implement discharge nurse role',
        'Streamline documentation requirements',
        'Earlier discharge planning initiation'
      ]
    },
    {
      resource: 'Pharmacy (Med Reconciliation)',
      peakTime: '1 PM – 5 PM',
      utilization: 95,
      target: 80,
      insight: 'Critical discharge medication delays',
      staffingGap: '-3 FTEs',
      responseTime: '2.8 hrs avg',
      completionRate: '72%',
      criticalAreas: ['Med-Surg', 'ED', 'Specialty Clinics'],
      recommendations: [
        'Add evening pharmacist coverage',
        'Implement med history technicians',
        'Enhance EMR medication workflows'
      ]
    },
    {
      resource: 'Care Management',
      peakTime: '9 AM – 1 PM',
      utilization: 88,
      target: 75,
      insight: 'Complex discharge planning delays',
      staffingGap: '-2 FTEs',
      responseTime: '24 hrs avg',
      completionRate: '84%',
      criticalAreas: ['ICU', 'Med-Surg', 'Rehab'],
      recommendations: [
        'Earlier post-acute care planning',
        'Enhance community partner network',
        'Implement discharge planning rounds'
      ]
    }
  ];

  // Calculate stats from bottleneck data
  const totalPatients = bottleneckData.reduce((sum, item) => sum + item.patientsAffected, 0);
  const avgImpactScore = bottleneckData.reduce((sum, item) => sum + item.impactScore, 0) / bottleneckData.length;
  const criticalBottlenecks = bottleneckData.filter(item => item.stressScore >= 3).length;

  const bottleneckStats = [
    {
      title: 'Critical Bottlenecks',
      value: criticalBottlenecks,
      change: `${((criticalBottlenecks / bottleneckData.length) * 100).toFixed(0)}%`,
      changeType: criticalBottlenecks > 2 ? 'negative' : 'positive',
      icon: AlertCircle,
      tooltip: 'Bottlenecks with stress score ≥ 3'
    },
    {
      title: 'Patients Affected',
      value: totalPatients,
      change: `${bottleneckData.filter(item => item.trend.includes('+')).length} increasing`,
      changeType: 'negative',
      icon: Activity,
      tooltip: 'Total number of patients impacted by current bottlenecks'
    },
    {
      title: 'Avg Impact Score',
      value: avgImpactScore.toFixed(1),
      change: 'High',
      changeType: avgImpactScore > 50 ? 'negative' : 'positive',
      icon: AlertTriangle,
      tooltip: 'Average impact score across all bottlenecks'
    }
  ];

  return (
    <DashboardLayout>
      <Head title="Process Bottlenecks - ZephyrusOR" />
      <PageContentLayout
        title="Process Bottlenecks Analysis"
        subtitle="Monitor and analyze system bottlenecks affecting patient flow and care delivery"
      >
        {/* Summary Stats */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
          {bottleneckStats.map((stat, index) => (
            <div 
              key={index} 
              className="bg-white dark:bg-gray-800 rounded-lg shadow p-4 relative group"
              title={stat.tooltip}
            >
              <div className="flex items-center justify-between">
                <div>
                  <div className="flex items-center gap-2">
                    <p className="text-sm text-gray-500 dark:text-gray-400">{stat.title}</p>
                    <div className="relative">
                      <div className="hidden group-hover:block absolute z-10 px-3 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg shadow-sm dark:bg-gray-700 -top-12 -left-1/2 whitespace-nowrap">
                        {stat.tooltip}
                      </div>
                    </div>
                  </div>
                  <h3 className="text-2xl font-bold mt-1 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {stat.value}
                  </h3>
                  <div className="flex items-center mt-2">
                    {stat.changeType === 'positive' ? (
                      <div className="flex items-center px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-900">
                        <TrendingDown className="h-3.5 w-3.5 text-green-600 dark:text-green-400 mr-1" />
                        <span className="text-sm font-medium text-green-600 dark:text-green-400">{stat.change}</span>
                      </div>
                    ) : (
                      <div className="flex items-center px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-900">
                        <TrendingUp className="h-3.5 w-3.5 text-red-600 dark:text-red-400 mr-1" />
                        <span className="text-sm font-medium text-red-600 dark:text-red-400">{stat.change}</span>
                      </div>
                    )}
                  </div>
                </div>
                <div className={`rounded-full p-2 ${stat.changeType === 'positive' ? 'bg-green-100 dark:bg-green-900' : 'bg-red-100 dark:bg-red-900'}`}>
                  <stat.icon className={`h-8 w-8 ${stat.changeType === 'positive' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`} />
                </div>
              </div>
            </div>
          ))}
        </div>

        {/* Main Content Grid */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
          {/* Bottlenecks Panel */}
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <h2 className="text-lg font-semibold mb-4">Top Bottlenecks (24h)</h2>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                  <tr>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Rank</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Type</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Location</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Impact</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Trend</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                  {bottleneckData.map((item, index) => (
                    <tr key={index} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                      <td className="px-3 py-2 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                        #{item.rank}
                      </td>
                      <td className="px-3 py-2">
                        <div className="text-sm text-gray-900 dark:text-gray-100 font-medium">{item.type}</div>
                        <div className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                          {item.keyFactors.map((factor, idx) => (
                            <span key={idx} className="inline-flex items-center mr-2">
                              <span className="w-1 h-1 bg-gray-400 rounded-full mr-1"></span>
                              {factor}
                            </span>
                          ))}
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <div className="text-sm text-gray-900 dark:text-gray-100">{item.location}</div>
                        <div className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                          {item.patientsAffected} patients affected
                        </div>
                        <div className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                          {item.cascadingImpact}
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <div className="text-sm text-gray-900 dark:text-gray-100">
                          Score: {item.impactScore.toFixed(1)}
                        </div>
                        <div className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                          Avg Delay: {item.avgDelay}
                        </div>
                        <div className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                          Stress Level: {Array(item.stressScore).fill('●').join('')}
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <div className={`text-sm font-medium ${item.trend.includes('+') ? 'text-red-500' : 'text-green-500'}`}>
                          {item.trend}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Resource Utilization Panel */}
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <h2 className="text-lg font-semibold mb-4">Resource Utilization & Stress</h2>
            <div className="overflow-x-auto">
              <table className="min-w-full">
                <thead>
                  <tr>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Resource</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Peak Time</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Utilization</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Insights</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                  {resourceData.map((item, index) => (
                    <tr key={index} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                      <td className="px-3 py-2">
                        <div className="font-medium text-sm text-gray-900 dark:text-gray-100">{item.resource}</div>
                        <div className="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mt-1">
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700">
                            {item.responseTime}
                          </span>
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700">
                            {item.completionRate}
                          </span>
                        </div>
                        <div className="text-xs text-red-500 mt-1 font-medium">
                          {item.staffingGap}
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <div className="text-sm text-gray-900 dark:text-gray-100">{item.peakTime}</div>
                        <div className="flex flex-wrap gap-1 mt-1">
                          {item.criticalAreas.map((area, idx) => (
                            <span 
                              key={idx}
                              className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200"
                            >
                              {area}
                            </span>
                          ))}
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <div className="flex items-center mb-2">
                          <div className="w-24 bg-gray-200 dark:bg-gray-600 rounded-full h-2.5 mr-2 overflow-hidden">
                            <div 
                              className={`h-2.5 rounded-full transition-all duration-300 ${item.utilization > item.target ? 'bg-red-500' : 'bg-green-500'}`}
                              style={{ width: `${item.utilization}%` }}
                            />
                          </div>
                          <span className="text-sm font-medium">{item.utilization}%</span>
                        </div>
                        <div className="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1">
                          <span>Target:</span>
                          <span className="font-medium">{item.target}%</span>
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <div className="text-sm font-medium text-gray-900 dark:text-gray-100">{item.insight}</div>
                        <ul className="mt-2 space-y-1.5">
                          {item.recommendations.map((rec, idx) => (
                            <li key={idx} className="text-xs text-gray-600 dark:text-gray-300 flex items-start">
                              <span className="w-1 h-1 bg-healthcare-primary dark:bg-healthcare-primary-dark rounded-full mr-2 mt-1.5"></span>
                              <span className="flex-1">{rec}</span>
                            </li>
                          ))}
                        </ul>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
};

export default Bottlenecks;
