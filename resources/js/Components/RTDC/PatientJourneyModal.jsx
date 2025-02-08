import React, { useEffect, useRef } from 'react';
import { Icon } from '@iconify/react';
import PatientJourney from './PatientJourney';
import Card from '@/Components/Dashboard/Card';

const PatientJourneyModal = ({ isOpen, onClose, patient }) => {
  const modalRef = useRef(null);

  // Handle focus trap and escape key
  useEffect(() => {
    if (!isOpen) return;

    const handleKeyDown = (e) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };

    // Focus the modal when it opens
    modalRef.current?.focus();
    document.addEventListener('keydown', handleKeyDown);

    return () => {
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const calculateLOS = () => {
    if (!patient?.admitDate) return 0;
    const admitDate = new Date(patient.admitDate);
    const currentDate = new Date();
    const diffTime = Math.abs(currentDate - admitDate);
    return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
  };

  const StatusPill = ({ label, value, color }) => (
    <span className={`px-3 py-1 rounded-full text-sm font-medium ${color}`}>
      {label}: {value}
    </span>
  );

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto">
      {/* Backdrop */}
      <div className="modal-backdrop" />

      {/* Modal */}
      <div className="flex min-h-screen items-center justify-center">
        <div 
          ref={modalRef}
          tabIndex={-1}
          className="modal-content bg-healthcare-surface dark:bg-healthcare-surface-dark"
        >
          {/* Header */}
          <div className="border-b border-gray-200 dark:border-gray-700 pb-4 mb-6">
            <div className="flex items-center justify-between">
              <div className="flex items-center space-x-3">
                <Icon 
                  icon="heroicons:clock" 
                  className="w-6 h-6 text-healthcare-primary dark:text-healthcare-primary-dark" 
                />
                <h2 className="text-xl font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                  Care Journey Timeline
                  {patient && (
                    <span className="text-sm font-normal text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark ml-2">
                      {patient.name} - Room {patient.room}
                    </span>
                  )}
                </h2>
              </div>
              <button
                onClick={onClose}
                className="healthcare-button-secondary"
                aria-label="Close modal"
              >
                <Icon icon="heroicons:x-mark" className="w-6 h-6" />
              </button>
            </div>

            <div className="mt-4 flex gap-4">
              <StatusPill 
                label="LOS"
                value={`${calculateLOS()} days`}
                color="bg-healthcare-primary/20 text-healthcare-primary dark:text-healthcare-primary-dark"
              />
              <StatusPill 
                label="Care Level"
                value={patient?.careJourney.careLevel}
                color="bg-healthcare-success/20 text-healthcare-success dark:text-healthcare-success-dark"
              />
              <StatusPill 
                label="Phase"
                value={patient?.careJourney.phase}
                color="bg-healthcare-warning/20 text-healthcare-warning dark:text-healthcare-warning-dark"
              />
            </div>
          </div>

          {/* Timeline Content */}
          <div className="space-y-6">
            <PatientJourney patient={patient} />
          </div>

          {/* Footer */}
          <div className="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <div className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Last updated: {new Date().toLocaleTimeString()}
            </div>
            <button
              onClick={onClose}
              className="healthcare-button-primary"
            >
              Close
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default PatientJourneyModal;
