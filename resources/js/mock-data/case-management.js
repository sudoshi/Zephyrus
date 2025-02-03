export const specialties = {
  "General Surgery": { color: "blue", count: 8, onTime: 7, delayed: 1 },
  "Orthopedics": { color: "green", count: 6, onTime: 5, delayed: 1 },
  "OBGYN": { color: "pink", count: 5, onTime: 4, delayed: 1 },
  "Cardiac": { color: "red", count: 4, onTime: 3, delayed: 1 },
  "Cath Lab": { color: "yellow", count: 5, onTime: 4, delayed: 1 },
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
    staff: [
      { name: "Dr. Johnson", role: "Surgeon" },
      { name: "Dr. Brown", role: "Anesthesiologist" },
      { name: "Nurse Miller", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "PACU", status: "onTime" },
      { name: "Recovery Equipment", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Johnson", role: "Surgeon" },
      { name: "Dr. Lee", role: "Anesthesiologist" },
      { name: "Nurse Wilson", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "Pre-Op 1", status: "delayed" },
      { name: "Anesthesia Cart", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Smith", role: "Surgeon" },
      { name: "Dr. Garcia", role: "Anesthesiologist" },
      { name: "Nurse Moore", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "OR 4", status: "onTime" },
      { name: "Laparoscopic Tower", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Johnson", role: "Surgeon" },
      { name: "Dr. Martinez", role: "Anesthesiologist" },
      { name: "Nurse Taylor", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "Pre-Op 3", status: "onTime" },
      { name: "Anesthesia Cart", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Smith", role: "Surgeon" },
      { name: "Dr. Wilson", role: "Anesthesiologist" },
      { name: "Nurse Anderson", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "Waiting Room", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Johnson", role: "Surgeon" },
      { name: "Dr. Brown", role: "Anesthesiologist" },
      { name: "Nurse Thomas", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "OR 1", status: "onTime" },
      { name: "Anesthesia Machine", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. White", role: "Surgeon" },
      { name: "Dr. Lee", role: "Anesthesiologist" },
      { name: "Nurse Brown", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "OR 5", status: "onTime" },
      { name: "Orthopedic Equipment", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. White", role: "Surgeon" },
      { name: "Dr. Garcia", role: "Anesthesiologist" },
      { name: "Nurse Garcia", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "Pre-Op 4", status: "onTime" },
      { name: "Anesthesia Cart", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Black", role: "Surgeon" },
      { name: "Dr. Martinez", role: "Anesthesiologist" },
      { name: "Nurse Martinez", role: "Recovery Nurse" }
    ],
    resources: [
      { name: "PACU", status: "onTime" },
      { name: "Recovery Equipment", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Black", role: "Surgeon" },
      { name: "Dr. Wilson", role: "Anesthesiologist" },
      { name: "Nurse Robinson", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "OR 6", status: "onTime" },
      { name: "Orthopedic Equipment", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. White", role: "Surgeon" },
      { name: "Dr. Brown", role: "Anesthesiologist" },
      { name: "Nurse Clark", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "Pre-Op 5", status: "onTime" },
      { name: "Anesthesia Cart", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Black", role: "Surgeon" },
      { name: "Dr. Lee", role: "Anesthesiologist" },
      { name: "Nurse Rodriguez", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "Waiting Room", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Martinez", role: "Surgeon" },
      { name: "Dr. Garcia", role: "Anesthesiologist" },
      { name: "Nurse Lee", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "L&D 1", status: "onTime" },
      { name: "C-Section Equipment", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Martinez", role: "Surgeon" },
      { name: "Dr. Wilson", role: "Anesthesiologist" },
      { name: "Nurse Walker", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "Pre-Op 6", status: "onTime" },
      { name: "Anesthesia Cart", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Adams", role: "Surgeon" },
      { name: "Dr. Brown", role: "Anesthesiologist" },
      { name: "Nurse Hall", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "L&D 2", status: "onTime" },
      { name: "C-Section Equipment", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Adams", role: "Surgeon" },
      { name: "Dr. Lee", role: "Anesthesiologist" },
      { name: "Nurse Young", role: "Recovery Nurse" }
    ],
    resources: [
      { name: "PACU", status: "onTime" },
      { name: "Recovery Equipment", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Martinez", role: "Surgeon" },
      { name: "Dr. Garcia", role: "Anesthesiologist" },
      { name: "Nurse Allen", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "Pre-Op 1", status: "onTime" },
      { name: "Anesthesia Cart", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Chen", role: "Surgeon" },
      { name: "Dr. Wilson", role: "Anesthesiologist" },
      { name: "Nurse Scott", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "OR 7", status: "onTime" },
      { name: "Heart-Lung Machine", status: "onTime" },
      { name: "Cardiac Equipment", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Chen", role: "Surgeon" },
      { name: "Dr. Brown", role: "Anesthesiologist" },
      { name: "Nurse Green", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "Pre-Op 2", status: "delayed" },
      { name: "Anesthesia Cart", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Wong", role: "Surgeon" },
      { name: "Dr. Lee", role: "Anesthesiologist" },
      { name: "Nurse Adams", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "OR 8", status: "onTime" },
      { name: "Heart-Lung Machine", status: "onTime" },
      { name: "Cardiac Equipment", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Wong", role: "Surgeon" },
      { name: "Dr. Martinez", role: "Anesthesiologist" },
      { name: "Nurse Nelson", role: "Scrub Nurse" }
    ],
    resources: [
      { name: "Pre-Op 3", status: "onTime" },
      { name: "Anesthesia Cart", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Patel", role: "Interventional Cardiologist" },
      { name: "Dr. Garcia", role: "Anesthesiologist" },
      { name: "Nurse King", role: "Cath Lab Nurse" }
    ],
    resources: [
      { name: "Cath 1", status: "onTime" },
      { name: "Cath Lab Equipment", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Patel", role: "Interventional Cardiologist" },
      { name: "Dr. Wilson", role: "Anesthesiologist" },
      { name: "Nurse Wright", role: "Recovery Nurse" }
    ],
    resources: [
      { name: "PACU", status: "onTime" },
      { name: "Recovery Equipment", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Shah", role: "Interventional Cardiologist" },
      { name: "Dr. Brown", role: "Anesthesiologist" },
      { name: "Nurse Lopez", role: "Cath Lab Nurse" }
    ],
    resources: [
      { name: "Pre-Op 4", status: "onTime" },
      { name: "Anesthesia Cart", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Shah", role: "Interventional Cardiologist" },
      { name: "Dr. Lee", role: "Anesthesiologist" },
      { name: "Nurse Hill", role: "Cath Lab Nurse" }
    ],
    resources: [
      { name: "Cath 2", status: "onTime" },
      { name: "Cath Lab Equipment", status: "onTime" }
    ]
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
    staff: [
      { name: "Dr. Patel", role: "Interventional Cardiologist" },
      { name: "Dr. Martinez", role: "Anesthesiologist" },
      { name: "Nurse Baker", role: "Cath Lab Nurse" }
    ],
    resources: [
      { name: "Waiting Room", status: "onTime" }
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
