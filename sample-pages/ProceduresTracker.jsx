import React, { useState } from "react";
import { Users, Clock, Activity, Clock4, AlertCircle } from "lucide-react";
import { Alert, AlertDescription } from "@/Components/ui/Alert";
import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/Card";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, Link } from "@inertiajs/react";
import Modal from "./CareJourneyModal";
import CareJourneyCard from "./CareJourneyCard";

const specialties = {
  "General Surgery": { color: "blue", count: 8, onTime: 7, delayed: 1 },
  Orthopedics: { color: "green", count: 6, onTime: 5, delayed: 1 },
  OBGYN: { color: "pink", count: 5, onTime: 4, delayed: 1 },
  Cardiac: { color: "red", count: 4, onTime: 3, delayed: 1 },
  "Cath Lab": { color: "yellow", count: 5, onTime: 4, delayed: 1 },
};

const locations = {
  "Main OR": { total: 8, inUse: 6 },
  "Cath Lab": { total: 3, inUse: 2 },
  "L&D": { total: 2, inUse: 2 },
  "Pre-Op": { total: 6, inUse: 4 },
};

const mockProcedures = [
  {
    id: 1,
    patient: "Johnson, M",
    type: "Laparoscopic Cholecystectomy",
    specialty: "General Surgery",
    status: "In Progress",
    phase: "Procedure",
    location: "OR 3",
    startTime: "07:30",
    expectedDuration: 90,
    provider: "Dr. Smith",
    resourceStatus: "On Time",
    journey: 60,
  },
  {
    id: 2,
    patient: "Davis, A",
    type: "Appendectomy",
    specialty: "General Surgery",
    status: "Pre-Op",
    phase: "Pre-Op",
    location: "Pre-Op 2",
    startTime: "08:15",
    expectedDuration: 60,
    provider: "Dr. Smith",
    resourceStatus: "On Time",
    journey: 20,
  },
  {
    id: 3,
    patient: "Miller, S",
    type: "Hernia Repair",
    specialty: "General Surgery",
    status: "Completed",
    phase: "Recovery",
    location: "PACU",
    startTime: "06:30",
    expectedDuration: 120,
    provider: "Dr. Johnson",
    resourceStatus: "On Time",
    journey: 90,
  },
  {
    id: 4,
    patient: "Wilson, R",
    type: "Bowel Resection",
    specialty: "General Surgery",
    status: "Delayed",
    phase: "Pre-Op",
    location: "Pre-Op 1",
    startTime: "09:00",
    expectedDuration: 180,
    provider: "Dr. Johnson",
    resourceStatus: "Delayed",
    journey: 10,
  },
  {
    id: 5,
    patient: "Moore, J",
    type: "Laparoscopic Appendectomy",
    specialty: "General Surgery",
    status: "In Progress",
    phase: "Procedure",
    location: "OR 4",
    startTime: "07:45",
    expectedDuration: 75,
    provider: "Dr. Smith",
    resourceStatus: "On Time",
    journey: 50,
  },
  {
    id: 6,
    patient: "Taylor, E",
    type: "Cholecystectomy",
    specialty: "General Surgery",
    status: "Pre-Op",
    phase: "Pre-Op",
    location: "Pre-Op 3",
    startTime: "09:30",
    expectedDuration: 90,
    provider: "Dr. Johnson",
    resourceStatus: "On Time",
    journey: 15,
  },
  {
    id: 7,
    patient: "Anderson, P",
    type: "Hernia Repair",
    specialty: "General Surgery",
    status: "In Queue",
    phase: "Pre-Op",
    location: "Waiting",
    startTime: "10:00",
    expectedDuration: 105,
    provider: "Dr. Smith",
    resourceStatus: "On Time",
    journey: 5,
  },
  {
    id: 8,
    patient: "Thomas, C",
    type: "Appendectomy",
    specialty: "General Surgery",
    status: "In Progress",
    phase: "Procedure",
    location: "OR 1",
    startTime: "08:00",
    expectedDuration: 60,
    provider: "Dr. Johnson",
    resourceStatus: "On Time",
    journey: 45,
  },
  {
    id: 9,
    patient: "Brown, L",
    type: "Total Knee Replacement",
    specialty: "Orthopedics",
    status: "In Progress",
    phase: "Procedure",
    location: "OR 5",
    startTime: "07:30",
    expectedDuration: 150,
    provider: "Dr. White",
    resourceStatus: "On Time",
    journey: 70,
  },
  {
    id: 10,
    patient: "Garcia, M",
    type: "Hip Replacement",
    specialty: "Orthopedics",
    status: "Pre-Op",
    phase: "Pre-Op",
    location: "Pre-Op 4",
    startTime: "09:45",
    expectedDuration: 180,
    provider: "Dr. White",
    resourceStatus: "On Time",
    journey: 25,
  },
  {
    id: 11,
    patient: "Martinez, R",
    type: "Arthroscopic Knee",
    specialty: "Orthopedics",
    status: "Completed",
    phase: "Recovery",
    location: "PACU",
    startTime: "06:45",
    expectedDuration: 90,
    provider: "Dr. Black",
    resourceStatus: "On Time",
    journey: 95,
  },
  {
    id: 12,
    patient: "Robinson, K",
    type: "Shoulder Surgery",
    specialty: "Orthopedics",
    status: "In Progress",
    phase: "Procedure",
    location: "OR 6",
    startTime: "08:15",
    expectedDuration: 120,
    provider: "Dr. Black",
    resourceStatus: "On Time",
    journey: 55,
  },
  {
    id: 13,
    patient: "Clark, A",
    type: "ACL Repair",
    specialty: "Orthopedics",
    status: "Pre-Op",
    phase: "Pre-Op",
    location: "Pre-Op 5",
    startTime: "10:30",
    expectedDuration: 120,
    provider: "Dr. White",
    resourceStatus: "On Time",
    journey: 20,
  },
  {
    id: 14,
    patient: "Rodriguez, J",
    type: "Knee Arthroscopy",
    specialty: "Orthopedics",
    status: "In Queue",
    phase: "Pre-Op",
    location: "Waiting",
    startTime: "11:00",
    expectedDuration: 90,
    provider: "Dr. Black",
    resourceStatus: "On Time",
    journey: 10,
  },
  {
    id: 15,
    patient: "Lee, S",
    type: "Cesarean Section",
    specialty: "OBGYN",
    status: "In Progress",
    phase: "Procedure",
    location: "L&D 1",
    startTime: "07:45",
    expectedDuration: 60,
    provider: "Dr. Martinez",
    resourceStatus: "On Time",
    journey: 65,
  },
  {
    id: 16,
    patient: "Walker, M",
    type: "Hysterectomy",
    specialty: "OBGYN",
    status: "Pre-Op",
    phase: "Pre-Op",
    location: "Pre-Op 6",
    startTime: "09:15",
    expectedDuration: 120,
    provider: "Dr. Martinez",
    resourceStatus: "On Time",
    journey: 30,
  },
  {
    id: 17,
    patient: "Hall, R",
    type: "Cesarean Section",
    specialty: "OBGYN",
    status: "In Progress",
    phase: "Procedure",
    location: "L&D 2",
    startTime: "08:30",
    expectedDuration: 60,
    provider: "Dr. Adams",
    resourceStatus: "On Time",
    journey: 50,
  },
  {
    id: 18,
    patient: "Young, K",
    type: "D&C",
    specialty: "OBGYN",
    status: "Completed",
    phase: "Recovery",
    location: "PACU",
    startTime: "07:00",
    expectedDuration: 45,
    provider: "Dr. Adams",
    resourceStatus: "On Time",
    journey: 100,
  },
  {
    id: 19,
    patient: "Allen, P",
    type: "Hysteroscopy",
    specialty: "OBGYN",
    status: "Pre-Op",
    phase: "Pre-Op",
    location: "Pre-Op 1",
    startTime: "10:45",
    expectedDuration: 60,
    provider: "Dr. Martinez",
    resourceStatus: "On Time",
    journey: 15,
  },
  {
    id: 20,
    patient: "Scott, D",
    type: "CABG",
    specialty: "Cardiac",
    status: "In Progress",
    phase: "Procedure",
    location: "OR 7",
    startTime: "07:15",
    expectedDuration: 240,
    provider: "Dr. Chen",
    resourceStatus: "On Time",
    journey: 75,
  },
  {
    id: 21,
    patient: "Green, T",
    type: "Valve Replacement",
    specialty: "Cardiac",
    status: "Pre-Op",
    phase: "Pre-Op",
    location: "Pre-Op 2",
    startTime: "10:00",
    expectedDuration: 180,
    provider: "Dr. Chen",
    resourceStatus: "Delayed",
    journey: 20,
  },
  {
    id: 22,
    patient: "Adams, B",
    type: "CABG",
    specialty: "Cardiac",
    status: "In Progress",
    phase: "Procedure",
    location: "OR 8",
    startTime: "07:30",
    expectedDuration: 240,
    provider: "Dr. Wong",
    resourceStatus: "On Time",
    journey: 70,
  },
  {
    id: 23,
    patient: "Nelson, M",
    type: "Valve Repair",
    specialty: "Cardiac",
    status: "Pre-Op",
    phase: "Pre-Op",
    location: "Pre-Op 3",
    startTime: "11:15",
    expectedDuration: 180,
    provider: "Dr. Wong",
    resourceStatus: "On Time",
    journey: 15,
  },
  {
    id: 24,
    patient: "King, L",
    type: "Cardiac Catheterization",
    specialty: "Cath Lab",
    status: "In Progress",
    phase: "Procedure",
    location: "Cath 1",
    startTime: "08:00",
    expectedDuration: 90,
    provider: "Dr. Patel",
    resourceStatus: "On Time",
    journey: 60,
  },
  {
    id: 25,
    patient: "Wright, R",
    type: "Angioplasty",
    specialty: "Cath Lab",
    status: "Completed",
    phase: "Recovery",
    location: "PACU",
    startTime: "07:00",
    expectedDuration: 120,
    provider: "Dr. Patel",
    resourceStatus: "On Time",
    journey: 100,
  },
  {
    id: 26,
    patient: "Lopez, A",
    type: "Cardiac Catheterization",
    specialty: "Cath Lab",
    status: "Pre-Op",
    phase: "Pre-Op",
    location: "Pre-Op 4",
    startTime: "09:30",
    expectedDuration: 90,
    provider: "Dr. Shah",
    resourceStatus: "On Time",
    journey: 25,
  },
  {
    id: 27,
    patient: "Hill, C",
    type: "Angioplasty",
    specialty: "Cath Lab",
    status: "In Progress",
    phase: "Procedure",
    location: "Cath 2",
    startTime: "08:30",
    expectedDuration: 120,
    provider: "Dr. Shah",
    resourceStatus: "On Time",
    journey: 55,
  },
  {
    id: 28,
    patient: "Baker, J",
    type: "EP Study",
    specialty: "Cath Lab",
    status: "In Queue",
    phase: "Pre-Op",
    location: "Waiting",
    startTime: "10:45",
    expectedDuration: 150,
    provider: "Dr. Patel",
    resourceStatus: "On Time",
    journey: 10,
  },
];

const StatusDot = ({ status }) => {
  const colors = {
    onTime: "bg-green-500",
    delayed: "bg-red-500",
    warning: "bg-yellow-500",
  };
  return (
    <div
      className={`h-2 w-2 rounded-full ${colors[status] || "bg-gray-500"}`}
    />
  );
};

const ProgressBar = ({ value, max, status, estimatedCompletion }) => {
  const percentage = (value / max) * 100;
  const getStatusColor = () => {
    switch (status) {
      case "delayed":
        return "bg-red-500";
      case "warning":
        return "bg-yellow-500";
      case "completed":
        return "bg-green-500";
      default:
        return percentage <= 33
          ? "bg-blue-500"
          : percentage <= 66
          ? "bg-purple-500"
          : "bg-green-500";
    }
  };
  const getStatusBg = () => {
    switch (status) {
      case "delayed":
        return "bg-red-100";
      case "warning":
        return "bg-yellow-100";
      case "completed":
        return "bg-green-100";
      default:
        return "bg-gray-100";
    }
  };
  return (
    <div className="space-y-1">
      <div className="flex items-center justify-between text-xs">
        <div className="flex items-center space-x-2">
          <StatusDot status={status} />
          <span
            className={status === "delayed" ? "text-red-600" : "text-gray-600"}
          >
            {status === "delayed"
              ? "Behind Schedule"
              : status === "warning"
              ? "At Risk"
              : status === "completed"
              ? "Completed"
              : "On Track"}
          </span>
        </div>
        {estimatedCompletion && (
          <span className="text-gray-500">
            Est. completion: {estimatedCompletion}
          </span>
        )}
      </div>
      <div
        className={`w-full h-1.5 ${getStatusBg()} rounded-full overflow-hidden`}
      >
        <div
          className={`h-full ${getStatusColor()} transition-all duration-300`}
          style={{ width: `${percentage}%` }}
        />
      </div>
    </div>
  );
};

export default function ProceduresTrackingPage() {
  const [selectedPhase, setSelectedPhase] = useState("all");
  const stats = {
    totalPatients: 28,
    inProgress: 12,
    delayed: 4,
    completed: 8,
    preOp: 4,
  };
  const { totalPatients, inProgress, delayed, completed, preOp } = stats;
  const [selectedProcedure, setSelectedProcedure] = useState(null);
  const [isModalOpen, setIsModalOpen] = useState(false);

  // Handle opening the modal (select procedure)
  const handleOpenModal = (procedure) => {
    setSelectedProcedure(procedure);
    setIsModalOpen(true);
    // React Query automatically triggers the "measurements" fetch now
  };

  const handleCloseModal = () => {
    setSelectedProcedure(null);
    setIsModalOpen(false);
  };

  return (
    <AuthenticatedLayout
      header={
        <h2 className="font-semibold text-xl text-gray-800 leading-tight">
          Procedures Tracker
        </h2>
      }
    >
      <Head title="Procedures Tracker" />
      <div className="py-12">
        <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
          <div className="mb-6">
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
              <div className="space-y-2 p-4 border rounded-lg bg-white">
                <div className="flex items-center justify-between">
                  <span className="text-sm font-medium text-gray-600">
                    Procedures
                  </span>
                  <Users className="h-4 w-4 text-gray-500" />
                </div>
                <div className="flex items-baseline justify-between">
                  <span className="text-2xl font-bold">{totalPatients}</span>
                  <span className="text-sm text-gray-500">Today</span>
                </div>
                <div className="grid grid-cols-2 gap-2 text-xs">
                  <div className="flex items-center space-x-1">
                    <div className="h-2 w-2 rounded-full bg-green-500"></div>
                    <span className="text-gray-600">
                      {inProgress} In Progress
                    </span>
                  </div>
                  <div className="flex items-center space-x-1">
                    <div className="h-2 w-2 rounded-full bg-blue-500"></div>
                    <span className="text-gray-600">{preOp} Pre-Op</span>
                  </div>
                  <div className="flex items-center space-x-1">
                    <div className="h-2 w-2 rounded-full bg-purple-500"></div>
                    <span className="text-gray-600">{completed} Completed</span>
                  </div>
                  <div className="flex items-center space-x-1">
                    <div className="h-2 w-2 rounded-full bg-red-500"></div>
                    <span className="text-gray-600">{delayed} Delayed</span>
                  </div>
                </div>
              </div>

              <div className="space-y-2 p-4 border rounded-lg bg-white">
                <div className="flex items-center justify-between">
                  <span className="text-sm font-medium text-gray-600">
                    Time Performance
                  </span>
                  <Clock className="h-4 w-4 text-gray-500" />
                </div>
                <div className="flex items-baseline justify-between">
                  <span className="text-2xl font-bold">86%</span>
                  <span className="text-sm text-green-600">↑ 2.1%</span>
                </div>
                <div className="space-y-1">
                  <div className="flex justify-between text-xs text-gray-600">
                    <span>On Time</span>
                    <span>24/28 Cases</span>
                  </div>
                  <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                    <div
                      className="h-full bg-green-500 rounded-full"
                      style={{ width: "86%" }}
                    ></div>
                  </div>
                </div>
              </div>

              <div className="space-y-2 p-4 border rounded-lg bg-white">
                <div className="flex items-center justify-between">
                  <span className="text-sm font-medium text-gray-600">
                    Resource Usage
                  </span>
                  <Activity className="h-4 w-4 text-gray-500" />
                </div>
                <div className="flex items-baseline justify-between">
                  <span className="text-2xl font-bold">83%</span>
                  <span className="text-xs text-gray-500">15/20 Rooms</span>
                </div>
                <div className="grid grid-cols-2 gap-2 text-xs">
                  <div>
                    <div className="flex justify-between text-gray-600 mb-1">
                      <span>OR</span>
                      <span>6/8</span>
                    </div>
                    <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                      <div
                        className="h-full bg-blue-500 rounded-full"
                        style={{ width: "75%" }}
                      ></div>
                    </div>
                  </div>
                  <div>
                    <div className="flex justify-between text-gray-600 mb-1">
                      <span>Cath</span>
                      <span>2/3</span>
                    </div>
                    <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                      <div
                        className="h-full bg-blue-500 rounded-full"
                        style={{ width: "66%" }}
                      ></div>
                    </div>
                  </div>
                </div>
              </div>

              <div className="space-y-2 p-4 border rounded-lg bg-white">
                <div className="flex items-center justify-between">
                  <span className="text-sm font-medium text-gray-600">
                    Turnover Times
                  </span>
                  <Clock4 className="h-4 w-4 text-gray-500" />
                </div>
                <div className="flex items-baseline justify-between">
                  <span className="text-2xl font-bold">24m</span>
                  <span className="text-sm text-green-600">↓ 1m</span>
                </div>
                <div className="space-y-1 text-xs">
                  <div className="flex justify-between text-gray-600">
                    <span>Target: 25m</span>
                    <span>Last: 22m</span>
                  </div>
                  <div className="grid grid-cols-4 gap-1">
                    {[22, 24, 23, 22].map((time, i) => (
                      <div
                        key={i}
                        className="h-1.5 bg-blue-500 rounded-full"
                        style={{ opacity: 0.5 + i * 0.15 }}
                      ></div>
                    ))}
                  </div>
                  <div className="text-gray-500">Last 4 turnovers</div>
                </div>
              </div>
            </div>
          </div>

          <div className="mb-6">
            <h3 className="text-2xl font-bold text-gray-900 mb-4">
              Service Line & Resource Status
            </h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="p-4 border rounded-lg bg-white">
                <h3 className="text-md font-medium mb-2">
                  Service Line Status
                </h3>
                <div className="space-y-4">
                  {Object.entries(specialties).map(([name, data]) => (
                    <div
                      key={name}
                      className="flex items-center justify-between"
                    >
                      <div className="flex items-center space-x-3">
                        <div
                          className={`h-8 w-8 rounded-full bg-${data.color}-100 flex items-center justify-center`}
                        >
                          <span
                            className={`text-${data.color}-600 font-medium`}
                          >
                            {data.count}
                          </span>
                        </div>
                        <div>
                          <div className="font-medium">{name}</div>
                          <div className="text-sm text-gray-500">
                            {data.onTime} on time • {data.delayed} delayed
                          </div>
                        </div>
                      </div>
                      <div className="flex items-center space-x-2">
                        <span className="text-sm">
                          {((data.onTime / data.count) * 100).toFixed(0)}%
                          On-Time
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
              <div className="p-4 border rounded-lg bg-white">
                <h3 className="text-md font-medium mb-2">Resource Status</h3>
                <div className="space-y-4">
                  {Object.entries(locations).map(([name, data]) => (
                    <div
                      key={name}
                      className="flex items-center justify-between"
                    >
                      <div>
                        <div className="font-medium">{name}</div>
                        <div className="text-sm text-gray-500">
                          {data.inUse} in use • {data.total - data.inUse}{" "}
                          available
                        </div>
                      </div>
                      <div className="w-36">
                        <div className="h-1.5 bg-gray-200 rounded-full overflow-hidden">
                          <div
                            className="h-full bg-blue-500 rounded-full"
                            style={{
                              width: `${(data.inUse / data.total) * 100}%`,
                            }}
                          ></div>
                        </div>
                        <div className="text-xs text-gray-500 text-right">
                          {data.inUse}/{data.total}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="text-lg">Active Procedures</CardTitle>
              <div className="flex space-x-2">
                {["Pre-Op", "Procedure", "Recovery"].map((phase) => (
                  <button
                    key={phase}
                    onClick={() => setSelectedPhase(phase)}
                    className={`px-3 py-1 rounded-full text-sm ${
                      selectedPhase === phase
                        ? "bg-blue-100 text-blue-800"
                        : "bg-gray-50 text-gray-600"
                    }`}
                  >
                    {phase}
                  </button>
                ))}
                <button
                  onClick={() => setSelectedPhase("all")}
                  className={`px-3 py-1 rounded-full text-sm ${
                    selectedPhase === "all"
                      ? "bg-gray-200 text-gray-800"
                      : "bg-gray-50 text-gray-600"
                  }`}
                >
                  All
                </button>
              </div>

              <Alert className="mb-4 bg-yellow-50 border-yellow-200">
                <AlertCircle className="h-4 w-4 text-yellow-600" />
                <AlertDescription className="text-yellow-800">
                  4 procedures currently showing delays. Resource adjustment
                  recommended.
                </AlertDescription>
              </Alert>
            </CardHeader>
            <CardContent>
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="text-left text-sm text-gray-500">
                      <th className="pb-3 font-medium">Patient & Procedure</th>
                      <th className="pb-3 font-medium">Location & Staff</th>
                      <th className="pb-3 font-medium">Timing</th>
                      <th className="pb-3 font-medium w-1/3">
                        Status & Progress
                      </th>
                    </tr>
                  </thead>
                  <tbody className="text-sm bg-white">
                    {mockProcedures
                      .filter(
                        (proc) =>
                          selectedPhase === "all" ||
                          proc.phase === selectedPhase
                      )
                      .map((proc) => {
                        const startTime = new Date(
                          `2025-01-16T${proc.startTime}`
                        );
                        const estimatedEnd = new Date(
                          startTime.getTime() + proc.expectedDuration * 60000
                        );
                        const estimatedCompletion =
                          estimatedEnd.toLocaleTimeString("en-US", {
                            hour: "2-digit",
                            minute: "2-digit",
                            hour12: false,
                          });
                        let progressStatus = "onTime";
                        if (proc.phase === "Recovery")
                          progressStatus = "completed";
                        else if (proc.resourceStatus === "Delayed")
                          progressStatus = "delayed";
                        return (
                          <tr
                            key={proc.id}
                            className={`cursor-pointer border-t ${
                              progressStatus === "delayed" ? "bg-red-50" : ""
                            }`}
                            onClick={() => handleOpenModal(proc)}
                          >
                            <td className="py-3 px-2">
                              <div className="font-medium">{proc.patient}</div>
                              <div className="text-sm">{proc.type}</div>
                              <div className="text-xs text-gray-500 mt-1">
                                <span
                                  className={`inline-block px-2 py-0.5 rounded-full ${
                                    proc.specialty === "General Surgery"
                                      ? "bg-blue-100 text-blue-800"
                                      : proc.specialty === "Orthopedics"
                                      ? "bg-purple-100 text-purple-800"
                                      : proc.specialty === "OBGYN"
                                      ? "bg-pink-100 text-pink-800"
                                      : proc.specialty === "Cardiac"
                                      ? "bg-red-100 text-red-800"
                                      : "bg-orange-100 text-orange-800"
                                  }`}
                                >
                                  {proc.specialty}
                                </span>
                              </div>
                            </td>
                            <td className="py-3 px-2">
                              <div className="font-medium">{proc.location}</div>
                              <div className="text-sm">{proc.provider}</div>
                              <div className="text-xs text-gray-500 mt-1">
                                {proc.phase === "Pre-Op" && "Preparing"}
                                {proc.phase === "Procedure" && "In Surgery"}
                                {proc.phase === "Recovery" && "Recovering"}
                              </div>
                            </td>
                            <td className="py-3 px-2">
                              <div className="space-y-1">
                                <div className="flex items-center space-x-1">
                                  <Clock4 className="h-4 w-4 text-gray-400" />
                                  <span>{proc.startTime}</span>
                                </div>
                                <div className="text-xs text-gray-500">
                                  Duration: {proc.expectedDuration} min
                                </div>
                                {progressStatus === "delayed" && (
                                  <div className="text-xs text-red-600">
                                    Delay: ~15-30 min
                                  </div>
                                )}
                              </div>
                            </td>
                            <td className="py-3 px-2">
                              <ProgressBar
                                value={proc.journey}
                                max={100}
                                status={progressStatus}
                                estimatedCompletion={estimatedCompletion}
                              />
                              {proc.phase === "Procedure" && (
                                <div className="mt-2 grid grid-cols-3 gap-2 text-xs">
                                  <div className="text-gray-500">
                                    <span className="font-medium">
                                      Anesthesia:
                                    </span>{" "}
                                    {progressStatus === "delayed"
                                      ? "Delayed"
                                      : "Ready"}
                                  </div>
                                  <div className="text-gray-500">
                                    <span className="font-medium">
                                      Blood Loss:
                                    </span>{" "}
                                    Minimal
                                  </div>
                                  <div className="text-gray-500">
                                    <span className="font-medium">Vitals:</span>{" "}
                                    Stable
                                  </div>
                                </div>
                              )}
                            </td>
                          </tr>
                        );
                      })}
                    {mockProcedures.filter(
                      (proc) =>
                        selectedPhase === "all" || proc.phase === selectedPhase
                    ).length === 0 && (
                      <tr>
                        <td
                          colSpan={4}
                          className="text-center py-4 text-gray-500"
                        >
                          No procedures found.
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
          {isModalOpen && selectedProcedure && (
            <Modal onClose={handleCloseModal}>
              <CareJourneyCard
                procedure={selectedProcedure}
                measurements={[]}
                onClose={handleCloseModal}
              />
            </Modal>
          )}
        </div>
      </div>
    </AuthenticatedLayout>
  );
}

