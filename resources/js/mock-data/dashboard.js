export const mockMetrics = {
  utilization: [
    { date: '2025-01-24', utilization: 75.2, avg_turnover: 28, case_count: 12 },
    { date: '2025-01-25', utilization: 82.1, avg_turnover: 25, case_count: 15 },
    { date: '2025-01-26', utilization: 68.4, avg_turnover: 32, case_count: 8 },
    { date: '2025-01-27', utilization: 79.9, avg_turnover: 27, case_count: 14 },
    { date: '2025-01-28', utilization: 85.3, avg_turnover: 24, case_count: 16 },
    { date: '2025-01-29', utilization: 77.8, avg_turnover: 29, case_count: 13 },
    { date: '2025-01-30', utilization: 81.5, avg_turnover: 26, case_count: 15 }
  ],
  summary: {
    avg_utilization: 78.6,
    avg_turnover: 27.3,
    total_cases: 93
  }
};

export const mockTodaysCases = [
  {
    case_id: 1,
    procedure_name: 'Total Knee Arthroplasty',
    surgeon_name: 'Dr. Sarah Johnson',
    room_name: 'OR-1',
    scheduled_start_time: '2025-01-31T07:30:00',
    actual_start_time: '2025-01-31T07:35:00',
    estimated_duration: 180,
    status: 'In Progress',
    service_name: 'Orthopedics'
  },
  {
    case_id: 2,
    procedure_name: 'Laparoscopic Cholecystectomy',
    surgeon_name: 'Dr. Michael Smith',
    room_name: 'OR-2',
    scheduled_start_time: '2025-01-31T08:00:00',
    actual_start_time: '2025-01-31T08:10:00',
    estimated_duration: 120,
    status: 'In Progress',
    service_name: 'General Surgery'
  },
  {
    case_id: 3,
    procedure_name: 'Spinal Fusion',
    surgeon_name: 'Dr. James Wilson',
    room_name: 'OR-3',
    scheduled_start_time: '2025-01-31T10:30:00',
    estimated_duration: 240,
    status: 'Scheduled',
    service_name: 'Neurosurgery'
  },
  {
    case_id: 4,
    procedure_name: 'Coronary Artery Bypass',
    surgeon_name: 'Dr. Emily Brown',
    room_name: 'OR-4',
    scheduled_start_time: '2025-01-31T11:00:00',
    estimated_duration: 300,
    status: 'Scheduled',
    service_name: 'Cardiology'
  },
  {
    case_id: 5,
    procedure_name: 'Hip Replacement',
    surgeon_name: 'Dr. Sarah Johnson',
    room_name: 'OR-1',
    scheduled_start_time: '2025-01-31T13:30:00',
    estimated_duration: 180,
    status: 'Scheduled',
    service_name: 'Orthopedics'
  }
];

export const mockRoomStatus = [
  {
    room_id: 1,
    room_name: 'OR-1',
    status: 'In Progress',
    case_id: 1,
    procedure_name: 'Total Knee Arthroplasty',
    surgeon_name: 'Dr. Sarah Johnson',
    service_name: 'Orthopedics',
    or_in_time: '2025-01-31T07:35:00',
    scheduled_duration: 180,
    next_case_time: '2025-01-31T13:30:00'
  },
  {
    room_id: 2,
    room_name: 'OR-2',
    status: 'In Progress',
    case_id: 2,
    procedure_name: 'Laparoscopic Cholecystectomy',
    surgeon_name: 'Dr. Michael Smith',
    service_name: 'General Surgery',
    or_in_time: '2025-01-31T08:10:00',
    scheduled_duration: 120
  },
  {
    room_id: 3,
    room_name: 'OR-3',
    status: 'Available',
    next_case_time: '2025-01-31T10:30:00'
  },
  {
    room_id: 4,
    room_name: 'OR-4',
    status: 'Available',
    next_case_time: '2025-01-31T11:00:00'
  },
  {
    room_id: 5,
    room_name: 'OR-5',
    status: 'Turnover',
    next_case_time: '2025-01-31T09:30:00'
  }
];

export const mockServices = [
  { service_id: 1, name: 'General Surgery', code: 'GS' },
  { service_id: 2, name: 'Orthopedics', code: 'ORTHO' },
  { service_id: 3, name: 'Cardiology', code: 'CARD' },
  { service_id: 4, name: 'Neurosurgery', code: 'NEURO' },
  { service_id: 5, name: 'Urology', code: 'URO' },
  { service_id: 6, name: 'ENT', code: 'ENT' }
];

export const mockSurgeons = [
  { provider_id: 1, name: 'Dr. Sarah Johnson', service_id: 2, specialty: 'Orthopedics' },
  { provider_id: 2, name: 'Dr. Michael Smith', service_id: 1, specialty: 'General Surgery' },
  { provider_id: 3, name: 'Dr. James Wilson', service_id: 4, specialty: 'Neurosurgery' },
  { provider_id: 4, name: 'Dr. Emily Brown', service_id: 3, specialty: 'Cardiology' },
  { provider_id: 5, name: 'Dr. David Lee', service_id: 5, specialty: 'Urology' },
  { provider_id: 6, name: 'Dr. Lisa Chen', service_id: 6, specialty: 'ENT' }
];

export const syntheticData = {
  lastMonth: {
    firstCaseOnTime: { 
      value: 85, 
      date: 'Dec 24',
      trend: 'up', 
      previousValue: 82 
    },
    avgTurnover: { 
      value: 31, 
      date: 'Dec 24',
      trend: 'down', 
      previousValue: 33 
    },
    caseLengthAccuracy: { 
      value: 78, 
      date: 'Dec 24',
      trend: 'up', 
      previousValue: 75 
    },
    performedCases: { 
      value: 325, 
      date: 'Dec 24',
      trend: 'up', 
      previousValue: 310 
    },
    doSCancellations: { 
      value: 6, 
      date: 'Dec 24',
      trend: 'down', 
      previousValue: 8 
    },
    blockUtilization: { 
      value: 76, 
      date: 'Dec 24',
      trend: 'up', 
      previousValue: 74 
    },
    primetimeUtilization: { 
      staffed: 84, 
      unstaffed: 83,
      date: 'Dec 24',
      trend: 'up', 
      previousValue: 82 
    }
  },
  monthToDate: {
    onTimeStarts: {
      overall: 79,
      byService: {
        'ENT': 85,
        'General': 82,
        'Neurosurgery': 81,
        'OB/GYN': 80,
        'Ophthalmology': 83,
        'Ortho': 78,
        'Plastics': 84,
        'Transplant': 77,
        'Trauma': 81,
        'Urology': 80
      },
      firstCase: {
        'ENT': 88,
        'General': 85,
        'Neurosurgery': 83,
        'OB/GYN': 82,
        'Ophthalmology': 85,
        'Ortho': 80,
        'Plastics': 86,
        'Transplant': 79,
        'Trauma': 83,
        'Urology': 82
      }
    },
    avgTurnover: {
      byService: {
        'ENT': { room: 45, procedure: 30 },
        'General': { room: 40, procedure: 45 },
        'Neurosurgery': { room: 45, procedure: 25 },
        'OB/GYN': { room: 40, procedure: 25 },
        'Ophthalmology': { room: 35, procedure: 20 },
        'Ortho': { room: 35, procedure: 45 },
        'Plastics': { room: 20, procedure: 20 },
        'Transplant': { room: 30, procedure: 25 },
        'Trauma': { room: 30, procedure: 20 },
        'Urology': { room: 30, procedure: 20 }
      }
    },
    caseLengthAccuracy: {
      byService: {
        'ENT': { accurate: 45, under: 35, over: 20 },
        'General': { accurate: 35, under: 45, over: 20 },
        'Neurosurgery': { accurate: 20, under: 60, over: 20 },
        'OB/GYN': { accurate: 40, under: 45, over: 15 },
        'Ophthalmology': { accurate: 50, under: 35, over: 15 },
        'Ortho': { accurate: 45, under: 35, over: 20 },
        'Plastics': { accurate: 20, under: 60, over: 20 },
        'Transplant': { accurate: 35, under: 45, over: 20 },
        'Trauma': { accurate: 40, under: 40, over: 20 },
        'Urology': { accurate: 45, under: 35, over: 20 }
      }
    },
    blockUtilization: {
      overall: 71,
      locations: [
        { name: 'EMH Main OR', mtd: 71, lastMonth: 74, lastThreeMonths: 75, projected: 74, sched: 49 }
      ]
    },
    primetimeUtilization: {
      trend: [
        { month: 'Aug 24', staffed: 79, unstaffed: 78 },
        { month: 'Sep 24', staffed: 80, unstaffed: 79 },
        { month: 'Oct 24', staffed: 82, unstaffed: 81 },
        { month: 'Nov 24', staffed: 83, unstaffed: 82 },
        { month: 'Dec 24', staffed: 84, unstaffed: 83 },
        { month: 'Jan 25', staffed: 85, unstaffed: 84 }
      ]
    },
    performedCases: {
      byService: {
        'ENT': { cases: 25, addons: 1 },
        'General': { cases: 25, addons: 0 },
        'Neurosurgery': { cases: 16, addons: 0 },
        'OB/GYN': { cases: 25, addons: 0 },
        'Ophthalmology': { cases: 28, addons: 0 },
        'Ortho': { cases: 27, addons: 0 },
        'Plastics': { cases: 29, addons: 2 },
        'Transplant': { cases: 24, addons: 1 },
        'Trauma': { cases: 25, addons: 1 },
        'Urology': { cases: 19, addons: 0 }
      }
    },
    doSCancellations: {
      byService: {
        'ENT': { cases: 0, minutes: 0 },
        'General': { cases: 0, minutes: 0 },
        'Neurosurgery': { cases: 1, minutes: 30 },
        'OB/GYN': { cases: 1, minutes: 40 },
        'Ophthalmology': { cases: 3, minutes: 90 },
        'Ortho': { cases: 2, minutes: 60 },
        'Plastics': { cases: 2, minutes: 60 },
        'Transplant': { cases: 0, minutes: 0 },
        'Trauma': { cases: 1, minutes: 30 },
        'Urology': { cases: 2, minutes: 60 }
      }
    }
  },
  workbenchReports: [
    { name: 'Case Cancellations', status: 'Ready to run' },
    { name: 'Cancelled Cases (Today)', status: 'Ready to run' },
    { name: 'Cancelled Cases (Yesterday)', status: 'Ready to run' },
    { name: 'Case Length Accuracy', status: 'Ready to run' },
    { name: 'Case Length Accuracy (Today) for Dashboard', status: 'Ready to run' },
    { name: 'On Time Starts', status: 'Ready to run' },
    { name: 'On Time Starts (Today) for Dashboard', status: 'Ready to run' },
    { name: 'On Time Starts (Yesterday) for Dashboard', status: 'Ready to run' },
    { name: 'Patient Wait Times', status: 'Ready to run' },
    { name: 'Average Patient Wait Times Intra-op (Today) for Dashboard', status: 'Ready to run' },
    { name: 'Average Patient Wait Times Pre-op (Yesterday) for Dashboard', status: 'Ready to run' },
    { name: 'Average Patient Wait Times (Last Week) for Dashboard', status: 'Ready to run' },
    { name: 'Average Patient Wait Times PACU (Yesterday & Today) for Dashboard', status: 'Ready to run' },
    { name: 'Average Patient Wait Times Intra-op (Yesterday) for Dashboard', status: 'Ready to run' },
    { name: 'Quality', status: 'Ready to run' },
    { name: 'ACE NSQIP cycle 02', status: 'Ready to run' }
  ]
};
