export const specialties = {
  "General Surgery": { color: "info", count: 8, onTime: 7, delayed: 1 },
  "Orthopedics": { color: "success", count: 6, onTime: 5, delayed: 1 },
  "OBGYN": { color: "warning", count: 5, onTime: 4, delayed: 1 },
  "Cardiac": { color: "error", count: 4, onTime: 3, delayed: 1 },
  "Cath Lab": { color: "primary", count: 5, onTime: 4, delayed: 1 },
};

export const locations = {
  "Main OR": { total: 8, inUse: 6 },
  "Cath Lab": { total: 3, inUse: 2 },
  "L&D": { total: 2, inUse: 2 },
  "Pre-Op": { total: 6, inUse: 4 },
};

export const stats = {
  totalPatients: 28,
  inProgress: 12,
  delayed: 4,
  completed: 8,
  preOp: 4,
};

export const mockProcedures = [
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
    staff: [
      { name: "Dr. Smith", role: "Surgeon" },
      { name: "Dr. Jones", role: "Anesthesiologist" },
      { name: "Nurse Johnson", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "OR 3", status: "onTime" },
      { name: "Anesthesia Machine", status: "onTime" },
      { name: "Laparoscopic Tower", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Smith", role: "Surgeon" },
      { name: "Dr. Wilson", role: "Anesthesiologist" },
      { name: "Nurse Davis", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "Pre-Op 2", status: "onTime" },
      { name: "Anesthesia Cart", status: "onTime" }
    ]
  },
  {
    id: 3,
    patient: "Miller, S",
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
    staff: [
      { name: "Dr. White", role: "Surgeon" },
      { name: "Dr. Brown", role: "Anesthesiologist" },
      { name: "Nurse Miller", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "OR 5", status: "onTime" },
      { name: "Anesthesia Machine", status: "onTime" },
      { name: "Orthopedic Equipment", status: "onTime" }
    ]
  },
  {
    id: 4,
    patient: "Wilson, R",
    type: "CABG",
    specialty: "Cardiac",
    status: "In Progress",
    phase: "Procedure",
    location: "OR 7",
    startTime: "07:15",
    expectedDuration: 240,
    provider: "Dr. Chen",
    resourceStatus: "Delayed",
    journey: 75,
    staff: [
      { name: "Dr. Chen", role: "Surgeon" },
      { name: "Dr. Lee", role: "Anesthesiologist" },
      { name: "Nurse Wilson", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "OR 7", status: "delayed" },
      { name: "Heart-Lung Machine", status: "onTime" },
      { name: "Cardiac Equipment", status: "onTime" }
    ]
  }
];

export const analyticsData = [
  { month: 'Jan 23', cases: 391, avgDuration: 93, totalTime: 38000 },
  { month: 'Mar 23', cases: 374, avgDuration: 101, totalTime: 44000 },
  { month: 'May 23', cases: 463, avgDuration: 94, totalTime: 39000 },
  { month: 'Jul 23', cases: 413, avgDuration: 94, totalTime: 40000 },
  { month: 'Sep 23', cases: 406, avgDuration: 93, totalTime: 39000 },
  { month: 'Nov 23', cases: 406, avgDuration: 93, totalTime: 40000 },
  { month: 'Jan 24', cases: 427, avgDuration: 95, totalTime: 43000 },
  { month: 'Mar 24', cases: 427, avgDuration: 95, totalTime: 35000 },
  { month: 'May 24', cases: 408, avgDuration: 93, totalTime: 38000 }
];
