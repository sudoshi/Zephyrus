import React, { useRef } from 'react';
import { TrendingUp, TrendingDown, AlertCircle, Users, DoorOpen, Stethoscope, HelpCircle } from 'lucide-react';
import ConfirmationDialog from './ConfirmationDialog';
import ActionFeedback from './ActionFeedback';
import StatusTooltip from './StatusTooltip';
import  { useResourceAction } from '@/hooks/useResourceAction.js';
import  { useKeyboardNavigation } from '@/hooks/useKeyboardNavigation.js';
import getResourceTooltips from './getResourceTooltips';

const ResourceOverview = ({ resourceData, predictions, onAction }) => {
  const {
    confirmAction,
    getConfirmationProps,
    getFeedbackProps
  } = useResourceAction();

  const actionsContainerRef = useRef(null);
  useKeyboardNavigation(actionsContainerRef, 'button[data-action]', (element) => {
    const actionIndex = parseInt(element.getAttribute('data-action-index'));
    const resourceKey = element.getAttribute('data-resource-key');
    const resource = resourceTypes.find(r => r.key === resourceKey);
    if (resource && resource.quickActions[actionIndex]) {
      confirmAction(resource.quickActions[actionIndex]);
    }
  });

  const getResourceStatus = (type, data) => {
    const utilization = type === 'space' 
      ? data.occupied / data.capacity 
      : data.assigned / data.required;
    
    const thresholds = resourceData[type].thresholds;
    
    return {
      status: utilization >= thresholds.critical 
        ? 'critical'
        : utilization >= thresholds.high
        ? 'high'
        : utilization >= thresholds.medium
        ? 'medium'
        : 'normal',
      percentage: Math.round(utilization * 100),
      trend: predictions.resourceUtilization.nextHour[data.key]?.assigned > data.assigned
        ? { icon: TrendingUp, label: 'Increasing', color: 'text-healthcare-critical' }
        : { icon: TrendingDown, label: 'Decreasing', color: 'text-healthcare-success' }
    };
  };

  const resourceTypes = [
    {
      key: 'nurses',
      type: 'staffing',
      label: 'Nursing Staff',
      icon: Users,
      data: resourceData.staffing.current.nurses,
      quickActions: [
        { 
          label: 'Request Additional Staff',
          handler: () => onAction?.('request_staff'),
          confirmTitle: 'Request Additional Staff',
          confirmMessage: 'This will notify the staffing coordinator to allocate additional nursing staff. Continue?',
          confirmType: 'warning'
        },
        {
          label: 'Adjust Shift Schedule',
          handler: () => onAction?.('adjust_schedule'),
          confirmTitle: 'Adjust Shift Schedule',
          confirmMessage: 'This will modify the current shift assignments. Are you sure?',
          confirmType: 'warning'
        }
      ]
    },
    {
      key: 'physicians',
      type: 'staffing',
      label: 'Physicians',
      icon: Stethoscope,
      data: resourceData.staffing.current.physicians,
      quickActions: [
        {
          label: 'Page On-Call Doctor',
          handler: () => onAction?.('page_doctor'),
          confirmTitle: 'Page On-Call Doctor',
          confirmMessage: 'This will send an immediate alert to the on-call physician. Proceed?',
          confirmType: 'warning'
        },
        {
          label: 'View Coverage Schedule',
          handler: () => onAction?.('view_schedule'),
          confirmTitle: 'View Coverage Schedule',
          confirmMessage: 'Open the physician coverage schedule?'
        }
      ]
    },
    {
      key: 'rooms',
      type: 'space',
      label: 'Patient Rooms',
      icon: DoorOpen,
      data: resourceData.space.current.rooms,
      quickActions: [
        {
          label: 'View Room Status',
          handler: () => onAction?.('view_rooms'),
          confirmTitle: 'View Room Status',
          confirmMessage: 'Open detailed room status view?'
        },
        {
          label: 'Expedite Turnover',
          handler: () => onAction?.('expedite_turnover'),
          confirmTitle: 'Expedite Room Turnover',
          confirmMessage: 'This will prioritize cleaning and preparation of available rooms. Continue?',
          confirmType: 'warning'
        }
      ]
    }
  ];

  const confirmationProps = getConfirmationProps();
  const feedbackProps = getFeedbackProps();

  return (
    <>
      {confirmationProps && <ConfirmationDialog {...confirmationProps} />}
      {feedbackProps && <ActionFeedback {...feedbackProps} />}

      <div className="grid grid-cols-3 gap-6" ref={actionsContainerRef}>
        {resourceTypes.map(resource => {
          const status = getResourceStatus(resource.type, { ...resource.data, key: resource.key });
          const Icon = resource.icon;
          const TrendIcon = status.trend.icon;
          const tooltips = getResourceTooltips(resource, status);
          
          return (
            <div 
              key={resource.key} 
              className="healthcare-card p-6"
              role="region"
              aria-label={`${resource.label} status and actions`}
            >
              <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-3">
                  <StatusTooltip content={tooltips.status}>
                    <div className={`rounded-full p-3 ${
                      status.status === 'critical'
                        ? 'bg-healthcare-critical/10 text-healthcare-critical'
                        : status.status === 'high'
                        ? 'bg-healthcare-warning/10 text-healthcare-warning'
                        : 'bg-healthcare-success/10 text-healthcare-success'
                    }`}>
                      <Icon className="h-6 w-6" aria-hidden="true" />
                    </div>
                  </StatusTooltip>
                  <div>
                    <div className="flex items-center gap-2">
                      <h3 className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        {resource.label}
                      </h3>
                      <StatusTooltip content={tooltips.utilization}>
                        <HelpCircle className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                      </StatusTooltip>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className={`text-sm font-medium ${
                        status.status === 'critical'
                          ? 'text-healthcare-critical'
                          : status.status === 'high'
                          ? 'text-healthcare-warning'
                          : 'text-healthcare-success'
                      }`}>
                        {status.percentage}% Utilized
                      </span>
                      <StatusTooltip content={tooltips.trend}>
                        <div className={`flex items-center gap-1 ${status.trend.color}`} role="status">
                          <TrendIcon className="h-4 w-4" aria-hidden="true" />
                          <span className="text-xs">{status.trend.label}</span>
                        </div>
                      </StatusTooltip>
                    </div>
                  </div>
                </div>
                {status.status === 'critical' && (
                  <div 
                    className="rounded-full bg-healthcare-critical/10 p-2"
                    role="alert"
                    aria-label="Critical utilization level"
                  >
                    <AlertCircle className="h-5 w-5 text-healthcare-critical" />
                  </div>
                )}
              </div>

              <div className="space-y-3 mb-4">
                <div className="flex justify-between text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                  <span>Current</span>
                  <span>{resource.type === 'space' ? resource.data.occupied : resource.data.assigned} / {resource.type === 'space' ? resource.data.capacity : resource.data.required}</span>
                </div>
                <div 
                  className="h-2 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-full overflow-hidden"
                  role="progressbar"
                  aria-valuenow={status.percentage}
                  aria-valuemin="0"
                  aria-valuemax="100"
                  aria-label={`${resource.label} utilization`}
                >
                  <div 
                    className={`h-full rounded-full transition-all ${
                      status.status === 'critical'
                        ? 'bg-healthcare-critical'
                        : status.status === 'high'
                        ? 'bg-healthcare-warning'
                        : 'bg-healthcare-success'
                    }`}
                    style={{ width: `${status.percentage}%` }}
                  />
                </div>
              </div>

              <div 
                className="flex gap-2"
                role="group"
                aria-label={`${resource.label} quick actions`}
              >
                {resource.quickActions.map((action, index) => (
                  <StatusTooltip
                    key={index}
                    content={tooltips.actions[Object.keys(tooltips.actions)[index]]}
                    position="bottom"
                  >
                    <button
                      onClick={() => confirmAction(action)}
                      data-action
                      data-action-index={index}
                      data-resource-key={resource.key}
                      className="flex-1 px-3 py-2 text-sm font-medium rounded-md bg-healthcare-surface hover:bg-healthcare-surface-hover dark:bg-healthcare-surface-dark dark:hover:bg-healthcare-surface-hover-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark healthcare-transition focus:outline-none focus:ring-2 focus:ring-healthcare-primary focus:ring-offset-2 dark:focus:ring-offset-healthcare-background-dark"
                    >
                      {action.label}
                    </button>
                  </StatusTooltip>
                ))}
              </div>
            </div>
          );
        })}
      </div>
    </>
  );
};

export default ResourceOverview;
