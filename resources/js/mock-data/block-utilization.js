// Mock data for block utilization dashboard
export const mockBlockUtilization = {
  // Overall metrics across all sites
  overallMetrics: {
    byService: {
      'VSG General/Vascular/Colorectal/Thoracic': {
        cases: 1211,
        in_block: 104049,
        prime_out_block: 27286,
        total_non_prime: 25832,
        non_prime_percentage: 16.44,
        block_time: 163920,
        in_block_utilization: 63.48,
        total_block_utilization: 80.12
      },
      'Orthopedic Surgery': {
        cases: 1170,
        in_block: 121310,
        prime_out_block: 37252,
        total_non_prime: 21575,
        non_prime_percentage: 11.98,
        block_time: 216780,
        in_block_utilization: 55.96,
        total_block_utilization: 73.14
      },
      'Urology': {
        cases: 967,
        in_block: 74953,
        prime_out_block: 16426,
        total_non_prime: 18562,
        non_prime_percentage: 16.88,
        block_time: 129390,
        in_block_utilization: 57.93,
        total_block_utilization: 70.62
      },
      'Obstetrics and Gynecology': {
        cases: 959,
        in_block: 53214,
        prime_out_block: 53035,
        total_non_prime: 13624,
        non_prime_percentage: 11.37,
        block_time: 106530,
        in_block_utilization: 49.95,
        total_block_utilization: 99.74
      }
    }
  },

  // Site-specific data
  sites: {
    'MARH OR': {
      services: [
        {
          service_id: 1,
          service_name: 'Bariatrics',
          numof_cases: 39,
          before_block_start: 66,
          in_block: 3427,
          overusage: 0,
          out_of_block: 2761,
          after_block_finish: 344,
          non_prime_percentage: 6.21,
          block_time: 12210,
          in_block_utilization: 28.07,
          total_block_utilization: 50.68,
          by_week: {
            1: { utilization: 68.17, cases: 7 },
            2: { utilization: 46.27, cases: 0 },
            3: { utilization: 38.24, cases: 3 },
            4: { utilization: 44.97, cases: 0 },
            5: { utilization: 0.00, cases: 0 }
          }
        },
        // ... existing MARH OR services ...
      ],
      totals: {
        numof_cases: 1106,
        before_block_start: 1634,
        in_block: 85874,
        overusage: 13476,
        out_of_block: 22236,
        after_block_finish: 19260,
        non_prime_percentage: 14.66,
        block_time: 161370,
        in_block_utilization: 53.22,
        total_block_utilization: 75.35
      }
    },
    'MEMH MHAS OR': {
      services: [
        {
          service_name: 'Obstetrics and Gynecology',
          providers: [
            {
              name: 'ADVO BURLINGTON OB/GYN',
              metrics: {
                cases: 45,
                utilization: 52.13,
                by_day: {
                  Tuesday: { cases: 12, utilization: 58.47 },
                  Wednesday: { cases: 2, utilization: 19.51 },
                  Thursday: { cases: 9, utilization: 45.82 },
                  Friday: { cases: 22, utilization: 63.73 }
                }
              }
            },
            {
              name: 'VIRTUA OB/GYN NORTH',
              metrics: {
                cases: 123,
                utilization: 83.54,
                by_day: {
                  Tuesday: { cases: 10, utilization: 62.78 },
                  Wednesday: { cases: 14, utilization: 43.65 },
                  Thursday: { cases: 49, utilization: 217.09 },
                  Friday: { cases: 50, utilization: 63.51 }
                }
              }
            }
          ]
        },
        {
          service_name: 'Orthopedic Surgery',
          providers: [
            {
              name: 'B. C. ORTHO',
              metrics: {
                cases: 31,
                utilization: 32.15,
                by_day: {
                  Tuesday: { cases: 20, utilization: 33.16 },
                  Thursday: { cases: 11, utilization: 30.45 }
                }
              }
            }
          ]
        }
      ]
    },
    'VORH JRI OR': {
      services: {
        'Breast Surgery': {
          providers: {
            'VIRTUA BREAST CARE - VOORHEES': {
              metrics: {
                by_month: {
                  '2024-10': { cases: 27, utilization: 86.42 },
                  '2024-11': { cases: 21, utilization: 77.99 },
                  '2024-12': { cases: 19, utilization: 83.95 }
                }
              }
            }
          }
        },
        'Colon and Rectal Surgery': {
          providers: {
            'VSG COLORECTAL MOORESTOWN': {
              metrics: {
                by_month: {
                  '2024-10': { cases: 14, utilization: 79.38 },
                  '2024-11': { cases: 11, utilization: 57.50 },
                  '2024-12': { cases: 15, utilization: 80.00 }
                }
              }
            }
          }
        }
      }
    }
  },

  // Day of Week Utilization
  dayOfWeek: {
    'VSG General/Vascular/Colorectal/Thoracic': {
      Monday: 88.60,
      Tuesday: 81.15,
      Wednesday: 58.89,
      Thursday: 86.21,
      Friday: 92.89,
      total: 81.26
    },
    'Urology': {
      Monday: 95.29,
      Tuesday: 48.95,
      Wednesday: 86.41,
      Thursday: null,
      Friday: 44.47,
      total: 85.75
    },
    'OPEN': {
      Monday: 0.00,
      Tuesday: null,
      Wednesday: null,
      Thursday: 0.00,
      Friday: 0.00,
      total: 0.00
    }
  },

  // Trend Data
  trends: {
    'VORH JRI OR': {
      utilization: [
        { month: 'Oct 2024', value: 75.9 },
        { month: 'Nov 2024', value: 75.5 },
        { month: 'Dec 2024', value: 67.8 }
      ],
      nonPrimeTime: [
        { month: 'Oct 2024', value: 14.4 },
        { month: 'Nov 2024', value: 14.4 },
        { month: 'Dec 2024', value: 16.8 }
      ],
      comparative: {
        current: {
          nonPrimeTime: 16.8,
          primeTimeUtil: 73.1
        },
        previous: {
          nonPrimeTime: 16.8,
          primeTimeUtil: 73.1
        }
      }
    }
  },

  // Provider Details with enhanced metrics
  providers: {
    'WASSER, SAMUEL': {
      service: 'Bariatrics',
      metrics: {
        cases: {
          total: 73,
          by_week: {
            1: { count: 7, utilization: 68.17 },
            2: { count: 0, utilization: 46.27 },
            3: { count: 3, utilization: 38.24 },
            4: { count: 0, utilization: 44.97 }
          }
        },
        block_time: 14580,
        in_block_utilization: 66.58,
        total_block_utilization: 70.21,
        non_prime_percentage: 12.88,
        by_month: {
          '2024-10': { utilization: 68.17, cases: 25 },
          '2024-11': { utilization: 71.75, cases: 23 },
          '2024-12': { utilization: 84.76, cases: 25 }
        }
      }
    },
    'VIRTUA BREAST CARE - VOORH': {
      service: 'Breast Surgery',
      metrics: {
        cases: {
          total: 117,
          by_week: {
            1: { count: 30, utilization: 70.04 },
            2: { count: 28, utilization: 75.12 },
            3: { count: 32, utilization: 80.45 },
            4: { count: 27, utilization: 68.89 }
          }
        },
        block_time: 14160,
        in_block_utilization: 70.04,
        total_block_utilization: 121.63,
        non_prime_percentage: 5.11,
        by_month: {
          '2024-10': { utilization: 86.42, cases: 27 },
          '2024-11': { utilization: 77.99, cases: 21 },
          '2024-12': { utilization: 83.95, cases: 19 }
        }
      }
    }
  }
};

// Utilization range colors
export const utilizationRanges = {
  low: { min: 0, max: 35, color: '#ff9999' },    // Light red
  medium: { min: 35, max: 65, color: '#ffcc99' }, // Light orange
  high: { min: 65, max: 100, color: '#99ff99' },  // Light green
  noBlock: { color: '#e6e6e6' }                   // Light gray
};
