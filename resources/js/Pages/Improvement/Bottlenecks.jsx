import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';
import { 
  AlertCircle, Clock, TrendingUp, TrendingDown,
  AlertTriangle, Activity
} from 'lucide-react';

const Bottlenecks = ({ bottlenecks = null }) => {
  // Fallback mock data — used only when the server prop is absent/empty so the
  // page never renders blank. Real data flows in via the `bottlenecks` prop
  // (DashboardService::getBottleneckStats), computed from live operational
  // signals (long-LOS vs GMLOS, blocked beds, at-risk transports, OR turnover).
  const MOCK_BOTTLENECK_DATA = [
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

  // Resource utilization data (fallback mock — see note above)
  const MOCK_RESOURCE_DATA = [
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

  // Prefer server-computed data (from real operational signals); fall back to
  // the mock only when the prop is absent or empty so the page never renders blank.
  const bottleneckData =
    bottlenecks?.bottleneckData?.length ? bottlenecks.bottleneckData : MOCK_BOTTLENECK_DATA;
  const resourceData =
    bottlenecks?.resourceData?.length ? bottlenecks.resourceData : MOCK_RESOURCE_DATA;

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
              className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow p-4 relative group"
              title={stat.tooltip}
            >
              <div className="flex items-center justify-between">
                <div>
                  <div className="flex items-center gap-2">
                    <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{stat.title}</p>
                    <div className="relative">
                      <div className="hidden group-hover:block absolute z-10 px-3 py-2 text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-sm -top-12 -left-1/2 whitespace-nowrap">
                        {stat.tooltip}
                      </div>
                    </div>
                  </div>
                  <h3 className="text-2xl font-semibold mt-1 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {stat.value}
                  </h3>
                  <div className="flex items-center mt-2">
                    {stat.changeType === 'positive' ? (
                      <div className="flex items-center px-2 py-0.5 rounded-full bg-healthcare-success/10 dark:bg-healthcare-success-dark/20">
                        <TrendingDown className="h-3.5 w-3.5 text-healthcare-success dark:text-healthcare-success-dark mr-1" />
                        <span className="text-sm font-medium text-healthcare-success dark:text-healthcare-success-dark">{stat.change}</span>
                      </div>
                    ) : (
                      <div className="flex items-center px-2 py-0.5 rounded-full bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/20">
                        <TrendingUp className="h-3.5 w-3.5 text-healthcare-critical dark:text-healthcare-critical-dark mr-1" />
                        <span className="text-sm font-medium text-healthcare-critical dark:text-healthcare-critical-dark">{stat.change}</span>
                      </div>
                    )}
                  </div>
                </div>
                <div className={`rounded-full p-2 ${stat.changeType === 'positive' ? 'bg-healthcare-success/10 dark:bg-healthcare-success-dark/20' : 'bg-healthcare-critical/10 dark:bg-healthcare-critical-dark/20'}`}>
                  <stat.icon className={`h-8 w-8 ${stat.changeType === 'positive' ? 'text-healthcare-success dark:text-healthcare-success-dark' : 'text-healthcare-critical dark:text-healthcare-critical-dark'}`} />
                </div>
              </div>
            </div>
          ))}
        </div>

        {/* Main Content Grid */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
          {/* Bottlenecks Panel */}
          <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow p-4">
            <h2 className="text-lg font-semibold mb-4">Top Bottlenecks (24h)</h2>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                <thead>
                  <tr>
                    <th className="px-3 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase">Rank</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase">Type</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase">Location</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase">Impact</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase">Trend</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                  {bottleneckData.map((item, index) => (
                    <tr key={index} className="hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark">
                      <td className="px-3 py-2 whitespace-nowrap text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        #{item.rank}
                      </td>
                      <td className="px-3 py-2">
                        <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark font-medium">{item.type}</div>
                        <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                          {item.keyFactors.map((factor, idx) => (
                            <span key={idx} className="inline-flex items-center mr-2">
                              <span className="w-1 h-1 bg-healthcare-border dark:bg-healthcare-border-dark rounded-full mr-1"></span>
                              {factor}
                            </span>
                          ))}
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{item.location}</div>
                        <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                          {item.patientsAffected} patients affected
                        </div>
                        <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                          {item.cascadingImpact}
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                          Score: {item.impactScore.toFixed(1)}
                        </div>
                        <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                          Avg Delay: {item.avgDelay}
                        </div>
                        <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                          Stress Level: {Array(item.stressScore).fill('●').join('')}
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <div className={`text-sm font-medium ${item.trend.includes('+') ? 'text-healthcare-critical dark:text-healthcare-critical-dark' : 'text-healthcare-success dark:text-healthcare-success-dark'}`}>
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
          <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow p-4">
            <h2 className="text-lg font-semibold mb-4">Resource Utilization & Stress</h2>
            <div className="overflow-x-auto">
              <table className="min-w-full">
                <thead>
                  <tr>
                    <th className="px-3 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase">Resource</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase">Peak Time</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase">Utilization</th>
                    <th className="px-3 py-2 text-left text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark uppercase">Insights</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-healthcare-border dark:divide-healthcare-border-dark">
                  {resourceData.map((item, index) => (
                    <tr key={index} className="hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark">
                      <td className="px-3 py-2">
                        <div className="font-medium text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{item.resource}</div>
                        <div className="flex items-center gap-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full bg-healthcare-background dark:bg-healthcare-background-dark">
                            {item.responseTime}
                          </span>
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full bg-healthcare-background dark:bg-healthcare-background-dark">
                            {item.completionRate}
                          </span>
                        </div>
                        <div className="text-xs text-healthcare-critical dark:text-healthcare-critical-dark mt-1 font-medium">
                          {item.staffingGap}
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{item.peakTime}</div>
                        <div className="flex flex-wrap gap-1 mt-1">
                          {item.criticalAreas.map((area, idx) => (
                            <span
                              key={idx}
                              className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-healthcare-background dark:bg-healthcare-background-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                            >
                              {area}
                            </span>
                          ))}
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <div className="flex items-center mb-2">
                          <div className="w-24 bg-healthcare-border dark:bg-healthcare-border-dark rounded-full h-2.5 mr-2 overflow-hidden">
                            <div
                              className={`h-2.5 rounded-full transition-all duration-300 ${item.utilization > item.target ? 'bg-healthcare-critical dark:bg-healthcare-critical-dark' : 'bg-healthcare-success dark:bg-healthcare-success-dark'}`}
                              style={{ width: `${item.utilization}%` }}
                            />
                          </div>
                          <span className="text-sm font-medium">{item.utilization}%</span>
                        </div>
                        <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark flex items-center gap-1">
                          <span>Target:</span>
                          <span className="font-medium">{item.target}%</span>
                        </div>
                      </td>
                      <td className="px-3 py-2">
                        <div className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{item.insight}</div>
                        <ul className="mt-2 space-y-1.5">
                          {item.recommendations.map((rec, idx) => (
                            <li key={idx} className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark flex items-start">
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
