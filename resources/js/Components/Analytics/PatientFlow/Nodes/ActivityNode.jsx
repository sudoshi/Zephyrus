import React, { memo } from 'react';
import { Handle, Position } from 'reactflow';

const ActivityNode = ({ data }) => {
  return (
    <div className="px-4 py-2 shadow-md rounded-md bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
      <Handle
        type="target"
        position={Position.Left}
        className="w-2 h-2 !bg-blue-500 dark:!bg-blue-400"
      />
      <div className="flex flex-col">
        <div className="font-bold text-sm text-center text-gray-800 dark:text-gray-200">
          {data.label}
        </div>
        <div className="flex justify-center items-center mt-2 space-x-4">
          <div className="flex flex-col items-center">
            <span className="text-xs text-gray-500 dark:text-gray-400">Count</span>
            <span className="text-sm font-medium text-gray-700 dark:text-gray-300">{data.count}</span>
          </div>
          {data.avgDuration && (
            <div className="flex flex-col items-center">
              <span className="text-xs text-gray-500 dark:text-gray-400">Avg. Time</span>
              <span className="text-sm font-medium text-gray-700 dark:text-gray-300">{data.avgDuration}</span>
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
