import React, { useState } from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { Button } from '@/Components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/Components/ui/dialog';

const ProgressBar = ({ percentage, label, color = 'blue' }) => {
  const getColorClass = (color) => {
    const classes = {
      blue: 'bg-healthcare-primary dark:bg-healthcare-primary-dark',
      orange: 'bg-orange-500 dark:bg-orange-600',
      yellow: 'bg-yellow-500 dark:bg-yellow-600',
      green: 'bg-green-500 dark:bg-green-600'
    };
    return classes[color] || classes.blue;
  };

  return (
    <div className="mb-4 last:mb-0">
      <div className="flex justify-between mb-1">
        <span className="text-base font-medium text-gray-700 dark:text-gray-300">{label}</span>
        <span className="text-base font-medium text-gray-700 dark:text-gray-300">{percentage}%</span>
      </div>
      <div className="w-full h-4 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
        <div
          className={`h-full rounded-full ${getColorClass(color)} transition-all duration-500`}
          style={{ width: `${percentage}%` }}
        />
      </div>
    </div>
  );
};

const TrendIndicator = ({ weekTrend }) => {
  // weekTrend is already the percentage change
  return (
    <div className="flex items-center gap-2 text-sm">
      {weekTrend > 0 ? (
        <Icon icon="lucide:trending-up" className="w-4 h-4 text-healthcare-critical dark:text-healthcare-critical-dark" />
      ) : weekTrend < 0 ? (
        <Icon icon="lucide:trending-down" className="w-4 h-4 text-healthcare-success dark:text-healthcare-success-dark" />
      ) : (
        <Icon icon="lucide:minus" className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
      )}
      <span 
        className={weekTrend > 0 
          ? 'text-healthcare-critical dark:text-healthcare-critical-dark' 
          : weekTrend < 0 
          ? 'text-healthcare-success dark:text-healthcare-success-dark' 
          : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
        }
      >
        {weekTrend > 0 ? '+' : ''}{weekTrend}% vs last week
      </span>
    </div>
  );
};

const ImpactVisual = ({ causes }) => {
  // Sort causes by percentage in descending order
  const sortedCauses = [...causes].sort((a, b) => b.percentage - a.percentage);
  
  return (
    <div className="space-y-6">
      {sortedCauses.map((cause, index) => {
        let color = 'blue';
        if (index === 0) color = 'orange';
        else if (index === 1) color = 'yellow';
        
        return (
          <div key={index} className="bg-white dark:bg-gray-800 rounded-lg p-4 shadow-sm border border-gray-200 dark:border-gray-700">
            <ProgressBar
              percentage={cause.percentage}
              label={cause.summary}
              color={color}
            />
            <div className="mt-2 text-sm text-gray-600 dark:text-gray-400">
              {cause.detail.description}
            </div>
            {cause.detail.subCauses && (
              <div className="mt-3 pl-4 border-l-2 border-gray-200 dark:border-gray-700">
                <ul className="space-y-1">
                  {cause.detail.subCauses.map((subCause, subIndex) => (
                    <li
                      key={subIndex}
                      className="text-sm text-gray-600 dark:text-gray-400 flex items-center gap-2"
                    >
                      <Icon
                        icon="lucide:minus"
                        className="w-3 h-3 text-gray-400"
                      />
                      {subCause}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
};

const RootCauseCard = ({ title, causes, totalPercentage, trend }) => {
  const [isExpanded, setIsExpanded] = useState(false);

  return (
    <div className="bg-white dark:bg-gray-800 rounded-lg shadow-lg overflow-hidden">
      {/* Header */}
      <div className="p-4 border-b border-gray-200 dark:border-gray-700">
        <div className="flex items-start justify-between">
          <div>
            <div className="flex items-center gap-3">
              <div className="flex-shrink-0 w-12 h-12 bg-red-100 dark:bg-red-900 rounded-full flex items-center justify-center">
                <span className="text-lg font-bold text-red-600 dark:text-red-400">{totalPercentage}%</span>
              </div>
              <div>
                <h3 className="text-xl font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                  <Icon icon="lucide:alert-circle" className="w-5 h-5 text-red-500" />
                  {title}
                </h3>
                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                  Root Causes Identified
                </p>
              </div>
            </div>
          </div>
          <button
            onClick={() => setIsExpanded(!isExpanded)}
            className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
          >
            <Icon
              icon={isExpanded ? 'lucide:chevron-up' : 'lucide:chevron-down'}
              className="w-5 h-5"
            />
          </button>
        </div>
      </div>

      {/* High Level View */}
      <div className="p-4 bg-gray-50 dark:bg-gray-900">
        {causes.map((cause, index) => (
          <div key={index} className="mb-3 last:mb-0">
            <div className="flex items-baseline justify-between text-sm">
              <span className="text-gray-700 dark:text-gray-300">
                <span className="font-semibold">{cause.percentage}%:</span> {cause.summary}
              </span>
            </div>
          </div>
        ))}
      </div>

      {/* Impact Analysis */}
      <div className="p-4 border-t border-gray-200 dark:border-gray-700">
        <div className="flex items-center justify-between mb-4">
          <h4 className="text-lg font-medium">Impact Analysis</h4>
          <TrendIndicator
            currentValue={totalPercentage}
            previousValue={trend?.[trend.length - 2]?.value || totalPercentage}
          />
        </div>
        <ImpactVisual causes={causes} />
      </div>

      {/* Enhanced View */}
      {isExpanded && (
        <div className="p-4 border-t border-gray-200 dark:border-gray-700">
          <h4 className="text-lg font-medium mb-3">Enhanced View</h4>
          {causes.map((cause, index) => (
            <div key={`detail-${index}`} className="mb-4 last:mb-0">
              <div className="flex items-start gap-2">
                <Icon icon="lucide:dot" className="w-4 h-4 mt-1 text-blue-500" />
                <div>
                  <div className="font-medium text-gray-900 dark:text-white">
                    {cause.percentage}%: {cause.detail.title}
                  </div>
                  <div className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    {cause.detail.description}
                  </div>
                  {cause.detail.subCauses && (
                    <ul className="mt-2 space-y-1">
                      {cause.detail.subCauses.map((subCause, subIndex) => (
                        <li
                          key={subIndex}
                          className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400"
                        >
                          <Icon
                            icon="lucide:arrow-right"
                            className="w-3 h-3 text-gray-400"
                          />
                          {subCause}
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

const getRecommendationsForBottleneck = (bottleneck) => {
  const { type, avgDelay, impactedPatients, causes, metrics } = bottleneck;
  
  // Specific recommendations based on bottleneck type
  if (type.includes('Discharge')) {
    return {
      immediate: [
        `Schedule discharge planning rounds at 0800 to address ${impactedPatients} affected patients`,
        `Add evening pharmacist coverage during peak hours (${avgDelay} current delay)`,
        'Implement discharge medication verification parallel workflow'
      ],
      shortTerm: [
        'Create dedicated discharge nurse role with 1:8 ratio',
        'Establish automated discharge criteria notification system',
        'Develop real-time pharmacy workload dashboard'
      ],
      longTerm: [
        'Implement predictive discharge planning AI model',
        'Integrate automated medication reconciliation system',
        'Establish cross-trained discharge support team'
      ]
    };
  }
  
  if (type.includes('OR to PACU')) {
    return {
      immediate: [
        'Implement electronic PACU handoff checklist',
        `Stagger OR case start times to reduce ${avgDelay} handoff delay`,
        'Establish PACU charge nurse flow coordinator role'
      ],
      shortTerm: [
        'Create standardized post-op order sets by procedure type',
        'Implement real-time OR-to-PACU capacity dashboard',
        'Develop nurse-led PACU readiness protocol'
      ],
      longTerm: [
        'Deploy AI-powered OR schedule optimization',
        'Implement automated PACU bed assignment system',
        'Establish dedicated perioperative patient flow team'
      ]
    };
  }

  // Default recommendations for other bottlenecks
  return {
    immediate: [
      `Address ${type} with focused process improvement team`,
      `Implement quick-wins to reduce ${avgDelay} delay`,
      'Establish daily metrics tracking and review'
    ],
    shortTerm: [
      'Develop standard operating procedures',
      'Create staff training and competency program',
      'Implement performance monitoring dashboard'
    ],
    longTerm: [
      'Deploy automated workflow optimization',
      'Establish continuous improvement framework',
      'Implement predictive analytics for resource planning'
    ]
  };
};

const AIRecommendations = ({ rootCauses }) => {
  return (
    <div className="space-y-8 max-h-[70vh] overflow-y-auto pr-4 -mr-4">
      {rootCauses.map((bottleneck, idx) => {
        const recommendations = getRecommendationsForBottleneck(bottleneck);
        return (
          <div key={idx} className="p-6 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
            <div className="flex items-start justify-between mb-4">
              <div>
                <h3 className="text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {bottleneck.type}
                </h3>
                <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                  {bottleneck.location} • {bottleneck.impactedPatients} patients affected
                </p>
              </div>
              <div className="text-right">
                <div className="text-2xl font-bold text-healthcare-primary dark:text-healthcare-primary-dark">
                  {bottleneck.score.toFixed(1)}
                </div>
                <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Impact Score
                </div>
              </div>
            </div>

            <div className="space-y-6">
              <div>
                <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-3 flex items-center gap-2">
                  <Icon icon="lucide:zap" className="w-4 h-4 text-healthcare-warning dark:text-healthcare-warning-dark" />
                  Immediate Actions (24-48 hrs)
                </h4>
                <ul className="list-disc list-inside space-y-2">
                  {recommendations.immediate.map((item, itemIdx) => (
                    <li key={itemIdx} className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {item}
                    </li>
                  ))}
                </ul>
              </div>

              <div>
                <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-3 flex items-center gap-2">
                  <Icon icon="lucide:clock" className="w-4 h-4 text-healthcare-info dark:text-healthcare-info-dark" />
                  Short-term Initiatives (1-2 weeks)
                </h4>
                <ul className="list-disc list-inside space-y-2">
                  {recommendations.shortTerm.map((item, itemIdx) => (
                    <li key={itemIdx} className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {item}
                    </li>
                  ))}
                </ul>
              </div>

              <div>
                <h4 className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-3 flex items-center gap-2">
                  <Icon icon="lucide:trending-up" className="w-4 h-4 text-healthcare-success dark:text-healthcare-success-dark" />
                  Long-term Solutions (1-3 months)
                </h4>
                <ul className="list-disc list-inside space-y-2">
                  {recommendations.longTerm.map((item, itemIdx) => (
                    <li key={itemIdx} className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {item}
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
};

const RootCause = ({ rootCauses }) => {
  const [showRecommendations, setShowRecommendations] = useState(false);

  return (
    <DashboardLayout>
      <Head title="Root Cause Analysis - ZephyrusOR" />
      <PageContentLayout
        title="Root Cause Analysis"
        subtitle="Real-time Analysis of Patient Flow Barriers and Resource Utilization"
        headerContent={
          <Dialog open={showRecommendations} onOpenChange={setShowRecommendations}>
            <DialogTrigger asChild>
              <Button 
                className="gap-2 bg-healthcare-primary hover:bg-healthcare-primary-dark text-white" 
                variant="default"
              >
                <Icon icon="lucide:sparkles" className="w-4 h-4" />
                AI Assistant
              </Button>
            </DialogTrigger>
            <DialogContent className="max-w-4xl bg-healthcare-background dark:bg-healthcare-background-dark border-healthcare-border dark:border-healthcare-border-dark">
              <DialogHeader className="border-b border-healthcare-border dark:border-healthcare-border-dark pb-4">
                <DialogTitle className="text-2xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark flex items-center gap-2">
                  <Icon icon="lucide:brain" className="w-6 h-6 text-healthcare-primary dark:text-healthcare-primary-dark" />
                  AI Flow Optimization Assistant
                </DialogTitle>
                <DialogDescription className="text-base text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  Based on real-time analysis of your current bottlenecks, here are targeted recommendations with immediate, short-term, and long-term actions to improve patient flow and resource utilization.
                </DialogDescription>
              </DialogHeader>
              <div className="mt-6">
                <AIRecommendations rootCauses={rootCauses} />
              </div>
            </DialogContent>
          </Dialog>
        }
      >
        <div className="space-y-6">
          {rootCauses?.map((rootCause, index) => (
            <div key={index} className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
              <div className="flex justify-between items-start mb-4">
                <div className="flex-1">
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-gray-500 dark:text-gray-400">#{rootCause.rank}</span>
                    <h3 className="text-xl font-semibold text-gray-900 dark:text-white">
                      {rootCause.type}
                    </h3>
                  </div>
                  <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    {rootCause.location}
                  </p>
                </div>
                <div className="text-right">
                  <div className="text-2xl font-bold text-healthcare-primary dark:text-healthcare-primary-dark">
                    {rootCause.score.toFixed(1)}
                  </div>
                  <div className="text-sm text-gray-500 dark:text-gray-400">Severity Score</div>
                </div>
              </div>
              
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div className="p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                  <div className="text-sm text-gray-500 dark:text-gray-400">Patient Impact</div>
                  <div className="text-lg font-semibold">{rootCause.impactedPatients} patients</div>
                  <div className="text-sm text-gray-600 dark:text-gray-300 mt-1">{rootCause.impactDetails}</div>
                </div>
                <div className="p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                  <div className="text-sm text-gray-500 dark:text-gray-400">Process Delay</div>
                  <div className="text-lg font-semibold">{rootCause.avgDelay}</div>
                  <TrendIndicator weekTrend={rootCause.weekTrend} />
                </div>
                <div className="p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                  <div className="text-sm text-gray-500 dark:text-gray-400">Resource Stress</div>
                  <div className="flex items-center gap-1 mb-1">
                    {[...Array(rootCause.stressLevel)].map((_, i) => (
                      <Icon key={i} icon="lucide:activity" className="w-5 h-5 text-orange-500" />
                    ))}
                  </div>
                  <div className="text-sm text-gray-600 dark:text-gray-300">Level {rootCause.stressLevel}/3</div>
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                  <h4 className="text-sm font-medium text-gray-900 dark:text-white mb-2">Root Causes</h4>
                  <ul className="list-disc list-inside space-y-1 text-gray-600 dark:text-gray-300">
                    {rootCause.causes.map((cause, idx) => (
                      <li key={idx} className="text-sm">{cause}</li>
                    ))}
                  </ul>
                </div>
                
                <div>
                  <h4 className="text-sm font-medium text-gray-900 dark:text-white mb-2">Resource Metrics</h4>
                  <ul className="list-none space-y-2">
                    {rootCause.metrics?.map((metric, idx) => (
                      <li key={idx} className="text-sm flex items-center gap-2">
                        <Icon icon="lucide:bar-chart-2" className="w-4 h-4 text-healthcare-primary dark:text-healthcare-primary-dark" />
                        <span className="text-gray-600 dark:text-gray-300">{metric}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              </div>
            </div>
          ))}
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
};

export default RootCause;
