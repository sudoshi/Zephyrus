import React from 'react';
import { Icon } from '@iconify/react';
import StatusDot from './StatusDot';

const RoomStatusCard = ({ room, onClick }) => {
  const getProgressBarColor = () => {
    if (room.status === 'delayed') return 'bg-healthcare-error dark:bg-healthcare-error-dark';
    if (room.timeRemaining <= 30) return 'bg-healthcare-warning dark:bg-healthcare-warning-dark';
    return 'bg-healthcare-success dark:bg-healthcare-success-dark';
  };

  const calculateProgress = () => {
    if (!room.currentCase) return 0;
    const elapsed = room.currentCase.elapsed;
    const total = room.currentCase.expectedDuration;
    return Math.min((elapsed / total) * 100, 100);
  };

  return (
    <div 
      onClick={onClick}
      className="border border-healthcare-border dark:border-healthcare-border-dark rounded-lg p-4 bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-all duration-300 cursor-pointer"
    >
      <div className="flex items-center justify-between mb-2">
        <span className="font-semibold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          Room {room.number}
        </span>
        <div className="flex items-center space-x-2">
          <StatusDot 
            status={room.status} 
            pulse={room.status === 'in_progress' || room.status === 'delayed'}
          />
          <span className={`text-xs font-medium ${
            room.status === 'delayed' 
              ? 'text-healthcare-error dark:text-healthcare-error-dark' 
              : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'
          }`}>
            {room.status === 'in_progress' ? 'In Progress' :
             room.status === 'turnover' ? 'Turnover' :
             room.status === 'delayed' ? 'Delayed' : 'Available'}
          </span>
        </div>
      </div>

      {room.currentCase ? (
        <>
          <div className="space-y-1 mb-3">
            <div className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              <p className="font-medium">{room.currentCase.procedure}</p>
              <p className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                {room.currentCase.patient}
              </p>
            </div>
            <div className="flex items-center text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              <Icon icon="heroicons:user" className="w-4 h-4 mr-1" />
              <span>{room.currentCase.provider}</span>
            </div>
          </div>

          <div className="space-y-1">
            <div className="flex justify-between text-xs">
              <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
                Progress
              </span>
              <span className={room.status === 'delayed' ? 'text-healthcare-error dark:text-healthcare-error-dark' : 'text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark'}>
                {room.timeRemaining} min remaining
              </span>
            </div>
            <div className="h-1.5 bg-healthcare-background dark:bg-healthcare-background-dark rounded-full overflow-hidden">
              <div
                className={`h-full ${getProgressBarColor()} transition-all duration-300`}
                style={{ width: `${calculateProgress()}%` }}
              />
            </div>
          </div>
        </>
      ) : (
        <div className="space-y-2">
          <div className="flex items-center text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            <Icon icon="heroicons:clock" className="w-4 h-4 mr-1" />
            {room.nextCase ? (
              <span>Next case: {room.nextCase.startTime}</span>
            ) : (
              <span>No cases scheduled</span>
            )}
          </div>
          {room.status === 'turnover' && (
            <div className="flex items-center text-xs text-healthcare-warning dark:text-healthcare-warning-dark">
              <Icon icon="heroicons:arrow-path" className="w-4 h-4 mr-1" />
              <span>Est. ready in {room.turnoverTime} min</span>
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export default RoomStatusCard;
