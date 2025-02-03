import React from 'react';

const Alert = ({ children, className = '', ...props }) => {
  return (
    <div
      role="alert"
      className={`relative w-full rounded-lg border p-4 ${className}`}
      {...props}
    >
      {children}
    </div>
  );
};

const AlertDescription = ({ children, className = '', ...props }) => {
  return (
    <div
      className={`text-sm [&_p]:leading-relaxed ${className}`}
      {...props}
    >
      {children}
    </div>
  );
};

export { Alert, AlertDescription };
