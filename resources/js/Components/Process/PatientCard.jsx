import React from 'react';
import { 
  Clock, 
  AlertCircle, 
  CheckCircle2, 
  UserCog, 
  Stethoscope,
  Car,
  Home,
  ClipboardCheck,
  MessageSquare,
  AlertTriangle
} from 'lucide-react';

const PatientCard = ({ patient }) => {
  const getStatusColor = (status) => {
    switch (status) {
      case 'critical':
        return 'text-healthcare-critical dark:text-healthcare-critical-dark';
      case 'warning':
        return 'text-healthcare-warning dark:text-healthcare-warning-dark';
      case 'success':
        return 'text-healthcare-success dark:text-healthcare-success-dark';
      default:
        return 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark';
    }
  };

  const getActionIcon = (type) => {
    switch (type) {
      case 'orders': return ClipboardCheck;
      case 'consult': return Stethoscope;
      case 'transport': return Car;
      case 'homecare': return Home;
      case 'education': return MessageSquare;
      default: return AlertCircle;
    }
  };

  return (
    <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg p-4 space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h4 className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            {patient.name}
          </h4>
          <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            {patient.mrn} • {patient.age}y • {patient.gender} • Room {patient.room}
          </p>
        </div>
        <div className={`flex items-center gap-2 ${getStatusColor(patient.status)}`}>
          {patient.status === 'critical' ? (
            <AlertCircle className="h-5 w-5" />
          ) : patient.status === 'warning' ? (
            <AlertTriangle className="h-5 w-5" />
          ) : (
            <CheckCircle2 className="h-5 w-5" />
          )}
        </div>
      </div>

      {/* Clinical Info */}
      <div className="grid grid-cols-2 gap-4 text-sm">
        <div>
          <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Primary Diagnosis
          </p>
          <p className="font-medium">{patient.diagnosis}</p>
        </div>
        <div>
          <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            Expected Discharge
          </p>
          <div className="flex items-center gap-1">
            <Clock className="h-4 w-4" />
            <p className="font-medium">{patient.expectedDischarge}</p>
          </div>
        </div>
      </div>

      {/* Care Team */}
      <div className="flex items-center gap-2 text-sm">
        <UserCog className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
        <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          Care Team:
        </span>
        <span className="font-medium">{patient.careTeam.join(', ')}</span>
      </div>

      {/* Required Actions */}
      <div className="space-y-2">
        <h5 className="text-sm font-medium">Required Actions</h5>
        <div className="space-y-1">
          {patient.actions.map((action, index) => {
            const Icon = getActionIcon(action.type);
            return (
              <div 
                key={index}
                className={`flex items-center justify-between p-2 rounded-md ${
                  action.status === 'pending' 
                    ? 'bg-healthcare-warning/10 dark:bg-healthcare-warning-dark/10' 
                    : action.status === 'completed'
                    ? 'bg-healthcare-success/10 dark:bg-healthcare-success-dark/10'
                    : 'bg-healthcare-surface-secondary dark:bg-healthcare-surface-dark'
                }`}
              >
                <div className="flex items-center gap-2">
                  <Icon className={`h-4 w-4 ${getStatusColor(action.status)}`} />
                  <span className="text-sm">{action.description}</span>
                </div>
                <span className={`text-xs ${getStatusColor(action.status)}`}>
                  {action.owner}
                </span>
              </div>
            );
          })}
        </div>
      </div>

      {/* Barriers */}
      {patient.barriers.length > 0 && (
        <div className="space-y-2">
          <h5 className="text-sm font-medium">Barriers</h5>
          <div className="space-y-1">
            {patient.barriers.map((barrier, index) => (
              <div 
                key={index}
                className="flex items-center gap-2 text-sm text-healthcare-critical dark:text-healthcare-critical-dark"
              >
                <AlertCircle className="h-4 w-4" />
                <span>{barrier}</span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Quick Actions */}
      <div className="flex gap-2 pt-2">
        <button className="flex-1 px-3 py-1.5 text-sm font-medium rounded-md bg-healthcare-primary text-white hover:bg-healthcare-primary/90">
          View Details
        </button>
        <button className="px-3 py-1.5 text-sm font-medium rounded-md border border-healthcare-border hover:bg-healthcare-hover">
          Assign Task
        </button>
      </div>
    </div>
  );
};

export default PatientCard;
