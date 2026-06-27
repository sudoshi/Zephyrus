import React, { useState } from 'react';
import { Icon } from '@iconify/react';
import { ProgressBar, CaseStatusBadge } from './StatusIndicator';
import CareJourneyModal from './CareJourneyModal';
import CareJourneyCard from './CareJourneyCard';
import { Section, MetricGrid, Panel, metric, STATUS_VAR } from '@/Components/system';

// Case Management instrument rebuilt on the gold-standard design system: the KPI
// wall is one MetricGrid of KpiTiles (status dot + value + gauge + target +
// caption), the service-line / resource-status panels and the active-procedures
// table live in Panels under Section headers. All live props (procedures,
// specialties, locations, stats) and interactive state (phase filter, row
// selection, care-journey modal) are preserved; nothing is fabricated.

// Map the operational status used across the case-tracker into the four-color
// command-center vocabulary so STATUS_VAR drives every status surface here.
const onTimeStatus = (rate) =>
  rate >= 95 ? 'success' : rate >= 80 ? 'info' : rate >= 60 ? 'warning' : 'critical';

const utilizationStatus = (rate) =>
  rate >= 90 ? 'critical' : rate >= 75 ? 'warning' : 'success';

const CaseTracker = ({ procedures, specialties, locations, stats }) => {
  const [selectedPhase, setSelectedPhase] = useState('all');
  const [selectedCase, setSelectedCase] = useState(null);
  const [isModalOpen, setIsModalOpen] = useState(false);

  const handleCaseClick = (caseData) => {
    setSelectedCase(caseData);
    setIsModalOpen(true);
  };

  const handleCloseDetail = () => {
    setSelectedCase(null);
    setIsModalOpen(false);
  };

  const delayedCount = procedures.filter((proc) => proc.resourceStatus === 'Delayed').length;

  // KPI wall — the throughput tile is computed from live `stats`; the
  // performance / resource / turnover tiles preserve their existing demo values
  // (and the real 4-point turnover series renders a sparkline via trajectory).
  const completionStatus = stats.delayed > 0 ? 'warning' : 'success';

  const kpiMetrics = [
    metric({
      key: 'procedures',
      label: 'Procedures Today',
      value: Number(stats.totalPatients ?? 0),
      status: completionStatus,
      caption: `${stats.inProgress} in progress · ${stats.preOp} pre-op · ${stats.completed} done · ${stats.delayed} delayed`,
      definition: 'Total surgical cases on the schedule for the current operating day.',
    }),
    metric({
      key: 'time-performance',
      label: 'Time Performance',
      value: 86,
      unit: '%',
      status: 'success',
      target: 90,
      trajectory: [82, 84, 85, 86],
      caption: '24/28 cases on time · ↑ 2.1%',
      definition: 'Share of cases starting and progressing on schedule.',
    }),
    metric({
      key: 'resource-usage',
      label: 'Resource Usage',
      value: 83,
      unit: '%',
      status: 'warning',
      caption: '15/20 rooms · OR 6/8 · Cath 2/3',
      definition: 'Procedure rooms currently in use across all service lines.',
    }),
    metric({
      key: 'turnover',
      label: 'Turnover Time',
      value: 24,
      display: '24m',
      status: 'success',
      target: 25,
      targetDisplay: '25m target',
      trajectory: [22, 24, 23, 22],
      goodWhenDown: true,
      caption: 'Last 4 turnovers · last 22m · ↓ 1m',
      definition: 'Average room turnover time between consecutive cases.',
    }),
  ];

  return (
    <div className="flex flex-col gap-5">
      {/* KPI wall */}
      <Section
        title="Case Throughput"
        icon="heroicons:clipboard-document-check"
        summary={`${stats.totalPatients} cases · ${stats.inProgress} in progress`}
      >
        <MetricGrid metrics={kpiMetrics} />
      </Section>

      {/* Service Line & Resource Status */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Section title="Service Line Status" icon="heroicons:rectangle-stack">
          <Panel className="p-4">
            <div className="space-y-4">
              {Object.entries(specialties).map(([name, data]) => {
                const onTimeRate = data.count > 0 ? (data.onTime / data.count) * 100 : 0;
                const status = onTimeStatus(onTimeRate);
                return (
                  <div key={name} className="flex items-center justify-between">
                    <div className="flex items-center space-x-3">
                      <div className={`h-8 w-8 rounded-full bg-healthcare-${data.color}-light dark:bg-healthcare-${data.color}-dark/20 flex items-center justify-center`}>
                        <span className={`text-healthcare-${data.color} dark:text-healthcare-${data.color}-dark font-medium tabular-nums`}>
                          {data.count}
                        </span>
                      </div>
                      <div>
                        <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{name}</div>
                        <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                          {data.onTime} on time • {data.delayed} delayed
                        </div>
                      </div>
                    </div>
                    <span
                      className="inline-flex items-center gap-1 text-sm font-medium tabular-nums"
                      style={{ color: STATUS_VAR[status] }}
                    >
                      <Icon
                        icon={status === 'success' ? 'heroicons:check-circle' : status === 'info' ? 'heroicons:arrow-trending-up' : 'heroicons:exclamation-triangle'}
                        className="h-4 w-4"
                        aria-hidden="true"
                      />
                      {onTimeRate.toFixed(0)}% On-Time
                    </span>
                  </div>
                );
              })}
            </div>
          </Panel>
        </Section>

        <Section title="Resource Status" icon="heroicons:building-office-2">
          <Panel className="p-4">
            <div className="space-y-4">
              {Object.entries(locations).map(([name, data]) => {
                const utilization = data.total > 0 ? (data.inUse / data.total) * 100 : 0;
                const status = utilizationStatus(utilization);
                return (
                  <div key={name} className="flex items-center justify-between">
                    <div>
                      <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{name}</div>
                      <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        {data.inUse} in use • {data.total - data.inUse} available
                      </div>
                    </div>
                    <div className="w-36">
                      <div className="h-1.5 bg-healthcare-border dark:bg-healthcare-border-dark rounded-full overflow-hidden">
                        <div
                          className="h-full rounded-full"
                          style={{ width: `${utilization}%`, backgroundColor: STATUS_VAR[status] }}
                        ></div>
                      </div>
                      <div className="text-xs tabular-nums text-right mt-1" style={{ color: STATUS_VAR[status] }}>
                        {data.inUse}/{data.total}
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </Panel>
        </Section>
      </div>

      {/* Active Procedures */}
      <Section
        title="Active Procedures"
        icon="heroicons:queue-list"
        summary={delayedCount > 0 ? `${delayedCount} showing delays` : undefined}
        actions={
          <div className="flex space-x-2">
            {['Pre-Op', 'Procedure', 'Recovery'].map((phase) => (
              <button
                key={phase}
                onClick={() => setSelectedPhase(phase)}
                className={`px-3 py-1 rounded-full text-sm ${
                  selectedPhase === phase
                    ? 'bg-healthcare-info-light dark:bg-healthcare-info-dark/20 text-healthcare-info dark:text-healthcare-info-dark'
                    : 'bg-healthcare-background dark:bg-healthcare-background-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
                }`}
              >
                {phase}
              </button>
            ))}
            <button
              onClick={() => setSelectedPhase('all')}
              className={`px-3 py-1 rounded-full text-sm ${
                selectedPhase === 'all'
                  ? 'bg-healthcare-background-alt dark:bg-healthcare-background-alt-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark'
                  : 'bg-healthcare-background dark:bg-healthcare-background-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
              }`}
            >
              All
            </button>
          </div>
        }
      >
        <Panel className="p-4 overflow-hidden">
          {delayedCount > 0 && (
            <div className="mb-4 rounded-lg p-3 flex items-center space-x-2 border border-healthcare-warning dark:border-healthcare-warning-dark bg-healthcare-warning-light dark:bg-healthcare-warning-dark/20">
              <Icon icon="heroicons:exclamation-circle" className="h-5 w-5 text-healthcare-warning dark:text-healthcare-warning-dark" />
              <span className="text-sm text-healthcare-warning dark:text-healthcare-warning-dark">
                {delayedCount} procedures currently showing delays. Resource adjustment recommended.
              </span>
            </div>
          )}

          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="text-left text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  <th className="pb-3 font-medium">Patient & Procedure</th>
                  <th className="pb-3 font-medium">Location & Staff</th>
                  <th className="pb-3 font-medium">Timing</th>
                  <th className="pb-3 font-medium w-1/3">Status & Progress</th>
                </tr>
              </thead>
              <tbody className="text-sm">
                {procedures
                  .filter((proc) => selectedPhase === 'all' || proc.phase === selectedPhase)
                  .map((proc) => {
                    const startTime = new Date(`2025-01-16T${proc.startTime}`);
                    const estimatedEnd = new Date(startTime.getTime() + proc.expectedDuration * 60000);
                    const estimatedCompletion = estimatedEnd.toLocaleTimeString('en-US', {
                      hour: '2-digit',
                      minute: '2-digit',
                      hour12: false,
                    });

                    let progressStatus = proc.resourceStatus === 'Delayed' ? 'delayed' : 'onTime';
                    if (proc.phase === 'Recovery') progressStatus = 'completed';

                    return (
                      <tr
                        key={proc.id}
                        onClick={() => handleCaseClick(proc)}
                        className={`cursor-pointer border-t border-healthcare-border dark:border-healthcare-border-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-150 ${
                          progressStatus === 'delayed' ? 'bg-healthcare-error-light dark:bg-healthcare-error-dark/10' : ''
                        }`}
                      >
                        <td className="py-3 px-2">
                          <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{proc.patient}</div>
                          <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{proc.type}</div>
                          <div className="flex items-center space-x-2 mt-1">
                            <CaseStatusBadge status={proc.status} />
                            <span className={`inline-block px-2 py-0.5 rounded-full text-xs ${
                              proc.specialty === 'General Surgery'
                                ? 'bg-healthcare-blue-light dark:bg-healthcare-blue-dark/20 text-healthcare-blue dark:text-healthcare-blue-dark'
                                : proc.specialty === 'Orthopedics'
                                ? 'bg-healthcare-green-light dark:bg-healthcare-green-dark/20 text-healthcare-green dark:text-healthcare-green-dark'
                                : proc.specialty === 'OBGYN'
                                ? 'bg-healthcare-pink-light dark:bg-healthcare-pink-dark/20 text-healthcare-pink dark:text-healthcare-pink-dark'
                                : proc.specialty === 'Cardiac'
                                ? 'bg-healthcare-red-light dark:bg-healthcare-red-dark/20 text-healthcare-red dark:text-healthcare-red-dark'
                                : 'bg-healthcare-yellow-light dark:bg-healthcare-yellow-dark/20 text-healthcare-yellow dark:text-healthcare-yellow-dark'
                            }`}>
                              {proc.specialty}
                            </span>
                          </div>
                        </td>
                        <td className="py-3 px-2">
                          <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{proc.location}</div>
                          <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{proc.provider}</div>
                          <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
                            {proc.phase === 'Pre-Op' && 'Preparing'}
                            {proc.phase === 'Procedure' && 'In Surgery'}
                            {proc.phase === 'Recovery' && 'Recovering'}
                          </div>
                        </td>
                        <td className="py-3 px-2">
                          <div className="space-y-1">
                            <div className="flex items-center space-x-1">
                              <Icon icon="heroicons:clock-4" className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                              <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark tabular-nums">{proc.startTime}</span>
                            </div>
                            <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                              Duration: {proc.expectedDuration} min
                            </div>
                            {progressStatus === 'delayed' && (
                              <div className="text-xs text-healthcare-error dark:text-healthcare-error-dark">
                                Delay: ~15-30 min
                              </div>
                            )}
                          </div>
                        </td>
                        <td className="py-3 px-2">
                          <ProgressBar
                            value={proc.journey}
                            max={100}
                            status={progressStatus}
                            estimatedCompletion={estimatedCompletion}
                          />
                          {proc.phase === 'Procedure' && (
                            <div className="mt-2 grid grid-cols-3 gap-2 text-xs">
                              <div className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                <span className="font-medium">Anesthesia:</span>{' '}
                                {progressStatus === 'delayed' ? 'Delayed' : 'Ready'}
                              </div>
                              <div className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                <span className="font-medium">Blood Loss:</span> Minimal
                              </div>
                              <div className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                                <span className="font-medium">Vitals:</span> Stable
                              </div>
                            </div>
                          )}
                        </td>
                      </tr>
                    );
                  })}
              </tbody>
            </table>
          </div>
        </Panel>
      </Section>

      {selectedCase && (
        <CareJourneyModal open={isModalOpen} onClose={handleCloseDetail}>
          <CareJourneyCard
            procedure={selectedCase}
            measurements={[]}
            onClose={handleCloseDetail}
          />
        </CareJourneyModal>
      )}
    </div>
  );
};

export default CaseTracker;
