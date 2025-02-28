// Mock data for Prime Time Capacity Review
export const mockPrimeTimeCapacityReview = {
  // Data for the main table
  sites: {
    'MARH OR': {
      primeTimeCurrent: 70.52,
      primeTimePrevious: 73.09,
      workDuringNonPrimeTimeCurrent: 14.8,
      workDuringNonPrimeTimePrevious: 14.5,
      numOfCasesCurrent: 1110,
      numOfCasesPrevious: 1085,
      potentialCases: 1263,
      additionalCasePotential: 153,
      numOfORsPerWeek: 29.06,
      numOfORsPerWeekPrevious: 28.75,
      numOfORsPerWeekNeeded: 25.55,
      numOfORsPerWeekNeededPrevious: 25.12,
      numOfORDifference: 3.51,
      numOfORDifferencePrevious: 3.63,
      numOfWeekendCases: 22,
      numOfWeekendCasesPrevious: 18,
      numOfORsAvailablePerWeekend: 0.35,
      numOfORsAvailablePerWeekendPrevious: 0.32,
      numOfORsNeededPerWeekend: 0.37,
      numOfORsNeededPerWeekendPrevious: 0.34,
      percentWeekendWorkDuringNonPrimeTime: 38.5,
      percentWeekendWorkDuringNonPrimeTimePrevious: 42.2,
      metricStartDate: '11/1/2024',
      metricEndDate: '1/31/2025',
      metricPreviousStartDate: '8/1/2024',
      metricPreviousEndDate: '10/31/2024'
    },
    'MARH IR': {
      primeTimeCurrent: 68.45,
      primeTimePrevious: 71.22,
      workDuringNonPrimeTimeCurrent: 12.3,
      workDuringNonPrimeTimePrevious: 13.1,
      numOfCasesCurrent: 845,
      numOfCasesPrevious: 812,
      potentialCases: 980,
      additionalCasePotential: 135,
      numOfORsPerWeek: 24.18,
      numOfORsPerWeekPrevious: 23.85,
      numOfORsPerWeekNeeded: 21.33,
      numOfORsPerWeekNeededPrevious: 20.95,
      numOfORDifference: 2.85,
      numOfORDifferencePrevious: 2.90,
      numOfWeekendCases: 18,
      numOfWeekendCasesPrevious: 15,
      numOfORsAvailablePerWeekend: 0.32,
      numOfORsAvailablePerWeekendPrevious: 0.28,
      numOfORsNeededPerWeekend: 0.34,
      numOfORsNeededPerWeekendPrevious: 0.30,
      percentWeekendWorkDuringNonPrimeTime: 35.2,
      percentWeekendWorkDuringNonPrimeTimePrevious: 38.7,
      metricStartDate: '11/1/2024',
      metricEndDate: '1/31/2025',
      metricPreviousStartDate: '8/1/2024',
      metricPreviousEndDate: '10/31/2024'
    }
  },
  
  // Monthly utilization trend data
  utilizationTrend: {
    '2024': [
      { month: 'January', value: 71 },
      { month: 'February', value: 75 },
      { month: 'March', value: 76 },
      { month: 'April', value: 77 },
      { month: 'May', value: 76 },
      { month: 'June', value: 72 },
      { month: 'July', value: 66 },
      { month: 'August', value: 76 },
      { month: 'September', value: 78 },
      { month: 'October', value: 71 },
      { month: 'November', value: 69 },
      { month: 'December', value: 69 }
    ],
    '2025': [
      { month: 'January', value: 78 }
    ]
  },
  
  // Average number of 8-hour ORs per day trend
  orsPerDayTrend: {
    '2024': [
      { month: 'January', value: 4.63 },
      { month: 'February', value: 5.14 },
      { month: 'March', value: 5.04 },
      { month: 'April', value: 4.93 },
      { month: 'May', value: 4.87 },
      { month: 'June', value: 5.09 },
      { month: 'July', value: 5.23 },
      { month: 'August', value: 5.54 },
      { month: 'September', value: 5.08 },
      { month: 'October', value: 5.21 },
      { month: 'November', value: 5.15 },
      { month: 'December', value: 6.04 }
    ],
    '2025': [
      { month: 'January', value: 6.39 }
    ]
  }
};
