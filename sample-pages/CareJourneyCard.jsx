import React, { useState, useEffect } from "react";
import {
  Clock,
  User,
  AlertCircle,
  Plus,
  ChevronRight,
  CheckCircle,
  Bell,
  Shield,
  Clock4,
} from "lucide-react";
import { Alert, AlertDescription } from "@/Components/ui/Alert";

const StatusDot = ({ status, pulse = false }) => {
  const colors = {
    green: "bg-green-500",
    yellow: "bg-yellow-500",
    red: "bg-red-500",
    gray: "bg-gray-400",
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
      isOverdue ? "text-red-600 font-bold" : "text-gray-700"
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

  if (!procedure) return null; // no data?

  return (
    <div className="relative bg-gray-100 p-4 rounded-lg">
      {/* Close button (top-right corner) */}
      <button
        onClick={onClose}
        className="absolute right-4 top-4 text-gray-600 hover:text-gray-800"
      >
        X
      </button>
      {/* Optional safety alert banner */}
      {showSafetyAlert && (
        <Alert className="mb-4 bg-yellow-50 border-yellow-200">
          <AlertCircle className="h-4 w-4 text-yellow-600" />
          <AlertDescription className="text-yellow-800">
            Safety Check Required: Please verify patient identity and procedure
            details
          </AlertDescription>
        </Alert>
      )}
      {/* Main Layout: Left / Middle / Right panels */}
      <div className="grid grid-cols-1 md:grid-cols-12 gap-4">
        {/* Left Panel */}
        <div className="md:col-span-3 bg-white rounded-lg shadow">
          <div className="border-t-4 border-green-500 p-4">
            <div className="flex justify-between items-start mb-4">
              <div>
                <h2 className="font-bold text-lg text-green-700">
                  Provider: {procedure.provider}
                </h2>
                <div className="flex items-center mt-1">
                  <StatusDot status="green" />
                  <span className="text-sm text-gray-600 ml-2">
                    Status: {procedure.status}
                  </span>
                </div>
              </div>
              <button className="bg-red-500 hover:bg-red-600 text-white px-3 py-1 text-sm rounded transition-colors duration-150 focus:ring-2 focus:ring-red-300">
                Cancel Procedure
              </button>
            </div>

            <div className="bg-blue-50 p-3 rounded-lg mb-4 border-l-4 border-blue-400">
              <div className="grid grid-cols-2 gap-1 text-sm">
                <span className="font-medium text-blue-800">Patient:</span>
                <span className="font-mono text-blue-900">
                  {procedure.patient}
                </span>
                <span className="font-medium text-blue-800">Specialty:</span>
                <span className="font-mono text-blue-900">
                  {procedure.specialty}
                </span>
              </div>
            </div>

            <div className="space-y-2 text-sm">
              <div className="grid grid-cols-2 gap-1">
                <span className="text-gray-600">Location:</span>
                <span>{procedure.location}</span>
              </div>
              <div className="grid grid-cols-2 gap-1">
                <span className="text-gray-600">Phase:</span>
                <span>{procedure.phase}</span>
              </div>
              <div className="grid grid-cols-2 gap-1">
                <span className="text-gray-600">Resource Status:</span>
                <span className="font-medium">{procedure.resourceStatus}</span>
              </div>
            </div>

            <div className="mt-4 pt-4 border-t">
              <h3 className="font-semibold mb-2">Procedure Details</h3>
              <div className="bg-gray-50 p-3 rounded-lg text-sm space-y-2">
                <div className="grid grid-cols-2 gap-1">
                  <span className="text-gray-600">Procedure Type:</span>
                  <span className="font-medium">{procedure.type}</span>
                </div>
                <div className="grid grid-cols-2 gap-1">
                  <span className="text-gray-600">Start Time:</span>
                  <span className="font-mono">{procedure.startTime}</span>
                </div>
                <div className="grid grid-cols-2 gap-1">
                  <span className="text-gray-600">Expected Duration:</span>
                  <span className="font-mono">
                    {procedure.expectedDuration} mins
                  </span>
                </div>
              </div>
            </div>

            <button className="mt-4 w-full flex items-center justify-center text-blue-600 text-sm p-2 rounded-lg hover:bg-blue-50 transition-colors duration-150">
              <Plus size={16} className="mr-1" />
              <span>Add Additional Information</span>
            </button>
          </div>
        </div>

        {/* Middle Panel */}
        <div className="md:col-span-6 space-y-4">
          {/* Pre-Procedure Panel */}
          <div className="bg-white rounded-lg shadow">
            <div className="border-t-4 border-blue-500 p-4">
              <div className="flex justify-between items-center mb-4">
                <div className="flex items-center">
                  <h2 className="font-bold text-lg">Pre-Procedure</h2>
                  <div className="ml-2 px-2 py-1 bg-blue-100 rounded-full text-xs text-blue-800">
                    Time Critical
                  </div>
                </div>
                <div className="flex items-center space-x-2">
                  <StatusDot status="green" pulse={true} />
                  <span className="text-sm font-medium">Ready for MD</span>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4 mb-4">
                <div className="bg-gray-50 p-3 rounded-lg">
                  <span className="text-sm text-gray-600">Current Time</span>
                  <TimeDisplay time={currentTime} />
                </div>
                <div className="bg-gray-50 p-3 rounded-lg">
                  <span className="text-sm text-gray-600">Journey so far</span>
                  <span className="font-mono block">
                    {procedure.journey} mins
                  </span>
                </div>
              </div>

              <div className="space-y-2 mb-4">
                <div className="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors duration-150 cursor-pointer">
                  <div className="flex items-center">
                    <StatusDot status="yellow" pulse={true} />
                    <span className="ml-2 font-medium">H&P</span>
                    <span className="ml-2 text-xs text-gray-500">Required</span>
                  </div>
                  <ChevronRight size={16} className="text-gray-400" />
                </div>
                <div className="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                  <div className="flex items-center">
                    <CheckCircle className="text-green-500 h-4 w-4" />
                    <span className="ml-2 font-medium">Consent</span>
                    <span className="ml-2 text-xs text-green-600">
                      Verified
                    </span>
                  </div>
                  <ChevronRight size={16} className="text-gray-400" />
                </div>
                <div className="flex items-center justify-between p-3 bg-red-50 rounded-lg">
                  <div className="flex items-center">
                    <StatusDot status="red" pulse={true} />
                    <span className="ml-2 font-medium">Labs</span>
                    <span className="ml-2 text-xs text-red-600">
                      Action Required
                    </span>
                  </div>
                  <ChevronRight size={16} className="text-gray-400" />
                </div>
              </div>

              <div className="space-y-2">
                <button className="w-full bg-blue-50 hover:bg-blue-100 text-blue-600 py-2 px-4 rounded-lg text-sm transition-colors duration-150 flex items-center justify-center">
                  <Shield className="h-4 w-4 mr-2" />
                  Add Safety Note
                </button>
                <button className="w-full bg-purple-50 hover:bg-purple-100 text-purple-600 py-2 px-4 rounded-lg text-sm transition-colors duration-150 flex items-center justify-center">
                  <Bell className="h-4 w-4 mr-2" />
                  Add Barrier
                </button>
              </div>
            </div>
          </div>

          {/* Procedure Transport Panel */}
          <div className="bg-white rounded-lg shadow p-4">
            <h3 className="font-bold mb-4 flex items-center">
              <Clock4 className="h-5 w-5 mr-2 text-blue-600" />
              Procedure Transport
            </h3>
            <div className="grid grid-cols-3 gap-4">
              <div className="bg-gray-50 p-3 rounded-lg">
                <span className="text-sm text-gray-600">Status</span>
                <div className="flex items-center mt-1">
                  <StatusDot status="green" />
                  <span className="ml-2 font-medium">Ready</span>
                </div>
              </div>
              <div className="bg-gray-50 p-3 rounded-lg">
                <span className="text-sm text-gray-600">Time</span>
                <TimeDisplay time={currentTime} />
              </div>
              <div className="bg-gray-50 p-3 rounded-lg">
                <span className="text-sm text-gray-600">Transport By</span>
                <p className="mt-1 font-medium">Chris</p>
              </div>
            </div>
          </div>
        </div>

        {/* Right Panel */}
        <div className="md:col-span-3 bg-white rounded-lg shadow">
          <div className="border-t-4 border-purple-500 p-4">
            <h2 className="font-bold text-lg mb-4 flex items-center">
              Post-Procedure
              <span className="ml-2 px-2 py-1 bg-purple-100 rounded-full text-xs text-purple-800">
                Monitoring
              </span>
            </h2>
            <div className="space-y-4">
              <div className="bg-gray-50 p-3 rounded-lg">
                <span className="text-sm text-gray-600">Location</span>
                <p className="font-medium">PACU</p>
              </div>
              <div className="grid grid-cols-2 gap-2">
                <div className="bg-gray-50 p-3 rounded-lg">
                  <span className="text-sm text-gray-600">Transport Time</span>
                  <p className="font-mono font-medium">12 mins</p>
                </div>
                <div className="bg-gray-50 p-3 rounded-lg">
                  <span className="text-sm text-gray-600">Duration</span>
                  <p className="font-mono font-medium">55 mins</p>
                </div>
              </div>

              <div className="bg-blue-50 p-3 rounded-lg border-l-4 border-blue-400">
                <h3 className="font-semibold text-sm mb-2 text-blue-800">
                  Room Turnover
                </h3>
                <div className="flex justify-between text-sm">
                  <span className="text-blue-600">Duration:</span>
                  <span className="font-mono text-blue-800">8 mins</span>
                </div>
              </div>

              <div className="space-y-3">
                <div className="p-2 rounded-lg bg-green-50 flex items-center justify-between">
                  <span className="text-sm text-green-800">
                    Transport Status
                  </span>
                  <div className="flex items-center">
                    <CheckCircle className="h-4 w-4 text-green-500 mr-1" />
                    <span className="text-sm text-green-700">Complete</span>
                  </div>
                </div>
                <div className="p-2 rounded-lg bg-yellow-50 flex items-center justify-between">
                  <span className="text-sm text-yellow-800">
                    Recovery Status
                  </span>
                  <div className="flex items-center">
                    <StatusDot status="yellow" pulse={true} />
                    <span className="ml-2 text-sm text-yellow-700">
                      In Progress
                    </span>
                  </div>
                </div>
                <div className="p-2 rounded-lg bg-gray-50 flex items-center justify-between">
                  <span className="text-sm text-gray-800">Patient D/C</span>
                  <div className="flex items-center">
                    <Clock className="h-4 w-4 text-gray-400 mr-1" />
                    <span className="text-sm text-gray-600">Pending</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>{" "}
      {/* end top grid */}
      {/* Timeline Panel */}
      <div className="mt-4 bg-white rounded-lg shadow p-4">
        <div className="flex justify-between items-center mb-6">
          <div className="flex items-center space-x-6">
            <div className="flex items-center bg-blue-50 px-3 py-2 rounded-lg">
              <Clock size={18} className="text-blue-600 mr-2" />
              <span className="font-medium text-blue-800">Current Time:</span>
              <TimeDisplay time={currentTime} />
            </div>
            <div className="flex items-center bg-gray-50 px-3 py-2 rounded-lg">
              <User size={18} className="text-gray-600 mr-2" />
              <span className="text-sm">Charge RN: MD15</span>
            </div>
          </div>
        </div>

        <div className="relative">
          <div className="h-2 bg-gray-200 rounded-full"></div>
          <div className="h-2 bg-blue-600 rounded-full absolute top-0 left-0 w-3/5"></div>

          <div className="flex justify-between mt-4">
            <div className="text-center">
              <div className="flex flex-col items-center">
                <span className="text-sm font-medium">Pre</span>
                <TimeDisplay time="08:26" />
              </div>
            </div>
            <div className="text-center">
              <div className="flex flex-col items-center">
                <span className="text-sm font-medium">Start</span>
                <TimeDisplay time="09:41" />
              </div>
            </div>
            <div className="text-center">
              <div className="flex flex-col items-center">
                <span className="text-sm font-medium">End</span>
                <TimeDisplay time="10:52" isOverdue={true} />
              </div>
            </div>
          </div>
        </div>
      </div>
      {/* Measurements Table */}
      <div className="mt-4 bg-white p-4 rounded-lg shadow">
        <h3 className="text-lg font-semibold mb-3">Measurements</h3>
        {measurements.length === 0 ? (
          <p className="text-sm text-gray-500">
            No measurements found for this patient.
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full border text-sm">
              <thead className="bg-gray-50">
                <tr>
                  <th className="p-2 border-b">Timestamp</th>
                  <th className="p-2 border-b">HR</th>
                  <th className="p-2 border-b">SBP</th>
                  <th className="p-2 border-b">DBP</th>
                  <th className="p-2 border-b">MAP</th>
                  <th className="p-2 border-b">SpO2</th>
                  <th className="p-2 border-b">Temp</th>
                  <th className="p-2 border-b">RR</th>
                  <th className="p-2 border-b">FiO2</th>
                  <th className="p-2 border-b">Notes</th>
                </tr>
              </thead>
              <tbody>
                {measurements.map((m) => (
                  <tr key={m.measurement_id} className="border-b">
                    <td className="p-2">
                      {new Date(m.Timestamp).toLocaleString()}
                    </td>
                    <td className="p-2">{m.HR}</td>
                    <td className="p-2">{m.SBP}</td>
                    <td className="p-2">{m.DBP}</td>
                    <td className="p-2">{m.MAP}</td>
                    <td className="p-2">{m.SpO2}</td>
                    <td className="p-2">
                      {m.Temp != null ? m.Temp.toFixed(1) : ""}
                    </td>
                    <td className="p-2">{m.RR}</td>
                    <td className="p-2">
                      {m.FiO2 != null ? m.FiO2.toFixed(2) : ""}
                    </td>
                    <td className="p-2">{m.notes}</td>
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

