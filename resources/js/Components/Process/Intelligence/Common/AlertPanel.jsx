import React from 'react';
import { AlertTriangle } from 'lucide-react';

const AlertPanel = ({ 
  title = "Critical Alerts",
  alerts = [],
  type = "critical", // critical, warning, info
  icon: CustomIcon,
  className = ""
}) => {
  const typeStyles = {
    critical: {
      wrapper: "bg-healthcare-surface dark:bg-healthcare-surface-dark",
      title: "text-healthcare-critical dark:text-healthcare-critical-dark",
      alert: "text-healthcare-critical dark:text-healthcare-critical-dark",
      icon: AlertTriangle
    },
    warning: {
      wrapper: "bg-healthcare-surface dark:bg-healthcare-surface-dark",
      title: "text-healthcare-warning dark:text-healthcare-warning-dark",
      alert: "text-healthcare-warning dark:text-healthcare-warning-dark",
      icon: AlertTriangle
    },
    info: {
      wrapper: "bg-healthcare-surface dark:bg-healthcare-surface-dark",
      title: "text-healthcare-info dark:text-healthcare-info-dark",
      alert: "text-healthcare-info dark:text-healthcare-info-dark",
      icon: AlertTriangle
    }
  };

  const styles = typeStyles[type];
  const Icon = CustomIcon || styles.icon;

  return (
    <div className={`healthcare-card ${styles.wrapper} ${className}`}>
      <h3 className={`font-bold ${styles.title} flex items-center gap-2`}>
        <Icon className="h-5 w-5" />
        {title}
      </h3>
      <div className="mt-4 space-y-3">
        {alerts.length > 0 ? (
          alerts.map((alert, index) => (
            <div key={index} className="text-sm healthcare-panel">
              {typeof alert === 'string' ? (
                <p className={styles.alert}>{alert}</p>
              ) : (
                <div>
                  <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {alert.title}:
                  </p>
                  <p className={styles.alert}>
                    {alert.message}
                    {alert.value && (
                      <span className="font-semibold ml-1 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                        ({alert.value})
                      </span>
                    )}
                  </p>
                </div>
              )}
            </div>
          ))
        ) : (
          <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            No alerts to display
          </p>
        )}
      </div>
    </div>
  );
};

export default AlertPanel;
