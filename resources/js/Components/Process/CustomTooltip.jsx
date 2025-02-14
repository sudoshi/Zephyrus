import React from 'react';

const CustomTooltip = ({ active, payload, label }) => {
  if (!active || !payload || !payload.length) {
    return null;
  }

  return (
    <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark rounded-lg shadow-lg p-3">
      <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
        {label} Days Post-Discharge
      </p>
      <div className="space-y-1.5">
        {payload
          .sort((a, b) => b.value - a.value)
          .map((entry, index) => (
            <div key={index} className="flex items-center justify-between gap-4">
              <div className="flex items-center gap-2">
                <div 
                  className="w-2 h-2 rounded-full"
                  style={{ backgroundColor: entry.color }}
                />
                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  {entry.name}
                </span>
              </div>
              <span className={`text-sm font-medium ${
                entry.value >= 25 ? 'text-healthcare-critical dark:text-healthcare-critical-dark' :
                entry.value >= 20 ? 'text-healthcare-warning dark:text-healthcare-warning-dark' :
                'text-healthcare-success dark:text-healthcare-success-dark'
              }`}>
                {entry.value}%
              </span>
            </div>
          ))}
      </div>
    </div>
  );
};

export default CustomTooltip;
