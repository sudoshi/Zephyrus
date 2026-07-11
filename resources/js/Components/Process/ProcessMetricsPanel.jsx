import React from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/Components/ui/Card';
import { formatProcessDuration } from './formatDuration';

const MetricItem = ({ label, value, subValue }) => (
  <div className="p-3 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg">
    <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{label}</div>
    <div className="text-xl font-semibold mt-1">{value}</div>
    {subValue && (
      <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">{subValue}</div>
    )}
  </div>
);

const ProcessMetricsPanel = ({ 
  selectedNode, 
  selectedEdge, 
  overallMetrics 
}) => {
  if (selectedNode) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{selectedNode.data.label} Metrics</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <MetricItem
                label="Total Patients"
                value={selectedNode.data.metrics.count}
              />
              <MetricItem
                label="Average Time"
                value={formatProcessDuration(selectedNode.data.metrics.avgTime)}
              />
            </div>
            
            {selectedNode.data.metrics.cohorts && (
              <div>
                <h4 className="font-semibold mb-2">Cohort Analysis</h4>
                <div className="space-y-2">
                  {Object.entries(selectedNode.data.metrics.cohorts).map(([cohort, data]) => (
                    <div 
                      key={cohort}
                      className="flex justify-between items-center p-2 bg-healthcare-background dark:bg-healthcare-background-dark rounded"
                    >
                      <span className="font-medium capitalize">{cohort}</span>
                      <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        <span className="mr-4">{data.count} patients</span>
                        <span>{formatProcessDuration(data.avgTime)}</span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </CardContent>
      </Card>
    );
  }

  if (selectedEdge) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Path Metrics</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <MetricItem
                label="Patient Flow"
                value={selectedEdge.data.patientCount}
                subValue="Total patients"
              />
              <MetricItem
                label="Average Time"
                value={formatProcessDuration(selectedEdge.data.avgTime)}
                subValue="Per patient"
              />
            </div>

            {selectedEdge.data.cohortMetrics && (
              <div>
                <h4 className="font-semibold mb-2">Cohort Breakdown</h4>
                <div className="space-y-2">
                  {Object.entries(selectedEdge.data.cohortMetrics).map(([cohort, metrics]) => (
                    <div 
                      key={cohort}
                      className="flex justify-between items-center p-2 bg-healthcare-background dark:bg-healthcare-background-dark rounded"
                    >
                      <span className="font-medium capitalize">{cohort}</span>
                      <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                        <span className="mr-4">{metrics.count} patients</span>
                        <span>{formatProcessDuration(metrics.avgTime)}</span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Overall Process Metrics</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="grid grid-cols-2 gap-4">
          <MetricItem
            label="Total Patients"
            value={overallMetrics.totalPatients}
          />
          <MetricItem
            label="Average Total Time"
            value={formatProcessDuration(overallMetrics.avgTotalTime)}
          />
          <MetricItem
            label="Active Cases"
            value={overallMetrics.activeCases}
          />
          <MetricItem
            label="Completed Today"
            value={overallMetrics.completedToday}
          />
        </div>
      </CardContent>
    </Card>
  );
};

export default ProcessMetricsPanel;
