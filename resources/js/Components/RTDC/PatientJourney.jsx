import React from 'react';
import { Icon } from '@iconify/react';
import Card from '@/Components/Dashboard/Card';

const PatientJourney = ({ patient }) => {
  const journeyData = patient?.dischargePlan?.journeyMilestones || [];

  const patientInfo = {
    name: patient?.name || "John Doe",
    mrn: patient?.mrn || "123456",
    diagnosis: patient?.diagnosis || "Congestive Heart Failure Exacerbation",
    admitDate: patient?.admitDate ? new Date(patient.admitDate).toLocaleDateString() : "June 1, 2024",
    anticipatedDischarge: patient?.dischargePlan?.estimatedDischargeDate ? 
      new Date(patient.dischargePlan.estimatedDischargeDate).toLocaleDateString() : 
      "June 4, 2024"
  };

  // Calculate length of stay
  const calculateLOS = () => {
    if (!patient?.admitDate) return 0;
    const admitDate = new Date(patient.admitDate);
    const currentDate = new Date();
    const diffTime = Math.abs(currentDate - admitDate);
    return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
  };

  // Get phase color based on type and status
  const getPhaseColor = (type, isAlert, isAnticipated) => {
    if (isAlert) return 'text-healthcare-critical dark:text-healthcare-critical-dark';
    if (isAnticipated) return 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark';
    
    switch (type) {
      case 'admission':
        return 'text-healthcare-info dark:text-healthcare-info-dark';
      case 'milestone':
        return 'text-healthcare-warning dark:text-healthcare-warning-dark';
      case 'discharge':
        return 'text-healthcare-success dark:text-healthcare-success-dark';
      default:
        return 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark';
    }
  };

  return (
    <div className="healthcare-card">
      <div className="flex justify-between items-start mb-6">
        <div>
          <h3 className="text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {patientInfo.name}
          </h3>
          <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mt-1">
            MRN: {patientInfo.mrn} | Primary Diagnosis: {patientInfo.diagnosis}
          </p>
        </div>
        <div className="flex gap-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          <div className="flex items-center gap-1">
            <Icon icon="heroicons:calendar" className="w-4 h-4" />
            <span className="text-sm">LOS: {calculateLOS()} days</span>
          </div>
          <div className="flex items-center gap-1">
            <Icon icon="heroicons:clock" className="w-4 h-4" />
            <span className="text-sm">Updated: 2 hrs ago</span>
          </div>
        </div>
      </div>
      
      <div className="relative bg-healthcare-surface-secondary dark:bg-healthcare-surface-secondary-dark rounded-2xl p-8 mt-4">
        {/* Long pill timeline container */}
        <div className="relative min-h-[160px] bg-healthcare-background/50 dark:bg-healthcare-background-dark/50 rounded-full p-6 shadow-md">
          {/* Progress gradient */}
          <div 
            className="absolute top-0 left-0 h-full rounded-full bg-gradient-to-r from-healthcare-info/20 via-healthcare-warning/20 to-healthcare-success/20 dark:from-healthcare-info-dark/20 dark:via-healthcare-warning-dark/20 dark:to-healthcare-success-dark/20"
            style={{ 
              width: `${Math.min((new Date() - new Date(patient?.admitDate)) / (1000 * 60 * 60 * 24) / 7 * 100, 100)}%`,
              transition: 'width 0.5s ease-in-out'
            }}
          />
          
          {/* Timeline events */}
          <div 
            className="relative flex justify-between items-center"
            role="list"
            aria-label="Care journey timeline"
          >
            {journeyData.map((event, index) => (
              <div 
                key={event.id} 
                className="relative flex flex-col items-center min-w-[120px]"
                role="listitem"
                aria-label={`${event.title} - ${event.description}`}
              >
                {/* Event dot with shadow */}
                <div className="relative">
                  <div className={`absolute inset-0 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-full blur-[2px] opacity-50`} />
                  <div className="relative z-10 p-2">
                    {event.isAlert ? (
                      <Icon 
                        icon="heroicons:exclamation-circle" 
                        className={`w-8 h-8 ${getPhaseColor(event.type, event.isAlert, event.isAnticipated)}`}
                        aria-label="Alert"
                      />
                    ) : (
                      <Icon 
                        icon="heroicons:circle" 
                        className={`w-8 h-8 ${getPhaseColor(event.type, event.isAlert, event.isAnticipated)}`}
                        style={{ 
                          fill: event.type === 'milestone' ? 'transparent' : 'currentColor',
                          stroke: 'currentColor',
                          strokeWidth: event.type === 'milestone' ? 2 : 0
                        }}
                        aria-hidden="true"
                      />
                    )}
                  </div>
                </div>

                {/* Event content - alternating top/bottom */}
                <div 
                  className={`absolute w-32 ${index % 2 === 0 ? '-top-20' : 'top-16'}`}
                  aria-hidden={false}
                >
                  <div className="flex flex-col items-center text-center">
                    <span className={`font-semibold text-sm ${getPhaseColor(event.type, event.isAlert, event.isAnticipated)}`}>
                      {event.title}
                    </span>
                    <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {event.time}
                    </span>
                    <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                      {event.date}
                    </span>
                    <span className="text-xs text-healthcare-text-tertiary dark:text-healthcare-text-tertiary-dark mt-1">
                      {event.description}
                    </span>
                  </div>
                </div>

                {/* Phase separator line */}
                {index < journeyData.length - 1 && (
                  <div className="absolute top-1/2 left-[60px] right-0 h-px bg-healthcare-border dark:bg-healthcare-border-dark transform -translate-y-1/2" />
                )}
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
};

export default PatientJourney;
