import React, { memo } from 'react';
import { Handle, Position } from 'reactflow';

const StartNode = ({ data }) => {
  return (
    <div className="px-4 py-2 shadow-md rounded-md bg-healthcare-info/10 dark:bg-healthcare-info-dark/20 border border-healthcare-info dark:border-healthcare-info-dark">
      <div className="flex flex-col">
        <div className="font-semibold text-sm text-center text-healthcare-info dark:text-healthcare-info-dark">
          {data.label}
        </div>
        <div className="flex justify-center items-center mt-2">
          <div className="flex flex-col items-center">
            <span className="text-xs text-healthcare-info dark:text-healthcare-info-dark">Count</span>
            <span className="text-sm font-medium text-healthcare-info dark:text-healthcare-info-dark">{data.count}</span>
          </div>
        </div>
      </div>
      <Handle
        type="source"
        position={Position.Right}
        className="w-2 h-2 !bg-healthcare-info dark:!bg-healthcare-info-dark"
      />
    </div>
  );
};

export default memo(StartNode);
