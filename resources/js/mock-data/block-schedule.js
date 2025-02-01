export const mockBlockTemplates = [
  {
    block_id: 1,
    room_id: 1,
    service_id: 2,
    surgeon_id: 1,
    title: 'Ortho AM Block',
    abbreviation: 'ORTHO-AM',
    start_time: '2025-01-31T07:30:00',
    end_time: '2025-01-31T11:30:00',
    is_public: false,
    days_of_week: [1, 3, 5] // Monday, Wednesday, Friday
  },
  {
    block_id: 2,
    room_id: 1,
    service_id: 2,
    surgeon_id: 1,
    title: 'Ortho PM Block',
    abbreviation: 'ORTHO-PM',
    start_time: '2025-01-31T12:30:00',
    end_time: '2025-01-31T16:30:00',
    is_public: false,
    days_of_week: [1, 3, 5]
  },
  {
    block_id: 3,
    room_id: 2,
    service_id: 1,
    surgeon_id: 2,
    title: 'General Surgery Block',
    abbreviation: 'GS',
    start_time: '2025-01-31T08:00:00',
    end_time: '2025-01-31T16:00:00',
    is_public: false,
    days_of_week: [2, 4] // Tuesday, Thursday
  },
  {
    block_id: 4,
    room_id: 3,
    service_id: 4,
    surgeon_id: 3,
    title: 'Neurosurgery Block',
    abbreviation: 'NEURO',
    start_time: '2025-01-31T07:30:00',
    end_time: '2025-01-31T15:30:00',
    is_public: false,
    days_of_week: [1, 4] // Monday, Thursday
  },
  {
    block_id: 5,
    room_id: 4,
    service_id: 3,
    surgeon_id: 4,
    title: 'Cardiology Block',
    abbreviation: 'CARD',
    start_time: '2025-01-31T07:00:00',
    end_time: '2025-01-31T15:00:00',
    is_public: false,
    days_of_week: [2, 5] // Tuesday, Friday
  }
];

export const mockBlockUtilization = [
  {
    block_id: 1,
    date: '2025-01-30',
    service_id: 2,
    location_id: 1,
    scheduled_minutes: 180,
    actual_minutes: 165,
    utilization_percentage: 91.7,
    cases_scheduled: 2,
    cases_performed: 2,
    prime_time_percentage: 100,
    non_prime_time_percentage: 0
  },
  {
    block_id: 2,
    date: '2025-01-30',
    service_id: 2,
    location_id: 1,
    scheduled_minutes: 240,
    actual_minutes: 210,
    utilization_percentage: 87.5,
    cases_scheduled: 3,
    cases_performed: 3,
    prime_time_percentage: 100,
    non_prime_time_percentage: 0
  },
  {
    block_id: 3,
    date: '2025-01-30',
    service_id: 1,
    location_id: 1,
    scheduled_minutes: 420,
    actual_minutes: 390,
    utilization_percentage: 92.9,
    cases_scheduled: 4,
    cases_performed: 4,
    prime_time_percentage: 95,
    non_prime_time_percentage: 5
  }
];

export const mockBlockSchedule = {
  '2025-01-30': [
    {
      block_id: 1,
      room_name: 'OR-1',
      service_name: 'Orthopedics',
      surgeon_name: 'Dr. Sarah Johnson',
      start_time: '2025-01-30T07:30:00',
      end_time: '2025-01-30T11:30:00',
      cases: [
        {
          case_id: 1,
          procedure_name: 'Total Knee Arthroplasty',
          start_time: '2025-01-30T07:30:00',
          duration: 180,
          status: 'In Progress'
        },
        {
          case_id: 2,
          procedure_name: 'Arthroscopic Shoulder Repair',
          start_time: '2025-01-30T10:30:00',
          duration: 120,
          status: 'Scheduled'
        }
      ]
    },
    {
      block_id: 2,
      room_name: 'OR-1',
      service_name: 'Orthopedics',
      surgeon_name: 'Dr. Sarah Johnson',
      start_time: '2025-01-30T12:30:00',
      end_time: '2025-01-30T16:30:00',
      cases: [
        {
          case_id: 5,
          procedure_name: 'Hip Replacement',
          start_time: '2025-01-30T13:30:00',
          duration: 180,
          status: 'Scheduled'
        }
      ]
    },
    {
      block_id: 3,
      room_name: 'OR-2',
      service_name: 'General Surgery',
      surgeon_name: 'Dr. Michael Smith',
      start_time: '2025-01-30T08:00:00',
      end_time: '2025-01-30T16:00:00',
      cases: [
        {
          case_id: 3,
          procedure_name: 'Laparoscopic Cholecystectomy',
          start_time: '2025-01-30T08:00:00',
          duration: 120,
          status: 'In Progress'
        },
        {
          case_id: 4,
          procedure_name: 'Appendectomy',
          start_time: '2025-01-30T10:30:00',
          duration: 90,
          status: 'Scheduled'
        }
      ]
    }
  ]
};

export const mockBlockStatistics = {
  utilization_by_service: [
    { service_name: 'Orthopedics', utilization: 89.6 },
    { service_name: 'General Surgery', utilization: 92.9 },
    { service_name: 'Cardiology', utilization: 85.2 },
    { service_name: 'Neurosurgery', utilization: 78.4 }
  ],
  utilization_by_surgeon: [
    { surgeon_name: 'Dr. Sarah Johnson', utilization: 89.6 },
    { surgeon_name: 'Dr. Michael Smith', utilization: 92.9 },
    { surgeon_name: 'Dr. James Wilson', utilization: 78.4 },
    { surgeon_name: 'Dr. Emily Brown', utilization: 85.2 }
  ],
  released_blocks: [
    {
      block_id: 6,
      room_name: 'OR-5',
      service_name: 'Neurosurgery',
      date: '2025-02-03',
      start_time: '2025-02-03T07:30:00',
      end_time: '2025-02-03T15:30:00',
      release_time: '2025-01-27T10:00:00'
    }
  ]
};
