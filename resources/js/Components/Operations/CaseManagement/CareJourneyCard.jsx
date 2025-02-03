import React, { useState, useEffect } from "react";
import { Icon } from '@iconify/react';
import { Alert, AlertDescription } from "@/Components/ui/Alert";

const StatusDot = ({ status, pulse = false }) => {
  const colors = {
    green: "bg-healthcare-success dark:bg-healthcare-success-dark",
    yellow: "bg-healthcare-warning dark:bg-healthcare-warning-dark",
    red: "bg-healthcare-error dark:bg-healthcare-error-dark",
    gray: "bg-healthcare-text-secondary dark:bg-healthcare-text-secondary-dark",
  };

  return (
    <div
      className={`h-2 w-2 rounded-full ${colors[status] || colors.gray} ${
        pulse ? "animate-pulse" : ""
      }`}
    />
  );
};

const TimeDisplay = ({ time, isOverdue = false }) => (
  <span
    className={`font-mono ${
      isOverdue ? "text-healthcare-error dark:text-healthcare-error-dark font-bold" : "text-healthcare-text-primary dark:text-healthcare-text-primary-dark"
    }`}
  >
    {time}
  </span>
);

function CareJourneyCard({ procedure, measurements, onClose }) {
  const [currentTime, setCurrentTime] = useState("08:37");
  const [showSafetyAlert, setShowSafetyAlert] = useState(false);

  useEffect(() => {
    const timer = setInterval(() => {
      const now = new Date();
      setCurrentTime(
        now.toLocaleTimeString("en-US", {
          hour12: false,
          hour: "2-digit",
          minute: "2-digit",
        })
      );
    }, 1000);
    return () => clearInterval(timer);
  }, []);

  if (!procedure) return null;

  return (
    <div className="relative bg-healthcare-background dark:bg-healthcare-background-dark p-4 rounded-lg">
      {/* Close button */}
      <button
        onClick={onClose}
        className="absolute right-4 top-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark hover:text-healthcare-text-primary dark:hover:text-healthcare-text-primary-dark"
      >
        <Icon icon="heroicons:x-mark" className="w-5 h-5" />
      </button>

      {/* Optional safety alert banner */}
      {showSafetyAlert && (
        <Alert className="mb-4 bg-healthcare-warning-light dark:bg-healthcare-warning-dark/20 border-healthcare-warning dark:border-healthcare-warning-dark">
          <Icon icon="heroicons:exclamation-circle" className="h-4 w-4 text-healthcare-warning dark:text-healthcare-warning-dark" />
          <AlertDescription className="text-healthcare-warning dark:text-healthcare-warning-dark">
            Safety Check Required: Please verify patient identity and procedure details
          </AlertDescription>
        </Alert>
      )}

      {/* Main Layout: Left / Middle / Right panels */}
      <div className="grid grid-cols-1 md:grid-cols-12 gap-4">
        {/* Left Panel */}
        <div className="md:col-span-3 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow">
          <div className="border-t-4 border-healthcare-success dark:border-healthcare-success-dark p-4">
            <div className="flex justify-between items-start mb-4">
              <div>
                <h2 className="font-bold text-lg text-healthcare-success dark:text-healthcare-success-dark">
                  Provider: {procedure.provider}
                </h2>
                <div className="flex items-center mt-1">
                  <StatusDot status="green" />
                  <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark ml-2">
                    Status: {procedure.status}
                  </span>
                </div>
              </div>
              <button className="bg-healthcare-error hover:bg-healthcare-error-dark text-white px-3 py-1 text-sm rounded transition-colors duration-150 focus:ring-2 focus:ring-healthcare-error-light dark:focus:ring-healthcare-error-dark">
                Cancel Procedure
              </button>
            </div>

            <div className="bg-healthcare-info-light dark:bg-healthcare-info-dark/20 p-3 rounded-lg mb-4 border-l-4 border-healthcare-info dark:border-healthcare-info-dark">
              <div className="grid grid-cols-2 gap-1 text-sm">
                <span className="font-medium text-healthcare-info dark:text-healthcare-info-dark">Patient:</span>
                <span className="font-mono text-healthcare-info-dark dark:text-healthcare-info-light">
                  {procedure.patient}
                </span>
                <span className="font-medium text-healthcare-info dark:text-healthcare-info-dark">Specialty:</span>
                <span className="font-mono text-healthcare-info-dark dark:text-healthcare-info-light">
                  {procedure.specialty}
                </span>
              </div>
            </div>

            <div className="space-y-2 text-sm">
              <div className="grid grid-cols-2 gap-1">
                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Location:</span>
                <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{procedure.location}</span>
              </div>
              <div className="grid grid-cols-2 gap-1">
                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Phase:</span>
                <span className="text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{procedure.phase}</span>
              </div>
              <div className="grid grid-cols-2 gap-1">
                <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Resource Status:</span>
                <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{procedure.resourceStatus}</span>
              </div>
            </div>

            <div className="mt-4 pt-4 border-t border-healthcare-border dark:border-healthcare-border-dark">
              <h3 className="font-semibold mb-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Procedure Details</h3>
              <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg text-sm space-y-2">
                <div className="grid grid-cols-2 gap-1">
                  <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Procedure Type:</span>
                  <span className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{procedure.type}</span>
                </div>
                <div className="grid grid-cols-2 gap-1">
                  <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Start Time:</span>
                  <span className="font-mono text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{procedure.startTime}</span>
                </div>
                <div className="grid grid-cols-2 gap-1">
                  <span className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Expected Duration:</span>
                  <span className="font-mono text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {procedure.expectedDuration} mins
                  </span>
                </div>
              </div>
            </div>

            <button className="mt-4 w-full flex items-center justify-center text-healthcare-info dark:text-healthcare-info-dark text-sm p-2 rounded-lg hover:bg-healthcare-info-light dark:hover:bg-healthcare-info-dark/20 transition-colors duration-150">
              <Icon icon="heroicons:plus" className="w-4 h-4 mr-1" />
              <span>Add Additional Information</span>
            </button>
          </div>
        </div>

        {/* Middle Panel */}
        <div className="md:col-span-6 space-y-4">
          {/* Pre-Procedure Panel */}
          <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow">
            <div className="border-t-4 border-healthcare-info dark:border-healthcare-info-dark p-4">
              <div className="flex justify-between items-center mb-4">
                <div className="flex items-center">
                  <h2 className="font-bold text-lg text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Pre-Procedure</h2>
                  <div className="ml-2 px-2 py-1 bg-healthcare-info-light dark:bg-healthcare-info-dark/20 rounded-full text-xs text-healthcare-info dark:text-healthcare-info-dark">
                    Time Critical
                  </div>
                </div>
                <div className="flex items-center space-x-2">
                  <StatusDot status="green" pulse={true} />
                  <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Ready for MD</span>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4 mb-4">
                <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
                  <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Current Time</span>
                  <TimeDisplay time={currentTime} />
                </div>
                <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
                  <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Journey so far</span>
                  <span className="font-mono block text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                    {procedure.journey} mins
                  </span>
                </div>
              </div>

              <div className="space-y-2 mb-4">
                <div className="flex items-center justify-between p-3 bg-healthcare-background dark:bg-healthcare-background-dark rounded-lg hover:bg-healthcare-background-alt dark:hover:bg-healthcare-background-alt-dark transition-colors duration-150 cursor-pointer">
                  <div className="flex items-center">
                    <StatusDot status="yellow" pulse={true} />
                    <span className="ml-2 font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">H&P</span>
                    <span className="ml-2 text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Required</span>
                  </div>
                  <Icon icon="heroicons:chevron-right" className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                </div>
                <div className="flex items-center justify-between p-3 bg-healthcare-success-light dark:bg-healthcare-success-dark/20 rounded-lg">
                  <div className="flex items-center">
                    <Icon icon="heroicons:check-circle" className="text-healthcare-success dark:text-healthcare-success-dark h-4 w-4" />
                    <span className="ml-2 font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Consent</span>
                    <span className="ml-2 text-xs text-healthcare-success dark:text-healthcare-success-dark">
                      Verified
                    </span>
                  </div>
                  <Icon icon="heroicons:chevron-right" className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                </div>
                <div className="flex items-center justify-between p-3 bg-healthcare-error-light dark:bg-healthcare-error-dark/20 rounded-lg">
                  <div className="flex items-center">
                    <StatusDot status="red" pulse={true} />
                    <span className="ml-2 font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Labs</span>
                    <span className="ml-2 text-xs text-healthcare-error dark:text-healthcare-error-dark">
                      Action Required
                    </span>
                  </div>
                  <Icon icon="heroicons:chevron-right" className="w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark" />
                </div>
              </div>

              <div className="space-y-2">
                <button className="w-full bg-healthcare-info-light dark:bg-healthcare-info-dark/20 hover:bg-healthcare-info-light/80 dark:hover:bg-healthcare-info-dark/30 text-healthcare-info dark:text-healthcare-info-dark py-2 px-4 rounded-lg text-sm transition-colors duration-150 flex items-center justify-center">
                  <Icon icon="heroicons:shield-check" className="h-4 w-4 mr-2" />
                  Add Safety Note
                </button>
                <button className="w-full bg-healthcare-purple-light dark:bg-healthcare-purple-dark/20 hover:bg-healthcare-purple-light/80 dark:hover:bg-healthcare-purple-dark/30 text-healthcare-purple dark:text-healthcare-purple-dark py-2 px-4 rounded-lg text-sm transition-colors duration-150 flex items-center justify-center">
                  <Icon icon="heroicons:bell" className="h-4 w-4 mr-2" />
                  Add Barrier
                </button>
              </div>
            </div>
          </div>

          {/* Procedure Transport Panel */}
          <div className="bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow p-4">
            <h3 className="font-bold mb-4 flex items-center text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              <Icon icon="heroicons:clock-4" className="h-5 w-5 mr-2 text-healthcare-info dark:text-healthcare-info-dark" />
              Procedure Transport
            </h3>
            <div className="grid grid-cols-3 gap-4">
              <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Status</span>
                <div className="flex items-center mt-1">
                  <StatusDot status="green" />
                  <span className="ml-2 font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Ready</span>
                </div>
              </div>
              <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Time</span>
                <TimeDisplay time={currentTime} />
              </div>
              <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Transport By</span>
                <p className="mt-1 font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Chris</p>
              </div>
            </div>
          </div>
        </div>

        {/* Right Panel */}
        <div className="md:col-span-3 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow">
          <div className="border-t-4 border-healthcare-purple dark:border-healthcare-purple-dark p-4">
            <h2 className="font-bold text-lg mb-4 flex items-center text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
              Post-Procedure
              <span className="ml-2 px-2 py-1 bg-healthcare-purple-light dark:bg-healthcare-purple-dark/20 rounded-full text-xs text-healthcare-purple dark:text-healthcare-purple-dark">
                Monitoring
              </span>
            </h2>
            <div className="space-y-4">
              <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
                <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Location</span>
                <p className="font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">PACU</p>
              </div>
              <div className="grid grid-cols-2 gap-2">
                <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
                  <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Transport Time</span>
                  <p className="font-mono font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">12 mins</p>
                </div>
                <div className="bg-healthcare-background dark:bg-healthcare-background-dark p-3 rounded-lg">
                  <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Duration</span>
                  <p className="font-mono font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">55 mins</p>
                </div>
              </div>

              <div className="bg-healthcare-info-light dark:bg-healthcare-info-dark/20 p-3 rounded-lg border-l-4 border-healthcare-info dark:border-healthcare-info-dark">
                <h3 className="font-semibold text-sm mb-2 text-healthcare-info dark:text-healthcare-info-dark">
                  Room Turnover
                </h3>
                <div className="flex justify-between text-sm">
                  <span className="text-healthcare-info dark:text-healthcare-info-dark">Duration:</span>
                  <span className="font-mono text-healthcare-info-dark dark:text-healthcare-info-light">8 mins</span>
                </div>
              </div>

              <div className="space-y-3">
                <div className="p-2 rounded-lg bg-healthcare-success-light dark:bg-healthcare-success-dark/20 flex items-center justify-between">
                  <span className="text-sm text-healthcare-success dark:text-healthcare-success-dark">
                    Transport Status
                  </span>
                  <div className="flex items-center">
                    <Icon icon="heroicons:check-circle" className="h-4 w-4 text-healthcare-success dark:text-healthcare-success-dark mr-1" />
                    <span className="text-sm text-healthcare-success dark:text-healthcare-success-dark">Complete</span>
                  </div>
                </div>
                <div className="p-2 rounded-lg bg-healthcare-warning-light dark:bg-healthcare-warning-dark/20 flex items-center justify-between">
                  <span className="text-sm text-healthcare-warning dark:text-healthcare-warning-dark">
                    Recovery Status
                  </span>
                  <div className="flex items-center">
                    <StatusDot status="yellow" pulse={true} />
                    <span className="ml-2 text-sm text-healthcare-warning dark:text-healthcare-warning-dark">
                      In Progress
                    </span>
                  </div>
                </div>
                <div className="p-2 rounded-lg bg-healthcare-background dark:bg-healthcare-background-dark flex items-center justify-between">
                  <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Patient D/C</span>
                  <div className="flex items-center">
                    <Icon icon="heroicons:clock" className="h-4 w-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mr-1" />
                    <span className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">Pending</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Timeline Panel */}
      <div className="mt-4 bg-healthcare-surface dark:bg-healthcare-surface-dark rounded-lg shadow p-4">
        <div className="flex justify-between items-center mb-6">
          <div className="flex items-center space-x-6">
            <div className="flex items-center bg-healthcare-info-light dark:bg-healthcare-info-dark/20 px-3 py-2 rounded-lg">
              <Icon icon="heroicons:clock" className="text-healthcare-info dark:text-healthcare-info-dark mr-2" />
              <span className="font-medium text-healthcare-info dark:text-healthcare-info-dark">Current Time:</span>
              <TimeDisplay time={currentTime} />
            </div>
            <div className="flex items-center bg-healthcare-background dark:bg-healthcare-background-dark px-3 py-2 rounded-lg">
              <Icon icon="heroicons:user" className="text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark mr-2" />
              <span className="text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Charge RN: MD15</span>
            </div>
          </div>
        </div>

        <div className="relative">
          <div className="h-2 bg-healthcare-border dark:bg-healthcare-border-dark rounded-full"></div>
          <div className="h-2 bg-healthcare-info dark:bg-healthcare-info-dark rounded-full absolute top-0 left-0 w-3/5"></div>

          <div className="flex justify-between mt-4">
            <div className="text-center">
              <div className="flex flex-col items-center">
                <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Pre</span>
                <TimeDisplay time="08:26" />
              </div>
            </div>
            <div className="text-center">
              <div className="flex flex-col items-center">
                <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Start</span>
                <TimeDisplay time="09:41" />
              </div>
            </div>
            <div className="text-center">
              <div className="flex flex-col items-center">
                <span className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">End</span>
                <TimeDisplay time="10:52" isOverdue={true} />
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Measurements Table */}
      <div className="mt-4 bg-healthcare-surface dark:bg-healthcare-surface-dark p-4 rounded-lg shadow">
        <h3 className="text-lg font-semibold mb-3 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Measurements</h3>
        {measurements.length === 0 ? (
          <p className="text-sm text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
            No measurements found for this patient.
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full border border-healthcare-border dark:border-healthcare-border-dark text-sm">
              <thead className="bg-healthcare-background dark:bg-healthcare-background-dark">
                <tr>
                  <th className="p-2 border-b border-healthcare-border dark:border-healthcare-border-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Timestamp</th>
                  <th className="p-2 border-b border-healthcare-border dark:border-healthcare-border-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark">HR</th>
                  <th className="p-2 border-b border-healthcare-border dark:border-healthcare-border-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark">SBP</th>
                  <th className="p-2 border-b border-healthcare-border dark:border-healthcare-border-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark">DBP</th>
                  <th className="p-2 border-b border-healthcare-border dark:border-healthcare-border-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark">MAP</th>
                  <th className="p-2 border-b border-healthcare-border dark:border-healthcare-border-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark">SpO2</th>
                  <th className="p-2 border-b border-healthcare-border dark:border-healthcare-border-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Temp</th>
                  <th className="p-2 border-b border-healthcare-border dark:border-healthcare-border-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark">RR</th>
                  <th className="p-2 border-b border-healthcare-border dark:border-healthcare-border-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark">FiO2</th>
                  <th className="p-2 border-b border-healthcare-border dark:border-healthcare-border-dark text-healthcare-text-primary dark:text-healthcare-text-primary-dark">Notes</th>
                </tr>
              </thead>
              <tbody>
                {measurements.map((m) => (
                  <tr key={m.measurement_id} className="border-b border-healthcare-border dark:border-healthcare-border-dark">
                    <td className="p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {new Date(m.Timestamp).toLocaleString()}
                    </td>
                    <td className="p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{m.HR}</td>
                    <td className="p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{m.SBP}</td>
                    <td className="p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{m.DBP}</td>
                    <td className="p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{m.MAP}</td>
                    <td className="p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{m.SpO2}</td>
                    <td className="p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {m.Temp != null ? m.Temp.toFixed(1) : ""}
                    </td>
                    <td className="p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{m.RR}</td>
                    <td className="p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
                      {m.FiO2 != null ? m.FiO2.toFixed(2) : ""}
                    </td>
                    <td className="p-2 text-healthcare-text-primary dark:text-healthcare-text-primary-dark">{m.notes}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

export default CareJourneyCard;
