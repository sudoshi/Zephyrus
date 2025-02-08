import React from 'react';
import { Icon } from '@iconify/react';

const CareJourneySummary = ({ patient, onClick }) => {
  // Calculate current milestone
  const getCurrentMilestone = () => {
    const now = new Date();
    const admitDate = new Date(patient.admitDate);
    const dischargeDate = new Date(patient.dischargePlan.estimatedDischargeDate);
    const totalDays = Math.ceil((dischargeDate - admitDate) / (1000 * 60 * 60 * 24));
    const currentDay = Math.ceil((now - admitDate) / (1000 * 60 * 60 * 24));
    const progress = (currentDay / totalDays) * 100;

    // Return appropriate milestone based on progress
    if (progress < 25) return { phase: 'Initial Assessment', icon: 'clipboard-document-check', color: 'text-healthcare-info' };
    if (progress < 50) return { phase: 'Treatment', icon: 'heart', color: 'text-healthcare-warning' };
    if (progress < 75) return { phase: 'Recovery', icon: 'arrow-trending-up', color: 'text-healthcare-success' };
    return { phase: 'Discharge Planning', icon: 'arrow-right-on-rectangle', color: 'text-healthcare-success' };
  };

  const currentMilestone = getCurrentMilestone();
  const daysUntilDischarge = Math.ceil(
    (new Date(patient.dischargePlan.estimatedDischargeDate) - new Date()) / (1000 * 60 * 60 * 24)
  );

  const progressPercentage = Math.min(
    (new Date() - new Date(patient.admitDate)) / (1000 * 60 * 60 * 24) / 7 * 100,
    100
  );

  return (
    <div 
      className="flex flex-col space-y-3"
      role="region"
      aria-label="Care journey summary"
    >
      {/* Current Phase */}
      <div className="flex items-center gap-2">
        <Icon 
          icon={`heroicons:${currentMilestone.icon}`}
          className={`w-5 h-5 ${currentMilestone.color} dark:${currentMilestone.color}-dark`}
          aria-hidden="true"
        />
        <span className="font-medium text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {currentMilestone.phase}
        </span>
      </div>

      {/* Long Pill Progress Container */}
      <div className="relative h-12 bg-healthcare-background/50 dark:bg-healthcare-background-dark/50 rounded-full shadow-sm overflow-hidden">
        {/* Background gradient */}
        <div className="absolute inset-0 bg-gradient-to-r from-healthcare-info/10 via-healthcare-warning/10 to-healthcare-success/10 dark:from-healthcare-info-dark/10 dark:via-healthcare-warning-dark/10 dark:to-healthcare-success-dark/10" />
        
        {/* Progress gradient */}
        <div 
          className="absolute top-0 left-0 h-full bg-gradient-to-r from-healthcare-info via-healthcare-warning to-healthcare-success dark:from-healthcare-info-dark dark:via-healthcare-warning-dark dark:to-healthcare-success-dark opacity-20"
          style={{ 
            width: `${progressPercentage}%`,
            transition: 'width 0.5s ease-in-out'
          }}
        />

        {/* Phase markers */}
        <div className="absolute inset-0 flex justify-between items-center px-4">
          {['Initial', 'Treatment', 'Recovery', 'Discharge'].map((phase, index) => (
            <div 
              key={phase}
              className={`flex flex-col items-center ${
                (progressPercentage >= index * 33.33) 
                  ? 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark' 
                  : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
              }`}
            >
              <div className="w-2 h-2 rounded-full bg-current" />
              <span className="text-xs mt-1">{phase}</span>
            </div>
          ))}
        </div>
      </div>

      {/* Time Indicators */}
      <div className="flex justify-between text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        <span>
          Admitted {new Date(patient.admitDate).toLocaleDateString()}
        </span>
        <span>
          {daysUntilDischarge} days until discharge
        </span>
      </div>

      {/* View Details Button */}
      <button
        onClick={onClick}
        className="healthcare-button-secondary mt-2 text-sm flex items-center gap-1 justify-center w-full"
        aria-label="View detailed care journey"
      >
        <Icon icon="heroicons:eye" className="w-4 h-4" aria-hidden="true" />
        View Journey
      </button>
    </div>
  );
};

export default CareJourneySummary;
