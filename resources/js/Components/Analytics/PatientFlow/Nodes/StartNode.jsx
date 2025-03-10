import React, { memo } from 'react';
import { Handle, Position } from 'reactflow';

const StartNode = ({ data }) => {
  return (
    <div className="px-4 py-2 shadow-md rounded-md bg-blue-100 dark:bg-blue-900 border border-blue-300 dark:border-blue-700">
      <div className="flex flex-col">
        <div className="font-bold text-sm text-center text-blue-800 dark:text-blue-200">
          {data.label}
        </div>
        <div className="flex justify-center items-center mt-2">
          <div className="flex flex-col items-center">
            <span className="text-xs text-blue-600 dark:text-blue-400">Count</span>
            <span className="text-sm font-medium text-blue-700 dark:text-blue-300">{data.count}</span>
          </div>
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

export default memo(StartNode);
