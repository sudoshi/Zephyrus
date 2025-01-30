export const mockMetrics = {
  utilization: [
    { date: '2025-01-23', utilization: 75.2, avg_turnover: 28, case_count: 12 },
    { date: '2025-01-24', utilization: 82.1, avg_turnover: 25, case_count: 15 },
    { date: '2025-01-25', utilization: 68.4, avg_turnover: 32, case_count: 8 },
    { date: '2025-01-26', utilization: 79.9, avg_turnover: 27, case_count: 14 },
    { date: '2025-01-27', utilization: 85.3, avg_turnover: 24, case_count: 16 },
    { date: '2025-01-28', utilization: 77.8, avg_turnover: 29, case_count: 13 },
    { date: '2025-01-29', utilization: 81.5, avg_turnover: 26, case_count: 15 }
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
    scheduled_start_time: '2025-01-30T07:30:00',
    actual_start_time: '2025-01-30T07:35:00',
    estimated_duration: 180,
    status: 'In Progress',
    service_name: 'Orthopedics'
  },
  {
    case_id: 2,
    procedure_name: 'Laparoscopic Cholecystectomy',
    surgeon_name: 'Dr. Michael Smith',
    room_name: 'OR-2',
    scheduled_start_time: '2025-01-30T08:00:00',
    actual_start_time: '2025-01-30T08:10:00',
    estimated_duration: 120,
    status: 'In Progress',
    service_name: 'General Surgery'
  },
  {
    case_id: 3,
    procedure_name: 'Spinal Fusion',
    surgeon_name: 'Dr. James Wilson',
    room_name: 'OR-3',
    scheduled_start_time: '2025-01-30T10:30:00',
    estimated_duration: 240,
    status: 'Scheduled',
    service_name: 'Neurosurgery'
  },
  {
    case_id: 4,
    procedure_name: 'Coronary Artery Bypass',
    surgeon_name: 'Dr. Emily Brown',
    room_name: 'OR-4',
    scheduled_start_time: '2025-01-30T11:00:00',
    estimated_duration: 300,
    status: 'Scheduled',
    service_name: 'Cardiology'
  },
  {
    case_id: 5,
    procedure_name: 'Hip Replacement',
    surgeon_name: 'Dr. Sarah Johnson',
    room_name: 'OR-1',
    scheduled_start_time: '2025-01-30T13:30:00',
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
    or_in_time: '2025-01-30T07:35:00',
    scheduled_duration: 180,
    next_case_time: '2025-01-30T13:30:00'
  },
  {
    room_id: 2,
    room_name: 'OR-2',
    status: 'In Progress',
    case_id: 2,
    procedure_name: 'Laparoscopic Cholecystectomy',
    surgeon_name: 'Dr. Michael Smith',
    service_name: 'General Surgery',
    or_in_time: '2025-01-30T08:10:00',
    scheduled_duration: 120
  },
  {
    room_id: 3,
    room_name: 'OR-3',
    status: 'Available',
    next_case_time: '2025-01-30T10:30:00'
  },
  {
    room_id: 4,
    room_name: 'OR-4',
    status: 'Available',
    next_case_time: '2025-01-30T11:00:00'
  },
  {
    room_id: 5,
    room_name: 'OR-5',
    status: 'Turnover',
    next_case_time: '2025-01-30T09:30:00'
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
