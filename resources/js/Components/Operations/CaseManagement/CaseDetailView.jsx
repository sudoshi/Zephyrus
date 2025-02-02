import React from 'react';
import { Icon } from '@iconify/react';
import { StatusDot, ProgressBar, CaseStatusBadge } from './StatusIndicator';
import Modal from '@/Components/Common/Modal';

const TimeDisplay = ({ time, isOverdue = false }) => (
  <span className={`font-mono ${
    isOverdue 
      ? "text-healthcare-error dark:text-healthcare-error-dark" 
      : "text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
  }`}>
    {time}
  </span>
);

const InfoSection = ({ title, icon, children, className = "" }) => (
  <div className={`bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg p-4 ${className}`}>
    <h3 className="font-semibold mb-4 flex items-center">
      <Icon icon={icon} className="w-5 h-5 mr-2 text-healthcare-info dark:text-healthcare-info-dark" />
      {title}
    </h3>
    {children}
  </div>
);

const CaseDetailView = ({ caseData, measurements = [], onClose }) => {
  if (!caseData) return null;

  const {
    patient,
    procedure,
    provider,
    specialty,
    status,
    phase,
    location,
    startTime,
    expectedEndTime,
    expectedDuration,
    elapsed,
    staff,
    resources,
    notes,
    alerts
  } = caseData;

  const modalContent = (
    <div className="space-y-6">
      {/* Header Section */}
      <div className="flex justify-between items-start">
        <div>
          <h2 className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {procedure}
          </h2>
          <div className="flex items-center mt-2 space-x-2">
            <CaseStatusBadge status={status} />
            <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              {phase}
            </span>
          </div>
        </div>
        <div className="bg-healthcare-info bg-opacity-10 dark:bg-opacity-20 p-4 rounded-lg">
          <div className="grid grid-cols-2 gap-2 text-sm">
            <span className="font-medium text-healthcare-info dark:text-healthcare-info-dark">Patient:</span>
            <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{patient}</span>
            <span className="font-medium text-healthcare-info dark:text-healthcare-info-dark">Provider:</span>
            <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{provider}</span>
          </div>
        </div>
      </div>

      {/* Timeline Section */}
      <InfoSection title="Timeline" icon="heroicons:clock">
        <div className="relative">
          <div className="h-2 bg-healthcare-border dark:bg-healthcare-border-dark rounded-full"></div>
          <div 
            className={`h-2 rounded-full absolute top-0 left-0 ${
              status === 'delayed' 
                ? 'bg-healthcare-error dark:bg-healthcare-error-dark' 
                : 'bg-healthcare-info dark:bg-healthcare-info-dark'
            }`}
            style={{ width: `${(elapsed / expectedDuration) * 100}%` }}
          ></div>
          <div className="flex justify-between mt-4">
            <div className="text-center">
              <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Start</span>
              <TimeDisplay time={startTime} />
            </div>
            <div className="text-center">
              <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Expected End</span>
              <TimeDisplay 
                time={expectedEndTime} 
                isOverdue={status === 'delayed'}
              />
            </div>
          </div>
        </div>
      </InfoSection>

      {/* Staff & Resources Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <InfoSection title="Staff" icon="heroicons:users">
          <div className="space-y-3">
            {staff?.map((member, index) => (
              <div key={index} className="flex items-center justify-between">
                <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{member.name}</span>
                <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{member.role}</span>
              </div>
            ))}
          </div>
        </InfoSection>

        <InfoSection title="Resources" icon="heroicons:cube">
          <div className="space-y-3">
            {resources?.map((resource, index) => (
              <div key={index} className="flex items-center justify-between">
                <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{resource.name}</span>
                <StatusDot status={resource.status} />
              </div>
            ))}
          </div>
        </InfoSection>
      </div>

      {/* Notes & Alerts */}
      {(notes || alerts) && (
        <InfoSection title="Additional Information" icon="heroicons:information-circle">
          {notes && (
            <div className="mb-4">
              <h4 className="text-sm font-medium mb-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Notes</h4>
              <p className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{notes}</p>
            </div>
          )}
          {alerts && alerts.length > 0 && (
            <div>
              <h4 className="text-sm font-medium mb-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Alerts</h4>
              <div className="space-y-2">
                {alerts.map((alert, index) => (
                  <div key={index} className="flex items-center text-sm text-healthcare-error dark:text-healthcare-error-dark">
                    <Icon icon="heroicons:exclamation-circle" className="w-4 h-4 mr-2" />
                    {alert}
                  </div>
                ))}
              </div>
            </div>
          )}
        </InfoSection>
      )}

      {/* Measurements Table */}
      {measurements.length > 0 && (
        <InfoSection title="Measurements" icon="heroicons:chart-bar">
          <div className="overflow-x-auto">
            <table className="min-w-full border text-sm">
              <thead className="bg-healthcare-background dark:bg-healthcare-background-dark">
                <tr>
                  <th className="p-2 border-b">Time</th>
                  <th className="p-2 border-b">HR</th>
                  <th className="p-2 border-b">BP</th>
                  <th className="p-2 border-b">SpO2</th>
                  <th className="p-2 border-b">Temp</th>
                  <th className="p-2 border-b">Notes</th>
                </tr>
              </thead>
              <tbody>
                {measurements.map((m) => (
                  <tr key={m.measurement_id} className="border-b">
                    <td className="p-2">{new Date(m.Timestamp).toLocaleTimeString()}</td>
                    <td className="p-2">{m.HR}</td>
                    <td className="p-2">{`${m.SBP}/${m.DBP}`}</td>
                    <td className="p-2">{m.SpO2}</td>
                    <td className="p-2">{m.Temp?.toFixed(1)}</td>
                    <td className="p-2">{m.notes}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </InfoSection>
      )}
    </div>
  );

  return (
    <Modal
      open={!!caseData}
      onClose={onClose}
      maxWidth="5xl"
    >
      {modalContent}
    </Modal>
  );
};

export default CaseDetailView;
