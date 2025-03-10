import React, { memo } from 'react';
import { Handle, Position } from 'reactflow';

const EndNode = ({ data }) => {
  return (
    <div className="px-4 py-2 shadow-md rounded-md bg-green-100 dark:bg-green-900 border border-green-300 dark:border-green-700">
      <Handle
        type="target"
        position={Position.Left}
        className="w-2 h-2 !bg-green-500 dark:!bg-green-400"
      />
      <div className="flex flex-col">
        <div className="font-bold text-sm text-center text-green-800 dark:text-green-200">
          {data.label}
        </div>
        <div className="flex justify-center items-center mt-2">
          <div className="flex flex-col items-center">
            <span className="text-xs text-green-600 dark:text-green-400">Count</span>
            <span className="text-sm font-medium text-green-700 dark:text-green-300">{data.count}</span>
          </div>
        </div>
      </div>
    </div>
  );
};

export default memo(EndNode);
