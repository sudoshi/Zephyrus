import React from 'react';
import { Icon } from '@iconify/react';
import StatusDot from './StatusDot';
import Modal from '@/Components/Common/Modal';

const TimeDisplay = ({ time, isOverdue = false }) => (
  <span className={`font-mono ${isOverdue ? 'text-healthcare-error dark:text-healthcare-error-dark' : 'text-healthcare-text-primary dark:text-healthcare-text-primary-dark'}`}>
    {time}
  </span>
);

const RoomDetailsModal = ({ room, onClose }) => {
  if (!room) return null;

  const modalContent = (
    <>
      {/* Header */}
      <div className="flex justify-between items-start mb-6">
        <div>
          <h2 className="text-2xl font-bold text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
            Room {room.number}
          </h2>
          <div className="flex items-center mt-2">
            <StatusDot status={room.status} />
            <span className="ml-2 text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
              Status: {room.status === 'in_progress' ? 'In Progress' :
                      room.status === 'turnover' ? 'Turnover' :
                      room.status === 'delayed' ? 'Delayed' : 'Available'}
            </span>
          </div>
        </div>
        {room.currentCase && (
          <div className="bg-healthcare-info bg-opacity-10 dark:bg-opacity-20 p-4 rounded-lg">
            <div className="grid grid-cols-2 gap-2 text-sm">
              <span className="font-medium text-healthcare-info dark:text-healthcare-info-dark">Patient:</span>
              <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{room.currentCase.patient}</span>
              <span className="font-medium text-healthcare-info dark:text-healthcare-info-dark">Procedure:</span>
              <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{room.currentCase.procedure}</span>
            </div>
          </div>
        )}
      </div>

      {/* Main Content */}
      {room.currentCase ? (
        <div className="space-y-6">
          {/* Timeline */}
          <div className="bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg p-4">
            <h3 className="font-semibold mb-4 flex items-center">
              <Icon icon="heroicons:clock" className="w-5 h-5 mr-2 text-healthcare-info dark:text-healthcare-info-dark" />
              Timeline
            </h3>
            <div className="relative">
              <div className="h-2 bg-healthcare-border dark:bg-healthcare-border-dark rounded-full"></div>
              <div 
                className={`h-2 rounded-full absolute top-0 left-0 ${
                  room.status === 'delayed' 
                    ? 'bg-healthcare-error dark:bg-healthcare-error-dark' 
                    : 'bg-healthcare-info dark:bg-healthcare-info-dark'
                }`}
                style={{ width: `${(room.currentCase.elapsed / room.currentCase.expectedDuration) * 100}%` }}
              ></div>
              <div className="flex justify-between mt-4">
                <div className="text-center">
                  <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Start</span>
                  <TimeDisplay time={room.currentCase.startTime} />
                </div>
                <div className="text-center">
                  <span className="text-sm font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Expected End</span>
                  <TimeDisplay 
                    time={room.currentCase.expectedEndTime} 
                    isOverdue={room.status === 'delayed'}
                  />
                </div>
              </div>
            </div>
          </div>

          {/* Staff & Resources */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg p-4">
              <h3 className="font-semibold mb-4 flex items-center">
                <Icon icon="heroicons:users" className="w-5 h-5 mr-2 text-healthcare-info dark:text-healthcare-info-dark" />
                Staff
              </h3>
              <div className="space-y-3">
                {room.currentCase.staff?.map((member, index) => (
                  <div key={index} className="flex items-center justify-between">
                    <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{member.name}</span>
                    <span className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">{member.role}</span>
                  </div>
                ))}
              </div>
            </div>

            <div className="bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg p-4">
              <h3 className="font-semibold mb-4 flex items-center">
                <Icon icon="heroicons:cube" className="w-5 h-5 mr-2 text-healthcare-info dark:text-healthcare-info-dark" />
                Resources
              </h3>
              <div className="space-y-3">
                {room.currentCase.resources?.map((resource, index) => (
                  <div key={index} className="flex items-center justify-between">
                    <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{resource.name}</span>
                    <StatusDot status={resource.status} />
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* Notes & Alerts */}
          {(room.currentCase.notes || room.currentCase.alerts) && (
            <div className="bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg p-4">
              <h3 className="font-semibold mb-4">Additional Information</h3>
              {room.currentCase.notes && (
                <div className="mb-4">
                  <h4 className="text-sm font-medium mb-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Notes</h4>
                  <p className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{room.currentCase.notes}</p>
                </div>
              )}
              {room.currentCase.alerts && room.currentCase.alerts.length > 0 && (
                <div>
                  <h4 className="text-sm font-medium mb-2 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Alerts</h4>
                  <div className="space-y-2">
                    {room.currentCase.alerts.map((alert, index) => (
                      <div key={index} className="flex items-center text-sm text-healthcare-error dark:text-healthcare-error-dark">
                        <Icon icon="heroicons:exclamation-circle" className="w-4 h-4 mr-2" />
                        {alert}
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}
        </div>
      ) : (
        <div className="text-center py-8 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
          <Icon icon="heroicons:clipboard-document-list" className="w-12 h-12 mx-auto mb-4" />
          <p>No active case in this room</p>
          {room.nextCase && (
            <p className="mt-2">
              Next case scheduled for {room.nextCase.startTime}
            </p>
          )}
        </div>
      )}
    </>
  );

  return (
    <Modal
      open={!!room}
      onClose={onClose}
      showClose={true}
    >
      {modalContent}
    </Modal>
  );
};

export default RoomDetailsModal;
