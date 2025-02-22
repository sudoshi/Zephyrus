import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { 
  Plus, ArrowRight, CheckCircle, Clock, AlertCircle,
  RefreshCcw, Target, Activity, TrendingUp, TrendingDown
} from 'lucide-react';

const Active = ({ cycles = [] }) => {
  // Helper function for status icons
  const getStatusIcon = (status) => {
    switch (status) {
      case 'completed':
        return <CheckCircle className="h-5 w-5 text-green-500" />;
      case 'in-progress':
        return <Clock className="h-5 w-5 text-blue-500" />;
      case 'at-risk':
        return <AlertCircle className="h-5 w-5 text-red-500" />;
      default:
        return null;
    }
  };

  // Mock PDSA Cycles data
  const mockCycles = [
    {
      id: 1,
      title: 'Fall Prevention & Patient Safety',
      status: 'in-progress',
      domain: 'Quality & Safety',
      description: 'Implementing standardized fall risk assessments and interventions to reduce patient falls.',
      currentPhase: 'Study',
      lastUpdated: '2 hours ago',
      owner: 'Sarah Chen, RN',
      metrics: {
        baseline: '4.2 falls/1000 patient days',
        current: '2.8 falls/1000 patient days',
        target: '2.0 falls/1000 patient days'
      },
      progress: 75
    },
    {
      id: 2,
      title: 'Bedside Shift Report Enhancement',
      status: 'in-progress',
      domain: 'Patient Satisfaction',
      description: 'Improving continuity of care through standardized bedside shift reporting.',
      currentPhase: 'Do',
      lastUpdated: '4 hours ago',
      owner: 'Michael Torres, RN',
      metrics: {
        baseline: '82% HCAHPS',
        current: '88% HCAHPS',
        target: '90% HCAHPS'
      },
      progress: 60
    },
    {
      id: 3,
      title: 'Hourly Rounding Implementation',
      status: 'in-progress',
      domain: 'Quality & Safety',
      description: 'Enhancing patient satisfaction and reducing falls through structured hourly rounding.',
      currentPhase: 'Plan',
      lastUpdated: '1 day ago',
      owner: 'Jessica Wong, CNS',
      metrics: {
        baseline: '45 calls/shift',
        current: 'Pending',
        target: '25 calls/shift'
      },
      progress: 30
    },
    {
      id: 4,
      title: 'Medication Reconciliation Improvement',
      status: 'in-progress',
      domain: 'Quality & HEDIS',
      description: 'Reducing medication discrepancies through pharmacist-driven reconciliation.',
      currentPhase: 'Do',
      lastUpdated: '6 hours ago',
      owner: 'David Park, PharmD',
      metrics: {
        baseline: '12% discrepancy rate',
        current: '8% discrepancy rate',
        target: '5% discrepancy rate'
      },
      progress: 55
    },
    {
      id: 5,
      title: 'Discharge Planning Optimization',
      status: 'in-progress',
      domain: 'Patient Flow & Quality',
      description: 'Implementing early discharge planning to reduce readmissions.',
      currentPhase: 'Study',
      lastUpdated: '1 day ago',
      owner: 'Rachel Adams, SW',
      metrics: {
        baseline: '18% readmission rate',
        current: '15% readmission rate',
        target: '12% readmission rate'
      },
      progress: 65
    }
  ];

  // PDSA Summary Cards Data
  const pdsaSummaryCards = [
    {
      title: 'Total PDSA Cycles',
      value: '14',
      change: '+3',
      changeType: 'positive',
      icon: RefreshCcw,
    },
    {
      title: 'Completed Cycles',
      value: '6',
      change: '+2',
      changeType: 'positive',
      icon: Target,
    },
    {
      title: 'Active Cycles',
      value: '8',
      change: '+1',
      changeType: 'positive',
      icon: Activity,
    }
  ];

  return (
    <DashboardLayout>
      <Head title="PDSA Cycles & Opportunities - ZephyrusOR" />
      <PageContentLayout
        title="PDSA Cycles & Opportunities"
        subtitle="Track active initiatives and explore improvement opportunities from reported barriers"
      >
        {/* PDSA Summary Cards */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
          {pdsaSummaryCards.map((card, index) => (
            <div key={index} className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-gray-500 dark:text-gray-400">{card.title}</p>
                  <h3 className="text-2xl font-bold mt-1">{card.value}</h3>
                  <div className="flex items-center mt-1">
                    {card.changeType === 'positive' ? (
                      <TrendingUp className="h-4 w-4 text-green-500 mr-1" />
                    ) : (
                      <TrendingDown className="h-4 w-4 text-red-500 mr-1" />
                    )}
                    <span className={`text-sm ${
                      card.changeType === 'positive' ? 'text-green-500' : 'text-red-500'
                    }`}>
                      {card.change}
                    </span>
                  </div>
                </div>
                <card.icon className="h-8 w-8 text-healthcare-primary dark:text-healthcare-primary-dark" />
              </div>
            </div>
          ))}
        </div>

        {/* Reported Barriers & Opportunities Section */}
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4 mb-6">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold">Reported Barriers & Opportunities</h2>
            <div className="flex gap-2">
              <select 
                className="bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md px-3 py-1 text-sm"
                defaultValue="all"
              >
                <option value="all">All Sources</option>
                <option value="discharge">Discharge Barriers</option>
                <option value="flow">Patient Flow</option>
                <option value="safety">Safety Events</option>
                <option value="staff">Staff Reports</option>
              </select>
              <select
                className="bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md px-3 py-1 text-sm"
                defaultValue="recent"
              >
                <option value="recent">Most Recent</option>
                <option value="impact">Highest Impact</option>
                <option value="frequency">Most Frequent</option>
              </select>
            </div>
          </div>
          
          {/* Sample Opportunities */}
          <div className="grid grid-cols-1 gap-4 mb-4">
            <div className="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
              <div className="flex items-start justify-between">
                <div>
                  <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-800 dark:text-red-100 mb-2">
                    High Impact
                  </span>
                  <h3 className="text-base font-medium mb-1">Delayed Discharge Due to Transportation</h3>
                  <p className="text-sm text-gray-600 dark:text-gray-400 mb-2">
                    Recurring issue with coordinating patient transportation leading to discharge delays
                  </p>
                  <div className="flex items-center gap-4 text-sm text-gray-500 dark:text-gray-400">
                    <span>Source: Discharge Barriers</span>
                    <span>Frequency: 24 reports this month</span>
                    <span>Avg Delay: 2.5 hours</span>
                  </div>
                </div>
                <button className="text-healthcare-primary dark:text-healthcare-primary-dark hover:text-healthcare-primary/80 text-sm">
                  Start PDSA Cycle
                </button>
              </div>
            </div>
            
            <div className="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
              <div className="flex items-start justify-between">
                <div>
                  <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-800 dark:text-yellow-100 mb-2">
                    Medium Impact
                  </span>
                  <h3 className="text-base font-medium mb-1">Medication Reconciliation Process Delays</h3>
                  <p className="text-sm text-gray-600 dark:text-gray-400 mb-2">
                    Staff reporting consistent delays in medication reconciliation during shift changes
                  </p>
                  <div className="flex items-center gap-4 text-sm text-gray-500 dark:text-gray-400">
                    <span>Source: Staff Reports</span>
                    <span>Frequency: 15 reports this month</span>
                    <span>Avg Delay: 45 minutes</span>
                  </div>
                </div>
                <button className="text-healthcare-primary dark:text-healthcare-primary-dark hover:text-healthcare-primary/80 text-sm">
                  Start PDSA Cycle
                </button>
              </div>
            </div>
          </div>
        </div>

        {/* Active PDSA Cycles */}
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <h2 className="text-lg font-semibold mb-4">Active PDSA Cycles</h2>
          <div className="grid grid-cols-1 gap-4">
            {mockCycles.filter(cycle => cycle.status === 'in-progress').map((cycle, index) => (
              <div
                key={index}
                className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow-sm transition-colors duration-300"
              >
                <div className="p-6">
                  <div className="flex items-start justify-between mb-4">
                    <div className="flex-1">
                      <div className="flex items-center gap-3 mb-2">
                        {getStatusIcon(cycle.status)}
                        <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                          {cycle.title}
                        </h3>
                      </div>
                      <div className="text-sm text-gray-500 dark:text-gray-400 mb-2">
                        Domain: {cycle.domain}
                      </div>
                      <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark text-sm">
                        {cycle.description}
                      </p>
                      
                      {/* Metrics Section */}
                      <div className="mt-4 grid grid-cols-3 gap-4">
                        <div className="bg-gray-50 dark:bg-gray-800 p-2 rounded">
                          <div className="text-xs text-gray-500 dark:text-gray-400">Baseline</div>
                          <div className="text-sm font-medium">{cycle.metrics.baseline}</div>
                        </div>
                        <div className="bg-gray-50 dark:bg-gray-800 p-2 rounded">
                          <div className="text-xs text-gray-500 dark:text-gray-400">Current</div>
                          <div className="text-sm font-medium">{cycle.metrics.current}</div>
                        </div>
                        <div className="bg-gray-50 dark:bg-gray-800 p-2 rounded">
                          <div className="text-xs text-gray-500 dark:text-gray-400">Target</div>
                          <div className="text-sm font-medium">{cycle.metrics.target}</div>
                        </div>
                      </div>
                    </div>
                    
                    <Link
                      href={`/improvement/active/${cycle.id}`}
                      className="text-healthcare-primary dark:text-healthcare-primary-dark hover:text-healthcare-primary/80 dark:hover:text-healthcare-primary-dark/80 flex items-center gap-1 text-sm ml-4"
                    >
                      View Details
                      <ArrowRight className="h-4 w-4" />
                    </Link>
                  </div>
                  
                  <div className="border-t border-healthcare-border dark:border-healthcare-border-dark mt-4 pt-4">
                    <div className="flex items-center justify-between text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      <span>Last Updated: {cycle.lastUpdated}</span>
                      <span>Phase: {cycle.currentPhase}</span>
                      <span>Owner: {cycle.owner}</span>
                    </div>
                    {/* Progress Bar */}
                    <div className="mt-3">
                      <div className="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400 mb-1">
                        <span>Progress</span>
                        <span>{cycle.progress}%</span>
                      </div>
                      <div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                        <div
                          className="bg-healthcare-primary dark:bg-healthcare-primary-dark h-2 rounded-full transition-all duration-300"
                          style={{ width: `${cycle.progress}%` }}
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
};

export default Active;
