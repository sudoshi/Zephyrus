import React from 'react';

const Progress = ({ value }) => {
  return (
    <div className="w-full bg-healthcare-border rounded-full dark:bg-healthcare-border-dark">
      <div
        className="bg-healthcare-primary text-xs font-medium text-white text-center p-0.5 leading-none rounded-full dark:bg-healthcare-primary-dark"
        style={{ width: `${value}%` }}
      >
        {value}%
      </div>
    </div>
  );
};

export default Progress;
