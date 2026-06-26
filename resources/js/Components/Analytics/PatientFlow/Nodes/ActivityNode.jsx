import React, { memo } from 'react';
import { Handle, Position } from 'reactflow';

const ActivityNode = ({ data }) => {
  return (
    <div className="px-4 py-2 shadow-md rounded-md bg-healthcare-surface dark:bg-healthcare-surface-dark border border-healthcare-border dark:border-healthcare-border-dark">
      <Handle
        type="target"
        position={Position.Left}
        className="w-2 h-2 !bg-blue-500 dark:!bg-blue-400"
      />
      <div className="flex flex-col">
        <div className="font-semibold text-sm text-center text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {data.label}
        </div>
        <div className="flex justify-center items-center mt-2 space-x-4">
          <div className="flex flex-col items-center">
            <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Count</span>
            <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{data.count}</span>
          </div>
          {data.avgDuration && (
            <div className="flex flex-col items-center">
              <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Avg. Time</span>
              <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{data.avgDuration}</span>
            </div>
          )}
        </div>
      </div>
      <Handle
        type="source"
        position={Position.Right}
        className="w-2 h-2 !bg-blue-500 dark:!bg-blue-400"
      />
    </div>
  );
};

export default memo(ActivityNode);
