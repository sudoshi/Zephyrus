// Mock data for block utilization dashboard
export const mockBlockUtilization = {
  sites: {
    'MARH OR': {
      metrics: {
        inBlockUtilization: '67.8%',
        totalBlockUtilization: '82.3%',
        nonPrimePercentage: '12.4%',
        utilizationTrend: '+3.2%'
      },
      services: [
        {
          service_name: 'Orthopedics',
          in_block_utilization: 72.5,
          total_block_utilization: 85.3,
          non_prime_percentage: 14.2
        },
        {
          service_name: 'General Surgery',
          in_block_utilization: 65.8,
          total_block_utilization: 78.6,
          non_prime_percentage: 18.9
        },
        {
          service_name: 'Neurosurgery',
          in_block_utilization: 71.2,
          total_block_utilization: 83.4,
          non_prime_percentage: 9.7
        },
        {
          service_name: 'Cardiology',
          in_block_utilization: 69.4,
          total_block_utilization: 81.2,
          non_prime_percentage: 11.5
        }
      ]
    },
    'VORH JRI OR': {
      metrics: {
        inBlockUtilization: '71.2%',
        totalBlockUtilization: '84.7%',
        nonPrimePercentage: '10.8%',
        utilizationTrend: '+1.5%'
      },
      services: [
        {
          service_name: 'Orthopedics',
          in_block_utilization: 75.6,
          total_block_utilization: 88.3,
          non_prime_percentage: 12.7
        },
        {
          service_name: 'General Surgery',
          in_block_utilization: 68.4,
          total_block_utilization: 80.1,
          non_prime_percentage: 15.2
        },
        {
          service_name: 'Neurosurgery',
          in_block_utilization: 73.9,
          total_block_utilization: 86.4,
          non_prime_percentage: 7.6
        }
      ]
    }
  },
  
  // Overall metrics across all sites
  overallMetrics: {
    inBlockUtilization: 69.5,
    totalBlockUtilization: 83.5,
    nonPrimePercentage: 11.6
  },
  
  // Service data for charts
  serviceData: [
    {
      name: 'Orthopedics',
      metrics: {
        inBlockUtilization: 74.1,
        totalBlockUtilization: 86.8,
        nonPrimePercentage: 13.5
      },
      sites: ['MARH OR', 'VORH JRI OR']
    },
    {
      name: 'General Surgery',
      metrics: {
        inBlockUtilization: 67.1,
        totalBlockUtilization: 79.3,
        nonPrimePercentage: 17.1
      },
      sites: ['MARH OR', 'VORH JRI OR', 'MEMH OR']
    },
    {
      name: 'Neurosurgery',
      metrics: {
        inBlockUtilization: 72.5,
        totalBlockUtilization: 84.9,
        nonPrimePercentage: 8.6
      },
      sites: ['MARH OR', 'OLLH OR']
    },
    {
      name: 'Cardiology',
      metrics: {
        inBlockUtilization: 69.4,
        totalBlockUtilization: 81.2,
        nonPrimePercentage: 11.5
      },
      sites: ['VORH JRI OR', 'OLLH OR']
    },
    {
      name: 'Urology',
      metrics: {
        inBlockUtilization: 65.2,
        totalBlockUtilization: 77.8,
        nonPrimePercentage: 14.3
      },
      sites: ['MARH OR', 'MEMH OR']
    }
  ],
  
  // Trend data for line charts
  trendData: {
    inBlock: [
      { x: '2025-01-01', y: 63.2 },
      { x: '2025-01-15', y: 65.8 },
      { x: '2025-02-01', y: 68.4 },
      { x: '2025-02-15', y: 67.1 },
      { x: '2025-03-01', y: 69.5 }
    ],
    total: [
      { x: '2025-01-01', y: 76.4 },
      { x: '2025-01-15', y: 78.2 },
      { x: '2025-02-01', y: 81.7 },
      { x: '2025-02-15', y: 79.3 },
      { x: '2025-03-01', y: 83.5 }
    ]
  },
  
  // Day of week data
  dayOfWeekData: [
    { name: 'Monday', utilization: 72.3 },
    { name: 'Tuesday', utilization: 76.5 },
    { name: 'Wednesday', utilization: 69.8 },
    { name: 'Thursday', utilization: 74.1 },
    { name: 'Friday', utilization: 65.7 }
  ],
  
  // Non-prime time trend data
  nonPrimeTimeTrendData: [
    { x: '2025-01-01', y: 13.5 },
    { x: '2025-01-15', y: 12.8 },
    { x: '2025-02-01', y: 11.9 },
    { x: '2025-02-15', y: 12.5 },
    { x: '2025-03-01', y: 11.6 }
  ],
  
  // Service non-prime time data
  serviceNonPrime: {
    'Orthopedics': {
      nonPrime: 13.5,
      prime: 86.5,
      trend: '-1.2%',
      status: 'Improving'
    },
    'General Surgery': {
      nonPrime: 17.1,
      prime: 82.9,
      trend: '+0.8%',
      status: 'Declining'
    },
    'Neurosurgery': {
      nonPrime: 8.6,
      prime: 91.4,
      trend: '-0.5%',
      status: 'Improving'
    },
    'Cardiology': {
      nonPrime: 11.5,
      prime: 88.5,
      trend: '-1.0%',
      status: 'Improving'
    },
    'Urology': {
      nonPrime: 14.3,
      prime: 85.7,
      trend: '+1.5%',
      status: 'Declining'
    }
  },
  
  // Block data for block view
  blockData: [
    {
      name: 'Orthopedics Block 1',
      specialty: 'Orthopedics',
      location: 'MARH OR',
      utilization: 74.5,
      released: false,
      sites: ['MARH OR']
    },
    {
      name: 'Orthopedics Block 2',
      specialty: 'Orthopedics',
      location: 'VORH JRI OR',
      utilization: 78.2,
      released: false,
      sites: ['VORH JRI OR']
    },
    {
      name: 'General Surgery Block 1',
      specialty: 'General Surgery',
      location: 'MARH OR',
      utilization: 67.8,
      released: false,
      sites: ['MARH OR']
    },
    {
      name: 'General Surgery Block 2',
      specialty: 'General Surgery',
      location: 'VORH JRI OR',
      utilization: 68.9,
      released: false,
      sites: ['VORH JRI OR']
    },
    {
      name: 'Neurosurgery Block 1',
      specialty: 'Neurosurgery',
      location: 'MARH OR',
      utilization: 72.1,
      released: true,
      sites: ['MARH OR']
    },
    {
      name: 'Cardiology Block 1',
      specialty: 'Cardiology',
      location: 'VORH JRI OR',
      utilization: 69.7,
      released: false,
      sites: ['VORH JRI OR']
    },
    {
      name: 'Urology Block 1',
      specialty: 'Urology',
      location: 'MARH OR',
      utilization: 65.4,
      released: true,
      sites: ['MARH OR']
    }
  ],
  
  // Location data for location view
  locationData: [
    {
      name: 'MARH OR',
      hospital: 'Memorial Academic Regional Hospital',
      utilization: 67.8,
      totalBlockUtilization: 82.3,
      nonPrimePercentage: 12.4,
      utilizationTrend: '+3.2%',
      specialties: ['Orthopedics', 'General Surgery', 'Neurosurgery', 'Cardiology'],
      services: [
        { service_name: 'Orthopedics', in_block_utilization: 72.5 },
        { service_name: 'General Surgery', in_block_utilization: 65.8 },
        { service_name: 'Neurosurgery', in_block_utilization: 71.2 }
      ]
    },
    {
      name: 'VORH JRI OR',
      hospital: 'Valley Orthopedic Regional Hospital',
      utilization: 71.2,
      totalBlockUtilization: 84.7,
      nonPrimePercentage: 10.8,
      utilizationTrend: '+1.5%',
      specialties: ['Orthopedics', 'General Surgery', 'Neurosurgery'],
      services: [
        { service_name: 'Orthopedics', in_block_utilization: 75.6 },
        { service_name: 'General Surgery', in_block_utilization: 68.4 },
        { service_name: 'Neurosurgery', in_block_utilization: 73.9 }
      ]
    },
    {
      name: 'MEMH OR',
      hospital: 'Memorial East Medical Hospital',
      utilization: 69.5,
      totalBlockUtilization: 83.1,
      nonPrimePercentage: 11.2,
      utilizationTrend: '+0.8%',
      specialties: ['General Surgery', 'Urology'],
      services: [
        { service_name: 'General Surgery', in_block_utilization: 67.1 },
        { service_name: 'Urology', in_block_utilization: 65.2 }
      ]
    },
    {
      name: 'OLLH OR',
      hospital: 'Our Lady of Lourdes Hospital',
      utilization: 70.8,
      totalBlockUtilization: 83.9,
      nonPrimePercentage: 9.7,
      utilizationTrend: '+2.1%',
      specialties: ['Neurosurgery', 'Cardiology'],
      services: [
        { service_name: 'Neurosurgery', in_block_utilization: 72.5 },
        { service_name: 'Cardiology', in_block_utilization: 69.4 }
      ]
    }
  ],
  
  // Non-prime data for non-prime view
  nonPrimeData: {
    weekendCases: 124,
    afterHoursCases: 87,
    trend: [
      { x: '2025-01-01', y: 13.5 },
      { x: '2025-01-15', y: 12.8 },
      { x: '2025-02-01', y: 11.9 },
      { x: '2025-02-15', y: 12.5 },
      { x: '2025-03-01', y: 11.6 }
    ]
  }
};

// Utilization ranges for color coding
export const utilizationRanges = {
  low: { min: 0, max: 50, color: '#ef4444' },
  medium: { min: 50, max: 70, color: '#f59e0b' },
  high: { min: 70, max: 85, color: '#10b981' },
  optimal: { min: 85, max: 100, color: '#3b82f6' }
};
