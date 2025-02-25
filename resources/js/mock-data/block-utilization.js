// Mock data for block utilization analytics
export const mockBlockUtilization = {
  // Overall metrics across all sites and services
  overallMetrics: {
    byService: {
      'Orthopedics': {
        in_block_utilization: 63.5,
        total_block_utilization: 75.4,
        non_prime_percentage: 16.4,
        cases: 1106
      },
      'General Surgery': {
        in_block_utilization: 68.2,
        total_block_utilization: 79.1,
        non_prime_percentage: 14.8,
        cases: 982
      },
      'Neurosurgery': {
        in_block_utilization: 71.3,
        total_block_utilization: 82.7,
        non_prime_percentage: 12.9,
        cases: 543
      },
      'Cardiothoracic': {
        in_block_utilization: 75.8,
        total_block_utilization: 84.2,
        non_prime_percentage: 10.5,
        cases: 421
      },
      'ENT': {
        in_block_utilization: 59.7,
        total_block_utilization: 72.3,
        non_prime_percentage: 18.2,
        cases: 678
      }
    }
  },
  
  // Site-specific data
  sites: {
    'MARH OR': {
      totals: {
        in_block_utilization: 67.8,
        total_block_utilization: 78.3,
        non_prime_percentage: 15.2,
        numof_cases: 2345
      },
      services: [
        {
          service_name: 'Orthopedics',
          in_block_utilization: 65.2,
          total_block_utilization: 76.8,
          non_prime_percentage: 17.3,
          providers: [
            { name: 'Dr. Smith', in_block_utilization: 68.4, total_block_utilization: 79.2, non_prime_percentage: 15.8 },
            { name: 'Dr. Johnson', in_block_utilization: 62.1, total_block_utilization: 74.5, non_prime_percentage: 18.9 }
          ]
        },
        {
          service_name: 'General Surgery',
          in_block_utilization: 70.5,
          total_block_utilization: 81.2,
          non_prime_percentage: 13.7,
          providers: [
            { name: 'Dr. Williams', in_block_utilization: 72.8, total_block_utilization: 83.4, non_prime_percentage: 12.5 },
            { name: 'Dr. Davis', in_block_utilization: 68.2, total_block_utilization: 79.0, non_prime_percentage: 14.9 }
          ]
        },
        {
          service_name: 'Neurosurgery',
          in_block_utilization: 73.4,
          total_block_utilization: 84.1,
          non_prime_percentage: 11.8,
          providers: [
            { name: 'Dr. Brown', in_block_utilization: 75.6, total_block_utilization: 86.3, non_prime_percentage: 10.2 },
            { name: 'Dr. Miller', in_block_utilization: 71.2, total_block_utilization: 81.9, non_prime_percentage: 13.4 }
          ]
        },
        {
          service_name: 'Cardiothoracic',
          in_block_utilization: 77.2,
          total_block_utilization: 85.9,
          non_prime_percentage: 9.8,
          providers: [
            { name: 'Dr. Wilson', in_block_utilization: 79.5, total_block_utilization: 87.2, non_prime_percentage: 8.6 },
            { name: 'Dr. Moore', in_block_utilization: 74.9, total_block_utilization: 84.6, non_prime_percentage: 11.0 }
          ]
        },
        {
          service_name: 'ENT',
          in_block_utilization: 61.3,
          total_block_utilization: 73.8,
          non_prime_percentage: 17.5,
          providers: [
            { name: 'Dr. Taylor', in_block_utilization: 63.7, total_block_utilization: 75.2, non_prime_percentage: 16.1 },
            { name: 'Dr. Anderson', in_block_utilization: 58.9, total_block_utilization: 72.4, non_prime_percentage: 18.9 }
          ]
        }
      ]
    },
    'VORH JRI OR': {
      totals: {
        in_block_utilization: 69.4,
        total_block_utilization: 80.1,
        non_prime_percentage: 14.3,
        numof_cases: 1876
      },
      services: [
        {
          service_name: 'Orthopedics',
          in_block_utilization: 67.8,
          total_block_utilization: 78.5,
          non_prime_percentage: 16.2,
          providers: [
            { name: 'Dr. Thomas', in_block_utilization: 70.1, total_block_utilization: 80.7, non_prime_percentage: 14.8 },
            { name: 'Dr. Jackson', in_block_utilization: 65.5, total_block_utilization: 76.3, non_prime_percentage: 17.6 }
          ]
        },
        {
          service_name: 'General Surgery',
          in_block_utilization: 72.3,
          total_block_utilization: 82.9,
          non_prime_percentage: 12.8,
          providers: [
            { name: 'Dr. White', in_block_utilization: 74.6, total_block_utilization: 84.2, non_prime_percentage: 11.5 },
            { name: 'Dr. Harris', in_block_utilization: 70.0, total_block_utilization: 81.6, non_prime_percentage: 14.1 }
          ]
        },
        {
          service_name: 'Neurosurgery',
          in_block_utilization: 75.1,
          total_block_utilization: 85.7,
          non_prime_percentage: 10.9,
          providers: [
            { name: 'Dr. Martin', in_block_utilization: 77.3, total_block_utilization: 87.0, non_prime_percentage: 9.6 },
            { name: 'Dr. Thompson', in_block_utilization: 72.9, total_block_utilization: 84.4, non_prime_percentage: 12.2 }
          ]
        },
        {
          service_name: 'Cardiothoracic',
          in_block_utilization: 78.9,
          total_block_utilization: 87.5,
          non_prime_percentage: 9.1,
          providers: [
            { name: 'Dr. Garcia', in_block_utilization: 80.2, total_block_utilization: 88.9, non_prime_percentage: 8.0 },
            { name: 'Dr. Martinez', in_block_utilization: 77.6, total_block_utilization: 86.1, non_prime_percentage: 10.2 }
          ]
        },
        {
          service_name: 'ENT',
          in_block_utilization: 63.8,
          total_block_utilization: 75.2,
          non_prime_percentage: 16.7,
          providers: [
            { name: 'Dr. Robinson', in_block_utilization: 65.9, total_block_utilization: 76.8, non_prime_percentage: 15.3 },
            { name: 'Dr. Clark', in_block_utilization: 61.7, total_block_utilization: 73.6, non_prime_percentage: 18.1 }
          ]
        }
      ]
    },
    'VORH Main OR': {
      totals: {
        in_block_utilization: 65.9,
        total_block_utilization: 76.7,
        non_prime_percentage: 16.1,
        numof_cases: 2103
      },
      services: [
        {
          service_name: 'Orthopedics',
          in_block_utilization: 63.4,
          total_block_utilization: 74.9,
          non_prime_percentage: 18.1,
          providers: [
            { name: 'Dr. Lewis', in_block_utilization: 65.7, total_block_utilization: 76.3, non_prime_percentage: 16.8 },
            { name: 'Dr. Lee', in_block_utilization: 61.1, total_block_utilization: 73.5, non_prime_percentage: 19.4 }
          ]
        },
        {
          service_name: 'General Surgery',
          in_block_utilization: 68.9,
          total_block_utilization: 79.4,
          non_prime_percentage: 14.5,
          providers: [
            { name: 'Dr. Walker', in_block_utilization: 71.2, total_block_utilization: 81.0, non_prime_percentage: 13.2 },
            { name: 'Dr. Hall', in_block_utilization: 66.6, total_block_utilization: 77.8, non_prime_percentage: 15.8 }
          ]
        },
        {
          service_name: 'Neurosurgery',
          in_block_utilization: 71.7,
          total_block_utilization: 82.3,
          non_prime_percentage: 12.3,
          providers: [
            { name: 'Dr. Allen', in_block_utilization: 73.9, total_block_utilization: 83.6, non_prime_percentage: 11.0 },
            { name: 'Dr. Young', in_block_utilization: 69.5, total_block_utilization: 81.0, non_prime_percentage: 13.6 }
          ]
        },
        {
          service_name: 'Cardiothoracic',
          in_block_utilization: 75.5,
          total_block_utilization: 84.1,
          non_prime_percentage: 10.2,
          providers: [
            { name: 'Dr. Hernandez', in_block_utilization: 77.8, total_block_utilization: 85.5, non_prime_percentage: 9.0 },
            { name: 'Dr. King', in_block_utilization: 73.2, total_block_utilization: 82.7, non_prime_percentage: 11.4 }
          ]
        },
        {
          service_name: 'ENT',
          in_block_utilization: 59.2,
          total_block_utilization: 71.8,
          non_prime_percentage: 18.9,
          providers: [
            { name: 'Dr. Wright', in_block_utilization: 61.5, total_block_utilization: 73.2, non_prime_percentage: 17.5 },
            { name: 'Dr. Lopez', in_block_utilization: 56.9, total_block_utilization: 70.4, non_prime_percentage: 20.3 }
          ]
        }
      ]
    }
  },
  
  // Day of week data
  dayOfWeek: {
    'Orthopedics': {
      'Monday': 64.2,
      'Tuesday': 67.8,
      'Wednesday': 65.9,
      'Thursday': 66.3,
      'Friday': 61.7,
      'total': 65.2
    },
    'General Surgery': {
      'Monday': 71.3,
      'Tuesday': 73.6,
      'Wednesday': 70.8,
      'Thursday': 72.1,
      'Friday': 68.9,
      'total': 71.3
    },
    'Neurosurgery': {
      'Monday': 74.2,
      'Tuesday': 76.5,
      'Wednesday': 73.8,
      'Thursday': 75.1,
      'Friday': 71.9,
      'total': 74.3
    },
    'Cardiothoracic': {
      'Monday': 78.1,
      'Tuesday': 80.4,
      'Wednesday': 77.7,
      'Thursday': 79.0,
      'Friday': 75.8,
      'total': 78.2
    },
    'ENT': {
      'Monday': 60.5,
      'Tuesday': 62.8,
      'Wednesday': 60.1,
      'Thursday': 61.4,
      'Friday': 58.2,
      'total': 60.6
    }
  },
  
  // Trend data over time
  trends: {
    'VORH JRI OR': {
      utilization: [
        { month: 'Jan', value: 68.2 },
        { month: 'Feb', value: 69.5 },
        { month: 'Mar', value: 70.8 },
        { month: 'Apr', value: 71.3 },
        { month: 'May', value: 72.6 },
        { month: 'Jun', value: 73.9 },
        { month: 'Jul', value: 72.1 },
        { month: 'Aug', value: 70.4 },
        { month: 'Sep', value: 71.7 },
        { month: 'Oct', value: 73.0 },
        { month: 'Nov', value: 74.3 },
        { month: 'Dec', value: 72.5 }
      ],
      nonPrimeTime: [
        { month: 'Jan', value: 15.8 },
        { month: 'Feb', value: 15.2 },
        { month: 'Mar', value: 14.6 },
        { month: 'Apr', value: 14.1 },
        { month: 'May', value: 13.5 },
        { month: 'Jun', value: 12.9 },
        { month: 'Jul', value: 13.4 },
        { month: 'Aug', value: 14.0 },
        { month: 'Sep', value: 13.7 },
        { month: 'Oct', value: 13.2 },
        { month: 'Nov', value: 12.7 },
        { month: 'Dec', value: 13.3 }
      ]
    },
    'MARH OR': {
      utilization: [
        { month: 'Jan', value: 66.5 },
        { month: 'Feb', value: 67.8 },
        { month: 'Mar', value: 69.1 },
        { month: 'Apr', value: 69.6 },
        { month: 'May', value: 70.9 },
        { month: 'Jun', value: 72.2 },
        { month: 'Jul', value: 70.4 },
        { month: 'Aug', value: 68.7 },
        { month: 'Sep', value: 70.0 },
        { month: 'Oct', value: 71.3 },
        { month: 'Nov', value: 72.6 },
        { month: 'Dec', value: 70.8 }
      ],
      nonPrimeTime: [
        { month: 'Jan', value: 16.7 },
        { month: 'Feb', value: 16.1 },
        { month: 'Mar', value: 15.5 },
        { month: 'Apr', value: 15.0 },
        { month: 'May', value: 14.4 },
        { month: 'Jun', value: 13.8 },
        { month: 'Jul', value: 14.3 },
        { month: 'Aug', value: 14.9 },
        { month: 'Sep', value: 14.6 },
        { month: 'Oct', value: 14.1 },
        { month: 'Nov', value: 13.6 },
        { month: 'Dec', value: 14.2 }
      ]
    },
    'VORH Main OR': {
      utilization: [
        { month: 'Jan', value: 64.6 },
        { month: 'Feb', value: 65.9 },
        { month: 'Mar', value: 67.2 },
        { month: 'Apr', value: 67.7 },
        { month: 'May', value: 69.0 },
        { month: 'Jun', value: 70.3 },
        { month: 'Jul', value: 68.5 },
        { month: 'Aug', value: 66.8 },
        { month: 'Sep', value: 68.1 },
        { month: 'Oct', value: 69.4 },
        { month: 'Nov', value: 70.7 },
        { month: 'Dec', value: 68.9 }
      ],
      nonPrimeTime: [
        { month: 'Jan', value: 17.6 },
        { month: 'Feb', value: 17.0 },
        { month: 'Mar', value: 16.4 },
        { month: 'Apr', value: 15.9 },
        { month: 'May', value: 15.3 },
        { month: 'Jun', value: 14.7 },
        { month: 'Jul', value: 15.2 },
        { month: 'Aug', value: 15.8 },
        { month: 'Sep', value: 15.5 },
        { month: 'Oct', value: 15.0 },
        { month: 'Nov', value: 14.5 },
        { month: 'Dec', value: 15.1 }
      ]
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
