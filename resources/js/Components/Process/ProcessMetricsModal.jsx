import React from 'react';

const ProcessMetricsModal = ({ isOpen, onClose, selectedNode, selectedEdge, overallMetrics }) => {
  if (!isOpen) return null;

  const renderOverallMetrics = () => (
    <div className="space-y-4">
      <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        Overall Process Metrics
      </h3>
      <div className="grid grid-cols-2 gap-4">
        <div className="healthcare-card p-4">
          <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Total Patients</div>
          <div className="text-2xl font-bold text-healthcare-primary dark:text-healthcare-primary-dark">
            {overallMetrics?.totalPatients || 0}
          </div>
        </div>
        <div className="healthcare-card p-4">
          <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Average Time</div>
          <div className="text-2xl font-bold text-healthcare-success dark:text-healthcare-success-dark">
            {overallMetrics?.avgTotalTime || '0m'}
          </div>
        </div>
        <div className="healthcare-card p-4">
          <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Active Cases</div>
          <div className="text-2xl font-bold text-healthcare-warning dark:text-healthcare-warning-dark">
            {overallMetrics?.activeCases || 0}
          </div>
        </div>
        <div className="healthcare-card p-4">
          <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Completed Today</div>
          <div className="text-2xl font-bold text-healthcare-info dark:text-healthcare-info-dark">
            {overallMetrics?.completedToday || 0}
          </div>
        </div>
      </div>
    </div>
  );

  const renderNodeMetrics = () => (
    <div className="space-y-4">
      <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {selectedNode.data.label} Metrics
      </h3>
      <div className="healthcare-card p-4 space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Total Cases</div>
            <div className="text-2xl font-bold text-healthcare-primary dark:text-healthcare-primary-dark">
              {selectedNode.data.metrics?.count || 0}
            </div>
          </div>
          <div>
            <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Average Time</div>
            <div className="text-2xl font-bold text-healthcare-success dark:text-healthcare-success-dark">
              {selectedNode.data.metrics?.avgTime || '0m'}
            </div>
          </div>
        </div>
        <div>
          <h4 className="text-sm font-semibold mb-2">Cohort Breakdown</h4>
          <div className="space-y-2">
            {Object.entries(selectedNode.data.metrics?.cohorts || {}).map(([cohort, metrics]) => (
              <div key={cohort} className="flex justify-between items-center p-2 bg-healthcare-surface-dark rounded">
                <span className="capitalize text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  {cohort}
                </span>
                <div className="text-right">
                  <div className="text-healthcare-info dark:text-healthcare-info-dark">{metrics.count} cases</div>
                  <div className="text-healthcare-success dark:text-healthcare-success-dark">{metrics.avgTime}</div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );

  const renderEdgeMetrics = () => (
    <div className="space-y-4">
      <h3 className="text-lg font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        Transition Metrics
      </h3>
      <div className="healthcare-card p-4 space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Patient Count</div>
            <div className="text-2xl font-bold text-healthcare-primary dark:text-healthcare-primary-dark">
              {selectedEdge.data?.patientCount || 0}
            </div>
          </div>
          <div>
            <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Average Time</div>
            <div className="text-2xl font-bold text-healthcare-success dark:text-healthcare-success-dark">
              {selectedEdge.data?.avgTime || '0m'}
            </div>
          </div>
        </div>
      </div>
    </div>
  );

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div className="bg-white dark:bg-healthcare-background-dark rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-hidden">
        <div className="p-6 space-y-6">
          <div className="flex justify-between items-center">
            <h2 className="text-xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Process Analytics
            </h2>
            <button
              onClick={onClose}
              className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark hover:text-healthcare-primary dark:hover:text-healthcare-primary-dark"
            >
              <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          
          <div className="overflow-y-auto max-h-[calc(90vh-8rem)] p-1 space-y-6">
            {!selectedNode && !selectedEdge && renderOverallMetrics()}
            {selectedNode && renderNodeMetrics()}
            {selectedEdge && renderEdgeMetrics()}
          </div>
        </div>
      </div>
    </div>
  );
};

export default ProcessMetricsModal;
