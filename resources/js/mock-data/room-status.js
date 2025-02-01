export const mockRoomStatus = [
  {
    room_id: 1,
    room_name: 'OR-1',
    status: 'In Progress',
    or_in_time: '2025-01-31T07:35:00',
    current_case: {
      case_id: 1,
      procedure_name: 'Total Knee Arthroplasty',
      surgeon_name: 'Dr. Sarah Johnson',
      service_name: 'Orthopedics',
      estimated_duration: 180,
      actual_start_time: '2025-01-31T07:35:00',
      actual_end_time: null,
      status: 'In Progress'
    },
    next_case: {
      case_id: 5,
      procedure_name: 'Hip Replacement',
      surgeon_name: 'Dr. Sarah Johnson',
      service_name: 'Orthopedics',
      scheduled_start_time: '2025-01-31T13:30:00',
      estimated_duration: 180,
      status: 'Scheduled'
    },
    utilization: {
      today: 85,
      week: 82,
      month: 80
    },
    turnover_stats: {
      last: 25,
      average: 28,
      target: 30
    }
  },
  {
    room_id: 2,
    room_name: 'OR-2',
    status: 'In Progress',
    or_in_time: '2025-01-31T08:10:00',
    current_case: {
      case_id: 2,
      procedure_name: 'Laparoscopic Cholecystectomy',
      surgeon_name: 'Dr. Michael Smith',
      service_name: 'General Surgery',
      estimated_duration: 120,
      actual_start_time: '2025-01-31T08:10:00',
      actual_end_time: null,
      status: 'In Progress'
    },
    next_case: {
      case_id: 6,
      procedure_name: 'Appendectomy',
      surgeon_name: 'Dr. Michael Smith',
      service_name: 'General Surgery',
      scheduled_start_time: '2025-01-31T10:30:00',
      estimated_duration: 90,
      status: 'Scheduled'
    },
    utilization: {
      today: 78,
      week: 75,
      month: 77
    },
    turnover_stats: {
      last: 32,
      average: 30,
      target: 30
    }
  },
  {
    room_id: 3,
    room_name: 'OR-3',
    status: 'Turnover',
    or_in_time: null,
    last_case_out: '2025-01-31T10:00:00',
    turnover_start: '2025-01-31T10:05:00',
    next_case: {
      case_id: 3,
      procedure_name: 'Spinal Fusion',
      surgeon_name: 'Dr. James Wilson',
      service_name: 'Neurosurgery',
      scheduled_start_time: '2025-01-31T10:30:00',
      estimated_duration: 240,
      status: 'Scheduled'
    },
    utilization: {
      today: 65,
      week: 70,
      month: 72
    },
    turnover_stats: {
      last: 28,
      average: 32,
      target: 30
    }
  },
  {
    room_id: 4,
    room_name: 'OR-4',
    status: 'Available',
    or_in_time: null,
    next_case: {
      case_id: 4,
      procedure_name: 'Coronary Artery Bypass',
      surgeon_name: 'Dr. Emily Brown',
      service_name: 'Cardiology',
      scheduled_start_time: '2025-01-31T11:00:00',
      estimated_duration: 300,
      status: 'Scheduled'
    },
    utilization: {
      today: 0,
      week: 68,
      month: 71
    },
    turnover_stats: {
      last: 35,
      average: 33,
      target: 30
    }
  },
  {
    room_id: 5,
    room_name: 'OR-5',
    status: 'Delayed',
    or_in_time: null,
    delay_reason: 'Equipment Issue',
    delay_start: '2025-01-31T07:30:00',
    next_case: {
      case_id: 7,
      procedure_name: 'Total Hip Replacement',
      surgeon_name: 'Dr. Sarah Johnson',
      service_name: 'Orthopedics',
      scheduled_start_time: '2025-01-31T07:30:00',
      estimated_duration: 180,
      status: 'Delayed'
    },
    utilization: {
      today: 0,
      week: 73,
      month: 75
    },
    turnover_stats: {
      last: 27,
      average: 29,
      target: 30
    }
  }
];

export const mockRoomStatusHistory = [
  {
    room_id: 1,
    date: '2025-01-31',
    events: [
      {
        type: 'Case Start',
        time: '2025-01-31T07:35:00',
        details: 'Total Knee Arthroplasty started'
      },
      {
        type: 'Milestone',
        time: '2025-01-31T08:30:00',
        details: 'Patient positioned and prepped'
      }
    ]
  },
  {
    room_id: 2,
    date: '2025-01-31',
    events: [
      {
        type: 'Case Start',
        time: '2025-01-31T08:10:00',
        details: 'Laparoscopic Cholecystectomy started'
      }
    ]
  },
  {
    room_id: 3,
    date: '2025-01-31',
    events: [
      {
        type: 'Case End',
        time: '2025-01-31T10:00:00',
        details: 'Previous case completed'
      },
      {
        type: 'Turnover Start',
        time: '2025-01-31T10:05:00',
        details: 'Room turnover initiated'
      }
    ]
  },
  {
    room_id: 5,
    date: '2025-01-31',
    events: [
      {
        type: 'Delay',
        time: '2025-01-31T07:30:00',
        details: 'Equipment issue reported'
      }
    ]
  }
];

export const mockRoomMetrics = {
  overall_utilization: 75.2,
  rooms_in_use: 2,
  rooms_available: 1,
  rooms_turnover: 1,
  rooms_delayed: 1,
  average_turnover: 30.4,
  delays_today: 1,
  on_time_starts: 2,
  late_starts: 1
};
