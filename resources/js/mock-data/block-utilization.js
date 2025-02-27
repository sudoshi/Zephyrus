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
    inBlockUtilization: '69.5%',
    totalBlockUtilization: '83.5%',
    nonPrimePercentage: '11.6%'
  },
  
  // Service data for charts
  serviceData: [
    {
      name: 'Orthopedics',
      metrics: {
        inBlockUtilization: 74.1,
        totalBlockUtilization: 86.8,
        nonPrimePercentage: 13.5
      }
    },
    {
      name: 'General Surgery',
      metrics: {
        inBlockUtilization: 67.1,
        totalBlockUtilization: 79.3,
        nonPrimePercentage: 17.1
      }
    },
    {
      name: 'Neurosurgery',
      metrics: {
        inBlockUtilization: 72.5,
        totalBlockUtilization: 84.9,
        nonPrimePercentage: 8.6
      }
    },
    {
      name: 'Cardiology',
      metrics: {
        inBlockUtilization: 69.4,
        totalBlockUtilization: 81.2,
        nonPrimePercentage: 11.5
      }
    },
    {
      name: 'Urology',
      metrics: {
        inBlockUtilization: 65.2,
        totalBlockUtilization: 77.8,
        nonPrimePercentage: 14.3
      }
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
  }
};

// Utilization ranges for color coding
export const utilizationRanges = {
  low: { min: 0, max: 50, color: '#ef4444' },
  medium: { min: 50, max: 70, color: '#f59e0b' },
  high: { min: 70, max: 85, color: '#10b981' },
  optimal: { min: 85, max: 100, color: '#3b82f6' }
};
