import React from 'react';

const StatusDot = ({ status, pulse = false }) => {
  const getStatusColor = () => {
    switch (status) {
      case 'in_progress':
        return 'bg-healthcare-success dark:bg-healthcare-success-dark';
      case 'turnover':
        return 'bg-healthcare-warning dark:bg-healthcare-warning-dark';
      case 'delayed':
        return 'bg-healthcare-error dark:bg-healthcare-error-dark';
      case 'available':
        return 'bg-healthcare-info dark:bg-healthcare-info-dark';
      default:
        return 'bg-healthcare-text-secondary dark:bg-healthcare-text-secondary-dark';
    }
  };

  return (
    <div
      className={`h-2 w-2 rounded-full ${getStatusColor()} ${
        pulse ? 'animate-pulse' : ''
      } transition-colors duration-300`}
    />
  );
};

export default StatusDot;
