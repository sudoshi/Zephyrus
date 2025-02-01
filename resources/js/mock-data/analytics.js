// Generate 30 days of trend data
const generateTrendData = () => {
  const trends = [];
  for (let i = 0; i < 30; i++) {
    const date = new Date();
    date.setDate(date.getDate() - (29 - i));
    trends.push({
      date: date.toISOString().split('T')[0],
      utilization: Math.floor(Math.random() * 20) + 70, // 70-90%
      case_count: Math.floor(Math.random() * 15) + 10,
      turnover: Math.floor(Math.random() * 10) + 25, // 25-35 minutes
      on_time_starts: Math.floor(Math.random() * 15) + 80 // 80-95%
    });
  }
  return trends;
};

// Service performance data
const serviceData = [
  {
    service_id: 1,
    service_name: 'Orthopedics',
    case_count: 245,
    avg_utilization: 85.3,
    avg_turnover: 28.5,
    on_time_start_percentage: 87.5,
    avg_duration: 120
  },
  {
    service_id: 2,
    service_name: 'General Surgery',
    case_count: 312,
    avg_utilization: 83.1,
    avg_turnover: 25.8,
    on_time_start_percentage: 84.2,
    avg_duration: 95
  },
  {
    service_id: 3,
    service_name: 'Cardiology',
    case_count: 178,
    avg_utilization: 81.9,
    avg_turnover: 29.1,
    on_time_start_percentage: 86.1,
    avg_duration: 150
  },
  {
    service_id: 4,
    service_name: 'Neurosurgery',
    case_count: 156,
    avg_utilization: 79.4,
    avg_turnover: 26.7,
    on_time_start_percentage: 83.0,
    avg_duration: 180
  },
  {
    service_id: 5,
    service_name: 'ENT',
    case_count: 289,
    avg_utilization: 78.6,
    avg_turnover: 24.3,
    on_time_start_percentage: 88.5,
    avg_duration: 75
  }
];

// Generate historical trend data (12 months)
const generateHistoricalTrends = () => {
  const trends = [];
  for (let i = 0; i < 12; i++) {
    const date = new Date();
    date.setMonth(date.getMonth() - (11 - i));
    
    // Create realistic seasonal patterns
    const seasonalFactor = Math.sin((i / 11) * Math.PI) * 0.15 + 1; // ±15% seasonal variation
    const baseVolume = 250;
    const baseUtilization = 80;
    
    trends.push({
      date: date.toISOString().split('T')[0],
      total_cases: Math.floor(baseVolume * seasonalFactor + (Math.random() * 30 - 15)),
      utilization: Math.min(95, Math.floor(baseUtilization * seasonalFactor + (Math.random() * 6 - 3))),
      on_time_percentage: Math.floor(82 + (Math.random() * 10 - 5)),
      used_minutes: Math.floor(28800 * (baseUtilization * seasonalFactor / 100)), // 8 hours * 60 minutes * utilization
      available_minutes: 28800 // 8 hours * 60 minutes
    });
  }
  return trends;
};

export const mockPerformanceMetrics = {
  utilization: serviceData,
  trends: generateTrendData(),
  turnover_time: serviceData.reduce((acc, service) => ({
    ...acc,
    [service.service_name]: service.avg_turnover
  }), {}),
  first_case_starts: serviceData.reduce((acc, service) => ({
    ...acc,
    [service.service_name]: service.on_time_start_percentage
  }), {}),
  // Historical trends data
  volume_trends: generateHistoricalTrends(),
  utilization_trends: generateHistoricalTrends(),
  ontime_trends: generateHistoricalTrends()
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
    total_minutes: 28800,
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
  },
  // Historical capacity trends
  trends: generateHistoricalTrends()
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
