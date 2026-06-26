import React, { memo } from 'react';
import { Handle, Position } from 'reactflow';

const EndNode = ({ data }) => {
  return (
    <div className="px-4 py-2 shadow-md rounded-md bg-healthcare-success/10 dark:bg-healthcare-success-dark/20 border border-healthcare-success/30 dark:border-healthcare-success-dark/40">
      <Handle
        type="target"
        position={Position.Left}
        className="w-2 h-2 !bg-green-500 dark:!bg-green-400"
      />
      <div className="flex flex-col">
        <div className="font-semibold text-sm text-center text-healthcare-success dark:text-healthcare-success-dark">
          {data.label}
        </div>
        <div className="flex justify-center items-center mt-2">
          <div className="flex flex-col items-center">
            <span className="text-xs text-healthcare-success dark:text-healthcare-success-dark">Count</span>
            <span className="text-sm font-medium text-healthcare-success dark:text-healthcare-success-dark">{data.count}</span>
          </div>
        </div>
      </div>
    </div>
  );
};

export default memo(EndNode);
