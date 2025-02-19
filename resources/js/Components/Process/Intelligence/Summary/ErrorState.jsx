import React from 'react';
import { AlertOctagon, RefreshCw } from 'lucide-react';

const ErrorState = ({ message, onRetry }) => {
  return (
    <div className="flex flex-col items-center justify-center h-64 space-y-4">
      <div className="rounded-full bg-healthcare-critical/10 p-4">
        <AlertOctagon className="h-8 w-8 text-healthcare-critical dark:text-healthcare-critical-dark" />
      </div>
      <div className="text-center">
        <h4 className="font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark mb-2">
          Unable to Load Data
        </h4>
        <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mb-4">
          {message || 'An error occurred while loading the data. Please try again.'}
        </p>
        <button
          onClick={onRetry}
          className="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-healthcare-primary dark:bg-healthcare-primary-dark text-white hover:bg-healthcare-primary-hover dark:hover:bg-healthcare-primary-hover-dark healthcare-transition"
        >
          <RefreshCw className="h-4 w-4" />
          Retry
        </button>
      </div>
    </div>
  );
};

export default ErrorState;
