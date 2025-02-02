import React, { useState } from 'react';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { StatusDot, ProgressBar, CaseStatusBadge } from './StatusIndicator';
import CaseDetailView from './CaseDetailView';

const MetricCard = ({ title, icon, value, subValue, children }) => (
  <div className="space-y-2 p-4 border rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark">
    <div className="flex items-center justify-between">
      <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        {title}
      </span>
      <Icon icon={icon} className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
    </div>
    <div className="flex items-baseline justify-between">
      <span className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{value}</span>
      {subValue && (
        <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{subValue}</span>
      )}
    </div>
    {children}
  </div>
);

const ServiceLineStatus = ({ specialties }) => (
  <div className="p-4 border rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark">
    <h3 className="text-md font-medium mb-4 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
      Service Line Status
    </h3>
    <div className="space-y-4">
      {Object.entries(specialties).map(([name, data]) => (
        <div key={name} className="flex items-center justify-between">
          <div className="flex items-center space-x-3">
            <div className={`h-8 w-8 rounded-full bg-healthcare-${data.color}-light dark:bg-healthcare-${data.color}-dark/20 flex items-center justify-center`}>
              <span className={`text-healthcare-${data.color} dark:text-healthcare-${data.color}-dark font-medium`}>
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
          <div className="flex items-center space-x-2">
            <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {((data.onTime / data.count) * 100).toFixed(0)}% On-Time
            </span>
          </div>
        </div>
      ))}
    </div>
  </div>
);

const ResourceStatus = ({ locations }) => (
  <div className="p-4 border rounded-lg bg-healthcare-surface dark:bg-healthcare-surface-dark">
    <h3 className="text-md font-medium mb-4 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
      Resource Status
    </h3>
    <div className="space-y-4">
      {Object.entries(locations).map(([name, data]) => (
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
                className="h-full bg-healthcare-info dark:bg-healthcare-info-dark rounded-full"
                style={{ width: `${(data.inUse / data.total) * 100}%` }}
              ></div>
            </div>
            <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark text-right mt-1">
              {data.inUse}/{data.total}
            </div>
          </div>
        </div>
      ))}
    </div>
  </div>
);

const CaseTracker = ({ procedures, specialties, locations, stats }) => {
  const [selectedPhase, setSelectedPhase] = useState("all");
  const [selectedCase, setSelectedCase] = useState(null);

  const handleCaseClick = (caseData) => {
    setSelectedCase(caseData);
  };

  const handleCloseDetail = () => {
    setSelectedCase(null);
  };

  return (
    <div className="space-y-6">
      {/* Stats Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <MetricCard
          title="Procedures"
          icon="heroicons:users"
          value={stats.totalPatients}
          subValue="Today"
        >
          <div className="grid grid-cols-2 gap-2 text-xs">
            <div className="flex items-center space-x-1">
              <StatusDot status="onTime" />
              <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {stats.inProgress} In Progress
              </span>
            </div>
            <div className="flex items-center space-x-1">
              <StatusDot status="warning" />
              <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {stats.preOp} Pre-Op
              </span>
            </div>
            <div className="flex items-center space-x-1">
              <StatusDot status="completed" />
              <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {stats.completed} Completed
              </span>
            </div>
            <div className="flex items-center space-x-1">
              <StatusDot status="delayed" />
              <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {stats.delayed} Delayed
              </span>
            </div>
          </div>
        </MetricCard>

        <MetricCard
          title="Time Performance"
          icon="heroicons:clock"
          value="86%"
          subValue="↑ 2.1%"
        >
          <div className="space-y-1">
            <div className="flex justify-between text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              <span>On Time</span>
              <span>24/28 Cases</span>
            </div>
            <div className="h-1.5 bg-healthcare-border dark:bg-healthcare-border-dark rounded-full overflow-hidden">
              <div className="h-full bg-healthcare-success dark:bg-healthcare-success-dark rounded-full" style={{ width: "86%" }}></div>
            </div>
          </div>
        </MetricCard>

        <MetricCard
          title="Resource Usage"
          icon="heroicons:chart-bar"
          value="83%"
          subValue="15/20 Rooms"
        >
          <div className="grid grid-cols-2 gap-2 text-xs">
            <div>
              <div className="flex justify-between text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">
                <span>OR</span>
                <span>6/8</span>
              </div>
              <div className="h-1.5 bg-healthcare-border dark:bg-healthcare-border-dark rounded-full overflow-hidden">
                <div className="h-full bg-healthcare-info dark:bg-healthcare-info-dark rounded-full" style={{ width: "75%" }}></div>
              </div>
            </div>
            <div>
              <div className="flex justify-between text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-1">
                <span>Cath</span>
                <span>2/3</span>
              </div>
              <div className="h-1.5 bg-healthcare-border dark:bg-healthcare-border-dark rounded-full overflow-hidden">
                <div className="h-full bg-healthcare-info dark:bg-healthcare-info-dark rounded-full" style={{ width: "66%" }}></div>
              </div>
            </div>
          </div>
        </MetricCard>

        <MetricCard
          title="Turnover Times"
          icon="heroicons:clock-4"
          value="24m"
          subValue="↓ 1m"
        >
          <div className="space-y-1 text-xs">
            <div className="flex justify-between text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              <span>Target: 25m</span>
              <span>Last: 22m</span>
            </div>
            <div className="grid grid-cols-4 gap-1">
              {[22, 24, 23, 22].map((time, i) => (
                <div
                  key={i}
                  className="h-1.5 bg-healthcare-info dark:bg-healthcare-info-dark rounded-full"
                  style={{ opacity: 0.5 + i * 0.15 }}
                ></div>
              ))}
            </div>
            <div className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Last 4 turnovers</div>
          </div>
        </MetricCard>
      </div>

      {/* Service Line & Resource Status */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <ServiceLineStatus specialties={specialties} />
        <ResourceStatus locations={locations} />
      </div>

      {/* Active Procedures */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle className="text-lg">Active Procedures</CardTitle>
          <div className="flex space-x-2">
            {["Pre-Op", "Procedure", "Recovery"].map((phase) => (
              <button
                key={phase}
                onClick={() => setSelectedPhase(phase)}
                className={`px-3 py-1 rounded-full text-sm ${
                  selectedPhase === phase
                    ? "bg-healthcare-info-light dark:bg-healthcare-info-dark/20 text-healthcare-info dark:text-healthcare-info-dark"
                    : "bg-healthcare-background dark:bg-healthcare-background-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
                }`}
              >
                {phase}
              </button>
            ))}
            <button
              onClick={() => setSelectedPhase("all")}
              className={`px-3 py-1 rounded-full text-sm ${
                selectedPhase === "all"
                  ? "bg-healthcare-background-alt dark:bg-healthcare-background-alt-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
                  : "bg-healthcare-background dark:bg-healthcare-background-dark text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark"
              }`}
            >
              All
            </button>
          </div>
        </CardHeader>
        <CardContent>
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
                  .filter((proc) => selectedPhase === "all" || proc.phase === selectedPhase)
                  .map((proc) => {
                    const startTime = new Date(`2025-01-16T${proc.startTime}`);
                    const estimatedEnd = new Date(startTime.getTime() + proc.expectedDuration * 60000);
                    const estimatedCompletion = estimatedEnd.toLocaleTimeString("en-US", {
                      hour: "2-digit",
                      minute: "2-digit",
                      hour12: false,
                    });

                    let progressStatus = proc.resourceStatus === "Delayed" ? "delayed" : "onTime";
                    if (proc.phase === "Recovery") progressStatus = "completed";

                    return (
                      <tr
                        key={proc.id}
                        onClick={() => handleCaseClick(proc)}
                        className={`cursor-pointer border-t border-healthcare-border dark:border-healthcare-border-dark hover:bg-healthcare-background dark:hover:bg-healthcare-background-dark transition-colors duration-150 ${
                          progressStatus === "delayed" ? "bg-healthcare-error-light dark:bg-healthcare-error-dark/10" : ""
                        }`}
                      >
                        <td className="py-3 px-2">
                          <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{proc.patient}</div>
                          <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{proc.type}</div>
                          <CaseStatusBadge status={proc.status} />
                        </td>
                        <td className="py-3 px-2">
                          <div className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{proc.location}</div>
                          <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{proc.provider}</div>
                        </td>
                        <td className="py-3 px-2">
                          <div className="space-y-1">
                            <div className="flex items-center space-x-1">
                              <Icon icon="heroicons:clock-4" className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                              <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{proc.startTime}</span>
                            </div>
                            <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                              Duration: {proc.expectedDuration} min
                            </div>
                          </div>
                        </td>
                        <td className="py-3 px-2">
                          <ProgressBar
                            value={proc.journey}
                            max={100}
                            status={progressStatus}
                            estimatedCompletion={estimatedCompletion}
                          />
                        </td>
                      </tr>
                    );
                  })}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      {selectedCase && (
        <CaseDetailView
          caseData={selectedCase}
          onClose={handleCloseDetail}
        />
      )}
    </div>
  );
};

export default CaseTracker;
