import React from 'react';
import { Icon } from '@iconify/react';

export const StatusDot = ({ status, pulse = false }) => {
  const colors = {
    onTime: "bg-healthcare-success dark:bg-healthcare-success-dark",
    delayed: "bg-healthcare-error dark:bg-healthcare-error-dark",
    warning: "bg-healthcare-warning dark:bg-healthcare-warning-dark",
    completed: "bg-healthcare-info dark:bg-healthcare-info-dark",
    default: "bg-healthcare-text-secondary dark:bg-healthcare-text-secondary-dark"
  };

  return (
    <div
      className={`h-2 w-2 rounded-full ${colors[status] || colors.default} ${
        pulse ? "animate-pulse" : ""
      }`}
    />
  );
};

export const ProgressBar = ({ value, max, status, estimatedCompletion }) => {
  const percentage = (value / max) * 100;
  
  const getStatusColor = () => {
    switch (status) {
      case "delayed":
        return "bg-healthcare-error dark:bg-healthcare-error-dark";
      case "warning":
        return "bg-healthcare-warning dark:bg-healthcare-warning-dark";
      case "completed":
        return "bg-healthcare-success dark:bg-healthcare-success-dark";
      default:
        return percentage <= 33
          ? "bg-healthcare-info dark:bg-healthcare-info-dark"
          : percentage <= 66
          ? "bg-healthcare-primary dark:bg-healthcare-primary-dark"
          : "bg-healthcare-success dark:bg-healthcare-success-dark";
    }
  };

  const getStatusBg = () => {
    switch (status) {
      case "delayed":
        return "bg-healthcare-error-light dark:bg-healthcare-error-dark/20";
      case "warning":
        return "bg-healthcare-warning-light dark:bg-healthcare-warning-dark/20";
      case "completed":
        return "bg-healthcare-success-light dark:bg-healthcare-success-dark/20";
      default:
        return "bg-healthcare-background dark:bg-healthcare-background-dark";
    }
  };

  const getStatusText = () => {
    switch (status) {
      case "delayed":
        return "Behind Schedule";
      case "warning":
        return "At Risk";
      case "completed":
        return "Completed";
      default:
        return "On Track";
    }
  };

  const getStatusTextColor = () => {
    switch (status) {
      case "delayed":
        return "text-healthcare-error dark:text-healthcare-error-dark";
      case "warning":
        return "text-healthcare-warning dark:text-healthcare-warning-dark";
      case "completed":
        return "text-healthcare-success dark:text-healthcare-success-dark";
      default:
        return "text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark";
    }
  };

  return (
    <div className="space-y-1">
      <div className="flex items-center justify-between text-xs">
        <div className="flex items-center space-x-2">
          <StatusDot status={status} />
          <span className={getStatusTextColor()}>
            {getStatusText()}
          </span>
        </div>
        {estimatedCompletion && (
          <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Est. completion: {estimatedCompletion}
          </span>
        )}
      </div>
      <div className={`w-full h-1.5 ${getStatusBg()} rounded-full overflow-hidden transition-all duration-300`}>
        <div
          className={`h-full ${getStatusColor()} transition-all duration-300`}
          style={{ width: `${Math.min(percentage, 100)}%` }}
        />
      </div>
    </div>
  );
};

export const CaseStatusBadge = ({ status, showIcon = true }) => {
  const getStatusConfig = () => {
    switch (status.toLowerCase()) {
      case 'in_progress':
      case 'in progress':
        return {
          color: 'bg-healthcare-info-light dark:bg-healthcare-info-dark/20',
          text: 'text-healthcare-info dark:text-healthcare-info-dark',
          icon: 'heroicons:play',
          label: 'In Progress'
        };
      case 'delayed':
        return {
          color: 'bg-healthcare-error-light dark:bg-healthcare-error-dark/20',
          text: 'text-healthcare-error dark:text-healthcare-error-dark',
          icon: 'heroicons:clock',
          label: 'Delayed'
        };
      case 'completed':
        return {
          color: 'bg-healthcare-success-light dark:bg-healthcare-success-dark/20',
          text: 'text-healthcare-success dark:text-healthcare-success-dark',
          icon: 'heroicons:check-circle',
          label: 'Completed'
        };
      case 'cancelled':
        return {
          color: 'bg-healthcare-error-light dark:bg-healthcare-error-dark/20',
          text: 'text-healthcare-error dark:text-healthcare-error-dark',
          icon: 'heroicons:x-circle',
          label: 'Cancelled'
        };
      case 'scheduled':
        return {
          color: 'bg-healthcare-warning-light dark:bg-healthcare-warning-dark/20',
          text: 'text-healthcare-warning dark:text-healthcare-warning-dark',
          icon: 'heroicons:calendar',
          label: 'Scheduled'
        };
      default:
        return {
          color: 'bg-healthcare-text-secondary/10 dark:bg-healthcare-text-secondary-dark/20',
          text: 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark',
          icon: 'heroicons:question-mark-circle',
          label: status
        };
    }
  };

  const config = getStatusConfig();

  return (
    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.color} ${config.text}`}>
      {showIcon && <Icon icon={config.icon} className="w-3.5 h-3.5 mr-1" />}
      {config.label}
    </span>
  );
};
