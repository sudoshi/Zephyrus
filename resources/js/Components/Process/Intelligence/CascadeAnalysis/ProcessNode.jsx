import React, { memo } from 'react';
import { Handle, Position } from 'reactflow';
import { AlertTriangle, Activity } from 'lucide-react';

const ProcessNode = ({ data, isConnectable }) => {
  const getStatusColor = () => {
    if (data.type === 'primary') {
      return 'bg-healthcare-primary/10 text-healthcare-primary';
    }
    const severity = data.severity || 0;
    if (severity > 0.8) return 'bg-healthcare-critical/10 text-healthcare-critical';
    if (severity > 0.6) return 'bg-healthcare-warning/10 text-healthcare-warning';
    return 'bg-healthcare-info/10 text-healthcare-info';
  };

  return (
    <div className="relative">
      <Handle
        type="target"
        position={Position.Top}
        isConnectable={isConnectable}
        className="w-2 h-2 !bg-healthcare-border dark:!bg-healthcare-border-dark"
      />
      <div className={`px-4 py-2 rounded-lg shadow-sm border border-healthcare-border dark:border-healthcare-border-dark ${
        data.type === 'primary' ? 'bg-white dark:bg-healthcare-background-dark' : 'bg-white dark:bg-healthcare-background-dark'
      }`}>
        <div className="flex items-center gap-2">
          <div className={`rounded-full p-1.5 ${getStatusColor()}`}>
            {data.type === 'primary' ? (
              <Activity className="h-4 w-4" />
            ) : (
              <AlertTriangle className="h-4 w-4" />
            )}
          </div>
          <div>
            <div className="font-medium text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              {data.label}
            </div>
            {data.type !== 'primary' && (
              <div className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                Impact: {Math.round((data.severity || 0) * 100)}%
              </div>
            )}
          </div>
        </div>
      </div>
      <Handle
        type="source"
        position={Position.Bottom}
        isConnectable={isConnectable}
        className="w-2 h-2 !bg-healthcare-border dark:!bg-healthcare-border-dark"
      />
    </div>
  );
};

export default memo(ProcessNode);
