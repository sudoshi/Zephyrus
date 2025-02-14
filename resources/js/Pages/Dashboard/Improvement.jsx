import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head, Link } from '@inertiajs/react';
import { 
  Plus, LayoutDashboard, Target, Library, RefreshCcw,
  Users, Laptop, Activity, Phone, Clock, Heart, ThumbsUp, Stethoscope,
  UserCog, BedDouble, Truck, Home, ClipboardCheck
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import ImprovementCard from '@/Components/Dashboard/ImprovementCard';
import AnalyticsPanel from '@/Components/Design/panels/AnalyticsPanel';
import TrendCard from '@/Components/Design/stats/TrendCard';
import BarChartCard from '@/Components/Design/charts/BarChartCard';
import CircleChartCard from '@/Components/Design/circles/CircleChartCard';
import ProcessIntelligenceCard from '@/Components/Process/ProcessIntelligenceCard';
import ProcessIntelligenceModal from '@/Components/Process/ProcessIntelligenceModal';

const Improvement = ({ auth, stats = {}, cycles = [] }) => {
  // Modal state for process intelligence
  const [selectedProcess, setSelectedProcess] = React.useState(null);
  // Healthcare-focused performance metrics with enhanced data
  const performanceMetrics = [
    {
      title: 'Patient Satisfaction',
      value: '92%',
      change: '+4%',
      changeType: 'positive',
      trendType: 'up',
      ariaLabel: 'Patient satisfaction rate is 92 percent, up 4 percent',
      icon: ThumbsUp,
    },
    {
      title: 'Care Response Time',
      value: '8 min',
      change: '-2 min',
      changeType: 'positive',
      trendType: 'down',
      ariaLabel: 'Average care response time is 8 minutes, improved by 2 minutes',
      icon: Clock,
    },
    {
      title: 'Clinical Outcomes',
      value: '94/100',
      change: '+5',
      changeType: 'positive',
      trendType: 'up',
      ariaLabel: 'Clinical outcomes score is 94 out of 100, up 5 points',
      icon: Heart,
    },
  ];

  // Enhanced patient flow stages data with time-based patterns
  const careJourneyData = [
    { 
      weekday: 'Mon', 
      hospital: { am: 8, pm: 12 }, 
      transition: { am: 3, pm: 4 }, 
      homeSetup: { am: 2, pm: 3 }, 
      active: { am: 6, pm: 8 }, 
      monitoring: { am: 4, pm: 5 } 
    },
    { 
      weekday: 'Tue', 
      hospital: { am: 7, pm: 10 }, 
      transition: { am: 4, pm: 5 }, 
      homeSetup: { am: 3, pm: 4 }, 
      active: { am: 7, pm: 9 }, 
      monitoring: { am: 5, pm: 6 } 
    },
    { 
      weekday: 'Wed', 
      hospital: { am: 9, pm: 11 }, 
      transition: { am: 2, pm: 3 }, 
      homeSetup: { am: 4, pm: 5 }, 
      active: { am: 6, pm: 7 }, 
      monitoring: { am: 3, pm: 4 } 
    },
    { 
      weekday: 'Thu', 
      hospital: { am: 6, pm: 9 }, 
      transition: { am: 5, pm: 6 }, 
      homeSetup: { am: 2, pm: 3 }, 
      active: { am: 8, pm: 10 }, 
      monitoring: { am: 6, pm: 7 } 
    },
    { 
      weekday: 'Fri', 
      hospital: { am: 10, pm: 13 }, 
      transition: { am: 3, pm: 4 }, 
      homeSetup: { am: 3, pm: 4 }, 
      active: { am: 7, pm: 8 }, 
      monitoring: { am: 4, pm: 5 } 
    },
    { 
      weekday: 'Sat', 
      hospital: { am: 5, pm: 8 }, 
      transition: { am: 2, pm: 3 }, 
      homeSetup: { am: 1, pm: 2 }, 
      active: { am: 5, pm: 6 }, 
      monitoring: { am: 3, pm: 4 } 
    },
    { 
      weekday: 'Sun', 
      hospital: { am: 4, pm: 7 }, 
      transition: { am: 1, pm: 2 }, 
      homeSetup: { am: 2, pm: 3 }, 
      active: { am: 4, pm: 5 }, 
      monitoring: { am: 2, pm: 3 } 
    },
  ];

  // Transform data for chart display
  const transformedJourneyData = careJourneyData.map(day => ({
    weekday: day.weekday,
    hospital: day.hospital.am + day.hospital.pm,
    transition: day.transition.am + day.transition.pm,
    homeSetup: day.homeSetup.am + day.homeSetup.pm,
    active: day.active.am + day.active.pm,
    monitoring: day.monitoring.am + day.monitoring.pm,
  }));

  const careJourneyCategories = [
    'Hospital',
    'Transition',
    'Home Setup',
    'Active Care',
    'Monitoring'
  ];

  // Enhanced resource utilization metrics
  const resourceMetrics = [
    {
      icon: Users,
      title: 'Clinical Staff',
      current: 85,
      total: 100,
      unit: 'Staff Members',
      status: 'normal',
      breakdown: {
        nurses: 45,
        physicians: 20,
        technicians: 20,
      },
    },
    {
      icon: Laptop,
      title: 'Remote Monitoring',
      current: 92,
      total: 120,
      unit: 'Devices',
      status: 'warning',
      breakdown: {
        active: 92,
        maintenance: 18,
        standby: 10,
      },
    },
    {
      icon: Activity,
      title: 'Patient Capacity',
      current: 78,
      total: 100,
      unit: 'Patients',
      status: 'normal',
      breakdown: {
        stable: 65,
        requiring_attention: 8,
        new_admissions: 5,
      },
    },
    {
      icon: Stethoscope,
      title: 'Care Quality',
      current: 95,
      total: 100,
      unit: 'Quality Score',
      status: 'success',
      breakdown: {
        clinical: 96,
        satisfaction: 94,
        documentation: 95,
      },
    },
  ];

  // Care coordination metrics with enhanced data
  const coordinationMetrics = {
    title: 'Care Coordination Status',
    value: '94%',
    unit: 'On Track',
    changePercentage: '4%',
    changeType: 'positive',
    chartData: [
      { name: 'On Schedule', value: 75, color: 'var(--healthcare-success)' },
      { name: 'Delayed', value: 15, color: 'var(--healthcare-warning)' },
      { name: 'At Risk', value: 10, color: 'var(--healthcare-critical)' },
    ],
    categories: ['On Schedule', 'Delayed', 'At Risk'],
  };

  return (
    <DashboardLayout>
      <Head title="Improvement Dashboard - ZephyrusOR" />
      <PageContentLayout
        title="Improvement"
        subtitle="Track and manage improvement initiatives across the organization"
      >
        <div className="flex justify-end mb-6">
          <Link href="/improvement/active/new">
            <Button 
              className="healthcare-button"
              aria-label="Create new PDSA cycle"
            >
              <Plus className="h-4 w-4" />
              New PDSA Cycle
            </Button>
          </Link>
        </div>

        <div className="healthcare-grid">
          <ImprovementCard
            title="Overview"
            description="Overview of improvement initiatives"
            icon={LayoutDashboard}
            href="/improvement/overview"
            count={stats.total}
            countLabel="Total Initiatives"
          />

          <ImprovementCard
            title="Opportunities"
            description="Review and prioritize improvement opportunities"
            icon={Target}
            href="/improvement/opportunities"
            count={stats.opportunities}
            countLabel="Open Items"
          />

          <ImprovementCard
            title="Library"
            description="Access improvement resources and templates"
            icon={Library}
            href="/improvement/library"
            count={stats.libraryItems}
            countLabel="Resources"
          />

          <ImprovementCard
            title="Active Cycles"
            description="Track active improvement initiatives"
            icon={RefreshCcw}
            href="/improvement/active"
            count={stats.activePDSA}
            countLabel="Active Cycles"
          />
        </div>

        {/* Two-column grid layout */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 mt-8">
          {/* Left Column */}
          <div className="space-y-8">
            {/* Process Intelligence Panel */}
            <AnalyticsPanel
              title="Process Intelligence"
              subtitle="ECLC Workflow Performance Analysis"
            >
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {/* Staffing Intelligence */}
                <ProcessIntelligenceCard
                  title="Staffing Intelligence"
                  icon={UserCog}
                  primaryMetric={{
                    value: "87%",
                    trend: "+3%",
                    label: "Staff Utilization Rate"
                  }}
                  secondaryMetrics={[
                    { label: "Skill-Mix Score", value: "92%", trend: "+2%" },
                    { label: "Cross-training Coverage", value: "78%", trend: "+5%" },
                    { label: "Overtime Risk", value: "Low", trend: "-8%" }
                  ]}
                  healthScore={94}
                  onClick={() => setSelectedProcess('staffing')}
                />

                {/* Bed Management */}
                <ProcessIntelligenceCard
                  title="Bed Management"
                  icon={BedDouble}
                  primaryMetric={{
                    value: "92%",
                    trend: "+5%",
                    label: "Bed Turnover Efficiency"
                  }}
                  secondaryMetrics={[
                    { label: "Discharge Prediction", value: "95%", trend: "+3%" },
                    { label: "Bottleneck Score", value: "0.3", trend: "-12%" },
                    { label: "Capacity Usage", value: "88%", trend: "+4%" }
                  ]}
                  healthScore={92}
                  onClick={() => setSelectedProcess('beds')}
                />

                {/* Transport Intelligence */}
                <ProcessIntelligenceCard
                  title="Transport Intelligence"
                  icon={Truck}
                  primaryMetric={{
                    value: "12min",
                    trend: "-2min",
                    label: "Avg Response Time"
                  }}
                  secondaryMetrics={[
                    { label: "Route Optimization", value: "89%", trend: "+6%" },
                    { label: "Equipment Available", value: "96%", trend: "+2%" },
                    { label: "On-Time Rate", value: "94%", trend: "+5%" }
                  ]}
                  healthScore={89}
                  onClick={() => setSelectedProcess('transport')}
                />

                {/* Hospital@Home */}
                <ProcessIntelligenceCard
                  title="Hospital@Home"
                  icon={Home}
                  primaryMetric={{
                    value: "95%",
                    trend: "+4%",
                    label: "Patient Stability Index"
                  }}
                  secondaryMetrics={[
                    { label: "Monitoring Effectiveness", value: "97%", trend: "+3%" },
                    { label: "Care Plan Adherence", value: "92%", trend: "+4%" },
                    { label: "Response Time", value: "8min", trend: "-3min" }
                  ]}
                  healthScore={96}
                  onClick={() => setSelectedProcess('home')}
                />

                {/* Care After Discharge */}
                <ProcessIntelligenceCard
                  title="Care After Discharge"
                  icon={ClipboardCheck}
                  primaryMetric={{
                    value: "89%",
                    trend: "+6%",
                    label: "Recovery Progress"
                  }}
                  secondaryMetrics={[
                    { label: "Readmission Risk", value: "Low", trend: "-15%" },
                    { label: "Medication Adherence", value: "94%", trend: "+3%" },
                    { label: "Care Plan Completion", value: "91%", trend: "+7%" }
                  ]}
                  healthScore={91}
                  onClick={() => setSelectedProcess('discharge')}
                />
              </div>

              {/* Process Intelligence Modal */}
              <ProcessIntelligenceModal
                open={!!selectedProcess}
                onOpenChange={() => setSelectedProcess(null)}
                type={selectedProcess}
              />
            </AnalyticsPanel>

            {/* Patient Care Journey Analysis */}
            <AnalyticsPanel
              title="Patient Care Journey Analysis"
              subtitle="Distribution of patients across care stages"
            >
              <BarChartCard
                title="Daily Care Stage Distribution"
                categories={careJourneyCategories}
                chartData={transformedJourneyData}
                colorScheme="healthcare"
                aria-label="Bar chart showing daily distribution of patients across care stages"
              />
            </AnalyticsPanel>


            {/* Recent PDSA Activity */}
            <div className="healthcare-card">
              <div className="p-6">
                <h2 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-4">
                  Recent PDSA Activity
                </h2>
                <div className="space-y-4">
                  {cycles.slice(0, 3).map((cycle, index) => (
                    <div
                      key={index}
                      className="flex items-center justify-between border-b border-healthcare-border dark:border-healthcare-border-dark pb-4 last:border-0"
                    >
                      <div>
                        <h3 className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                          {cycle.title}
                        </h3>
                        <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          {cycle.plan.objective}
                        </p>
                      </div>
                      <Link 
                        href={`/improvement/active/${cycle.id}`}
                        aria-label={`View details for PDSA cycle: ${cycle.title}`}
                      >
                        <Button variant="outline" size="sm" className="healthcare-button">
                          View Details
                        </Button>
                      </Link>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>

          {/* Right Column */}
          <div className="space-y-8">
            {/* Resource Utilization */}
          <AnalyticsPanel
            title="Resource Utilization"
            subtitle="Current resource allocation and capacity"
          >
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              {resourceMetrics.map((metric, index) => (
                <div 
                  key={index}
                  className="healthcare-panel"
                  role="region"
                  aria-label={`${metric.title} metrics`}
                >
                  <div className="flex items-center gap-3 mb-4">
                    <metric.icon className="h-5 w-5 text-healthcare-primary dark:text-healthcare-primary-dark" />
                    <h3 className="font-medium">{metric.title}</h3>
                  </div>
                  <div className="space-y-2">
                    <div className="flex justify-between text-sm">
                      <span>{metric.current} / {metric.total}</span>
                      <span>{metric.unit}</span>
                    </div>
                    <div className="h-2 bg-healthcare-surface-secondary dark:bg-healthcare-surface-dark rounded-full overflow-hidden">
                      <div 
                        className={`h-full rounded-full ${
                          metric.status === 'critical' 
                            ? 'bg-healthcare-critical dark:bg-healthcare-critical-dark'
                            : metric.status === 'warning'
                            ? 'bg-healthcare-warning dark:bg-healthcare-warning-dark'
                            : metric.status === 'success'
                            ? 'bg-healthcare-success dark:bg-healthcare-success-dark'
                            : 'bg-healthcare-primary dark:bg-healthcare-primary-dark'
                        }`}
                        style={{ width: `${(metric.current / metric.total) * 100}%` }}
                        role="progressbar"
                        aria-valuenow={metric.current}
                        aria-valuemin={0}
                        aria-valuemax={metric.total}
                      />
                    </div>
                    <div className="mt-4 space-y-2">
                      {Object.entries(metric.breakdown).map(([key, value], i) => (
                        <div key={i} className="flex justify-between text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          <span className="capitalize">{key.replace('_', ' ')}</span>
                          <span>{value}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </AnalyticsPanel>
            {/* Care Transition Performance */}
          <AnalyticsPanel
            title="Care Transition Performance"
            subtitle="Key performance indicators for patient care transitions"
          >
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              {performanceMetrics.map((metric, index) => (
                <TrendCard
                  key={index}
                  title={metric.title}
                  value={metric.value}
                  change={metric.change}
                  changeType={metric.changeType}
                  trendType={metric.trendType}
                  aria-label={metric.ariaLabel}
                />
              ))}
            </div>
          </AnalyticsPanel>
            {/* Care Coordination Overview */}
          <AnalyticsPanel
            title="Care Coordination Overview"
            subtitle="Current status of care coordination efforts"
          >
            <CircleChartCard
              title={coordinationMetrics.title}
              value={coordinationMetrics.value}
              unit={coordinationMetrics.unit}
              changePercentage={coordinationMetrics.changePercentage}
              changeType={coordinationMetrics.changeType}
              chartData={coordinationMetrics.chartData}
              categories={coordinationMetrics.categories}
              colorScheme="healthcare"
            />
          </AnalyticsPanel>
          </div>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
};

export default Improvement;
