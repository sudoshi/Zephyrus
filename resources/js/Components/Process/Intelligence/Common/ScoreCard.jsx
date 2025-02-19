import React from 'react';

const ScoreCard = ({ 
  title, 
  score, 
  maxScore, 
  icon: Icon,
  details = [],
  className = "",
  colorScheme = "primary" // primary, success, warning, critical
}) => {
  const colorClasses = {
    primary: "bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-primary dark:text-healthcare-primary-dark",
    success: "bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-success dark:text-healthcare-success-dark",
    warning: "bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-warning dark:text-healthcare-warning-dark",
    critical: "bg-healthcare-surface dark:bg-healthcare-surface-dark text-healthcare-critical dark:text-healthcare-critical-dark"
  };

  return (
    <div className={`healthcare-card ${colorClasses[colorScheme]} ${className}`}>
      <h3 className="font-bold flex items-center gap-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        {Icon && <Icon className="h-5 w-5" />}
        {title}
        {score !== undefined && maxScore !== undefined && (
          <span className={`ml-auto font-semibold ${colorClasses[colorScheme]}`}>
            {score}/{maxScore} points
          </span>
        )}
      </h3>
      {details.length > 0 && (
        <div className="mt-4 space-y-3">
          {details.map((detail, index) => (
            <div key={index} className="text-sm">
              {typeof detail === 'string' ? (
                <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{detail}</p>
              ) : (
                <div>
                  <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{detail.label}:</p>
                  <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{detail.value}</p>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export default ScoreCard;
