import React from 'react';

const DropLightPanel = ({ title, children, darkMode = false, className = '' }) => {
  const baseClasses = `
    rounded-lg
    backdrop-blur-sm
    transition-all duration-300
    ${darkMode ? 
      'bg-healthcare-surface-dark border-healthcare-border-dark shadow-blue-dark text-healthcare-text-primary-dark' : 
      'bg-healthcare-surface border-healthcare-border shadow-blue-light text-healthcare-text-primary'
    }
    ${className}
  `;

  return (
    <div className={baseClasses}>
      {title && (
        <div className={`p-4 border-b ${darkMode ? 'border-healthcare-border-dark' : 'border-healthcare-border'}`}>
          <h2 className="text-lg font-semibold">
            {title}
          </h2>
        </div>
      )}
      <div className="p-4">
        {children}
      </div>
    </div>
  );
};

export default DropLightPanel;
