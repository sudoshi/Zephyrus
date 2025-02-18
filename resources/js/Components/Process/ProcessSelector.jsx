import React from 'react';

const hospitals = [
  'Virtua Marlton Hospital',
  'Virtua Mount Holly Hospital',
  'Virtua Our Lady of Lourdes Hospital',
  'Virtua Voorhees Hospital',
  'Virtua Willingboro Hospital'
];

const workflows = [
  'Admissions',
  'Discharges'
];

const timeRanges = [
  '24 Hours',
  '7 Days',
  '14 Days',
  '1 Month'
];

const ProcessSelector = ({ 
  selectedHospital, 
  selectedWorkflow, 
  selectedTimeRange, 
  onHospitalChange, 
  onWorkflowChange, 
  onTimeRangeChange,
  onShowMetrics,
  onResetLayout
}) => {
  return (
    <div className="flex gap-4 items-start">
      <div className="w-96">
        <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          Hospital
        </label>
        <select
          value={selectedHospital}
          onChange={(e) => onHospitalChange(e.target.value)}
          className="w-full px-4 py-2 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-healthcare-primary focus:border-transparent"
        >
          {hospitals.map((hospital) => (
            <option key={hospital} value={hospital}>
              {hospital}
            </option>
          ))}
        </select>
      </div>

      <div className="w-[220px]">
        <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          Process
        </label>
        <select
          value={selectedWorkflow}
          onChange={(e) => onWorkflowChange(e.target.value)}
          className="w-full px-4 py-2 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-healthcare-primary focus:border-transparent"
        >
          {workflows.map((workflow) => (
            <option key={workflow} value={workflow}>
              {workflow}
            </option>
          ))}
        </select>
      </div>

      <div className="flex-1">
        <label className="block text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          Time Range
        </label>
        <select
          value={selectedTimeRange}
          onChange={(e) => onTimeRangeChange(e.target.value)}
          className="w-full px-4 py-2 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-healthcare-primary focus:border-transparent"
        >
          {timeRanges.map((range) => (
            <option key={range} value={range}>
              {range}
            </option>
          ))}
        </select>
      </div>

      <div className="flex flex-col">
        <label className="block text-sm font-medium text-transparent select-none">
          Spacer
        </label>
        <div className="flex gap-4">
        <button
          onClick={onShowMetrics}
          className="px-4 py-2 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md shadow-sm hover:bg-healthcare-surface-hover dark:hover:bg-healthcare-surface-hover-dark transition-colors"
        >
          View Metrics
        </button>
        <button
          onClick={onResetLayout}
          className="px-4 py-2 bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-md shadow-sm hover:bg-healthcare-surface-hover dark:hover:bg-healthcare-surface-hover-dark transition-colors"
        >
          Reset Layout
        </button>
        </div>
      </div>
    </div>
  );
};

// Export the constants for reuse
export { hospitals, workflows, timeRanges };
export default ProcessSelector;
