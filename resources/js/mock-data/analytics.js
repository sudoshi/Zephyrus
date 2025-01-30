export const mockPerformanceMetrics = {
  utilization: {
    current_month: 82.5,
    previous_month: 79.8,
    trend: [
      { date: '2024-12-01', value: 79.8 },
      { date: '2025-01-01', value: 82.5 }
    ],
    by_service: [
      { service: 'Orthopedics', value: 85.3 },
      { service: 'General Surgery', value: 83.1 },
      { service: 'Cardiology', value: 81.9 },
      { service: 'Neurosurgery', value: 79.4 }
    ]
  },
  turnover_time: {
    current_month: 27.5,
    previous_month: 29.2,
    trend: [
      { date: '2024-12-01', value: 29.2 },
      { date: '2025-01-01', value: 27.5 }
    ],
    by_service: [
      { service: 'Orthopedics', value: 28.5 },
      { service: 'General Surgery', value: 25.8 },
      { service: 'Cardiology', value: 29.1 },
      { service: 'Neurosurgery', value: 26.7 }
    ]
  },
  first_case_starts: {
    on_time_percentage: 85.2,
    trend: [
      { date: '2024-12-01', value: 82.1 },
      { date: '2025-01-01', value: 85.2 }
    ],
    by_service: [
      { service: 'Orthopedics', value: 87.5 },
      { service: 'General Surgery', value: 84.2 },
      { service: 'Cardiology', value: 86.1 },
      { service: 'Neurosurgery', value: 83.0 }
    ]
  }
};

export const mockSurgeonScorecard = {
  'Dr. Sarah Johnson': {
    cases_performed: 45,
    avg_duration: 155,
    on_time_starts: 89.5,
    turnover_time: 26.3,
    utilization: 87.2,
    cases_by_type: [
      { type: 'Total Knee Replacement', count: 15 },
      { type: 'Hip Replacement', count: 12 },
      { type: 'Shoulder Arthroscopy', count: 10 },
      { type: 'ACL Repair', count: 8 }
    ],
    historical_trend: [
      { month: '2024-11', utilization: 85.1 },
      { month: '2024-12', utilization: 86.4 },
      { month: '2025-01', utilization: 87.2 }
    ]
  },
  'Dr. Michael Smith': {
    cases_performed: 52,
    avg_duration: 95,
    on_time_starts: 91.2,
    turnover_time: 24.8,
    utilization: 88.9,
    cases_by_type: [
      { type: 'Laparoscopic Cholecystectomy', count: 18 },
      { type: 'Appendectomy', count: 15 },
      { type: 'Hernia Repair', count: 12 },
      { type: 'Breast Biopsy', count: 7 }
    ],
    historical_trend: [
      { month: '2024-11', utilization: 87.2 },
      { month: '2024-12', utilization: 88.1 },
      { month: '2025-01', utilization: 88.9 }
    ]
  }
};

export const mockCapacityAnalysis = {
  block_utilization: {
    allocated_blocks: 120,
    utilized_blocks: 102,
    released_blocks: 8,
    utilization_rate: 85.0,
    by_service: [
      { service: 'Orthopedics', allocated: 35, utilized: 31 },
      { service: 'General Surgery', allocated: 30, utilized: 27 },
      { service: 'Cardiology', allocated: 25, utilized: 20 },
      { service: 'Neurosurgery', allocated: 30, utilized: 24 }
    ]
  },
  prime_time_utilization: {
    total_minutes: 28800, // 8 hours * 60 minutes * 6 rooms
    utilized_minutes: 24480,
    utilization_rate: 85.0,
    by_room: [
      { room: 'OR-1', utilization: 88.5 },
      { room: 'OR-2', utilization: 86.2 },
      { room: 'OR-3', utilization: 84.7 },
      { room: 'OR-4', utilization: 83.9 },
      { room: 'OR-5', utilization: 82.1 },
      { room: 'OR-6', utilization: 84.5 }
    ]
  },
  case_volume_trends: {
    current_month: 285,
    previous_month: 272,
    by_service: [
      {
        service: 'Orthopedics',
        trend: [
          { month: '2024-11', volume: 82 },
          { month: '2024-12', volume: 85 },
          { month: '2025-01', volume: 88 }
        ]
      },
      {
        service: 'General Surgery',
        trend: [
          { month: '2024-11', volume: 75 },
          { month: '2024-12', volume: 78 },
          { month: '2025-01', volume: 82 }
        ]
      }
    ]
  }
};

export const mockEfficiencyMetrics = {
  scheduling_accuracy: {
    overall: 85.2,
    by_service: [
      { service: 'Orthopedics', accuracy: 87.5 },
      { service: 'General Surgery', accuracy: 84.2 },
      { service: 'Cardiology', accuracy: 86.1 },
      { service: 'Neurosurgery', accuracy: 83.0 }
    ]
  },
  room_turnover: {
    average_time: 27.5,
    target_time: 30,
    by_service: [
      { service: 'Orthopedics', time: 28.5 },
      { service: 'General Surgery', time: 25.8 },
      { service: 'Cardiology', time: 29.1 },
      { service: 'Neurosurgery', time: 26.7 }
    ],
    trend: [
      { date: '2024-11-01', time: 29.2 },
      { date: '2024-12-01', time: 28.4 },
      { date: '2025-01-01', time: 27.5 }
    ]
  },
  case_delays: {
    total_delays: 45,
    by_reason: [
      { reason: 'Late Patient Arrival', count: 15 },
      { reason: 'Equipment Issues', count: 12 },
      { reason: 'Staff Availability', count: 10 },
      { reason: 'Previous Case Overrun', count: 8 }
    ],
    trend: [
      { date: '2024-11-01', delays: 52 },
      { date: '2024-12-01', delays: 48 },
      { date: '2025-01-01', delays: 45 }
    ]
  }
};
