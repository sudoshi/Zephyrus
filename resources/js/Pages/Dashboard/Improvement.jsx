import React from 'react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Head } from '@inertiajs/react';
import {
  LayoutDashboard, Target, Library, RefreshCcw,
  Users, Laptop, Activity, Stethoscope,
  UserCog, BedDouble, Truck, Home, ClipboardCheck
} from 'lucide-react';
import { Section, MetricGrid, Panel, metric } from '@/Components/system';
import ImprovementCard from '@/Components/Dashboard/ImprovementCard';
import ChronicCarePanel from '@/Components/Process/ChronicCarePanel';
import ProcessIntelligenceCard from '@/Components/Process/ProcessIntelligenceCard';
import ProcessIntelligenceModal from '@/Components/Process/ProcessIntelligenceModal';
import DischargeProcessFailures from '@/Components/Process/DischargeProcessFailures';
import { formatDurationMinutes } from '@/lib/duration';

// Improvement workflow dashboard rebuilt on the gold-standard design system: the
// care-transition KPI wall is one MetricGrid; every bespoke Card wrapping a
// sub-panel (Process Intelligence, Discharge failures, Resource utilization,
// Care coordination) is now a Section + Panel. Navigation tiles, process-
// intelligence cards, and live child widgets are preserved unchanged.

const Improvement = ({ auth, stats = {}, cycles = [] }) => {
  // Modal state for process intelligence
  const [selectedProcess, setSelectedProcess] = React.useState(null);

  // Care-transition KPIs — mapped onto the gold-standard metric contract. No
  // per-metric history is available, so no sparkline is fabricated; the change
  // chip becomes the caption and the trend drives status.
  const performanceMetrics = [
    metric({
      key: 'patient-satisfaction', label: 'Patient Satisfaction', value: 92, display: '92%',
      status: 'success', caption: '+4% vs prior period',
      definition: 'Patient satisfaction rate is 92 percent, up 4 percent.',
    }),
    metric({
      key: 'care-response-time', label: 'Care Response Time', value: 8, display: formatDurationMinutes(8),
      status: 'success', goodWhenDown: true, caption: `-${formatDurationMinutes(2)} vs prior period`,
      definition: `Average care response time is ${formatDurationMinutes(8)}, improved by ${formatDurationMinutes(2)}.`,
    }),
    metric({
      key: 'clinical-outcomes', label: 'Clinical Outcomes', value: 94, display: '94/100',
      status: 'success', caption: '+5 vs prior period',
      definition: 'Clinical outcomes score is 94 out of 100, up 5 points.',
    }),
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

  return (
    <DashboardLayout>
      <Head title="Improvement Dashboard - ZephyrusOR" />
      <PageContentLayout
        title="Improvement"
        subtitle="Track and manage improvement initiatives across the organization"
      >
        <div className="flex flex-col gap-5">
          <Section
            title="Initiatives"
            icon="heroicons:rocket-launch"
            summary="Overview, opportunities, library & active improvement cycles"
          >
            <div className="healthcare-grid">
              <ImprovementCard
                title="Overview"
                description="Overview of improvement initiatives"
                icon={LayoutDashboard}
                href="/dashboard/improvement"
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
          </Section>

          {/* Two-column grid layout */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* Left Column */}
            <div className="flex flex-col gap-5">
              {/* Process Intelligence Panel */}
              <Section
                title="Process Intelligence"
                icon="heroicons:cpu-chip"
                summary="ECLC Workflow Performance Analysis"
              >
                <Panel className="p-4">
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
                        value: formatDurationMinutes(12),
                        trend: formatDurationMinutes(-2),
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
                        { label: "Response Time", value: formatDurationMinutes(8), trend: formatDurationMinutes(-3) }
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

                    {/* Chronic Care Intelligence */}
                    <ProcessIntelligenceCard
                      title="Chronic Care Intelligence"
                      icon={Activity}
                      primaryMetric={{
                        value: "89%",
                        trend: "+2%",
                        label: "Overall Care Score"
                      }}
                      secondaryMetrics={[
                        { label: "High Risk Conditions", value: "3", trend: "+1" },
                        { label: "Medication Adherence", value: "89%", trend: "+2%" },
                        { label: "Critical Alerts", value: "2", trend: "0" }
                      ]}
                      healthScore={87}
                      onClick={() => setSelectedProcess('chronic')}
                    />
                  </div>

                  {/* Process Intelligence Modal */}
                  <ProcessIntelligenceModal
                    open={!!selectedProcess}
                    onOpenChange={() => setSelectedProcess(null)}
                    type={selectedProcess}
                  />
                </Panel>
              </Section>

              {/* Discharge Process Failures */}
              <Section
                title="Discharge Process Failures"
                icon="heroicons:exclamation-triangle"
                summary="Analysis of discharge-related process failures and improvement opportunities"
              >
                <Panel className="p-4">
                  <DischargeProcessFailures />
                </Panel>
              </Section>
            </div>

            {/* Right Column */}
            <div className="flex flex-col gap-5">
              {/* Resource Utilization */}
              <Section
                title="Resource Utilization"
                icon="heroicons:chart-pie"
                summary="Current resource allocation and capacity"
              >
                <Panel className="p-4">
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
                </Panel>
              </Section>

              {/* Care Transition Performance */}
              <Section
                title="Care Transition Performance"
                icon="heroicons:arrows-right-left"
                summary="Key performance indicators for patient care transitions"
              >
                <MetricGrid metrics={performanceMetrics} />
              </Section>

              {/* Care Coordination Overview */}
              <Section
                title="Care Coordination Overview"
                icon="heroicons:user-group"
                summary="Current status of care coordination efforts"
              >
                <Panel className="p-4">
                  <ChronicCarePanel />
                </Panel>
              </Section>
            </div>
          </div>
        </div>
      </PageContentLayout>
    </DashboardLayout>
  );
};

export default Improvement;
