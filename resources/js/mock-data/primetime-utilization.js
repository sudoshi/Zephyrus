// Mock data for Primetime Utilization dashboard
export const mockPrimetimeUtilization = {
  // Overall metrics across all sites
  overallMetrics: {
    primeTimeUtilization: 72.8,
    nonPrimeTimePercentage: 15.6,
    totalCases: 4307,
    casesInPrimeTime: 3845,
    casesInNonPrimeTime: 462
  },

  // Monthly utilization data for all locations
  utilizationData: [
    { month: 'Jan 23', marhIR: 56.16, marhOR: 73.89, nonPrimeIR: 0.00, nonPrimeOR: 16.43 },
    { month: 'Mar 23', marhIR: 54.39, marhOR: 77.20, nonPrimeIR: 0.00, nonPrimeOR: 17.41 },
    { month: 'May 23', marhIR: 70.33, marhOR: 77.46, nonPrimeIR: 0.00, nonPrimeOR: 17.25 },
    { month: 'Jul 23', marhIR: 65.37, marhOR: 76.78, nonPrimeIR: 0.00, nonPrimeOR: 17.21 },
    { month: 'Sep 23', marhIR: 73.77, marhOR: 66.56, nonPrimeIR: 0.00, nonPrimeOR: 17.18 },
    { month: 'Nov 23', marhIR: 55.08, marhOR: 69.47, nonPrimeIR: 0.00, nonPrimeOR: 17.14 },
    { month: 'Jan 24', marhIR: 69.04, marhOR: 76.78, nonPrimeIR: 0.85, nonPrimeOR: 15.80 },
    { month: 'Mar 24', marhIR: 57.71, marhOR: 75.30, nonPrimeIR: 0.53, nonPrimeOR: 15.52 },
    { month: 'May 24', marhIR: 57.25, marhOR: 68.04, nonPrimeIR: 0.00, nonPrimeOR: 15.50 },
    { month: 'Jul 24', marhIR: 62.18, marhOR: 71.35, nonPrimeIR: 0.42, nonPrimeOR: 15.47 },
    { month: 'Sep 24', marhIR: 68.92, marhOR: 74.82, nonPrimeIR: 0.38, nonPrimeOR: 15.43 },
    { month: 'Nov 24', marhIR: 65.47, marhOR: 73.56, nonPrimeIR: 0.35, nonPrimeOR: 15.40 }
  ],

  // Site-specific data
  sites: {
    'MARH OR': {
      primeTimeUtilization: 73.6,
      nonPrimeTimePercentage: 15.4,
      totalCases: 1106,
      casesInPrimeTime: 987,
      casesInNonPrimeTime: 119,
      trends: {
        primeTimeUtilization: [
          { month: 'Jul 24', value: 71.35 },
          { month: 'Aug 24', value: 72.64 },
          { month: 'Sep 24', value: 74.82 },
          { month: 'Oct 24', value: 73.95 },
          { month: 'Nov 24', value: 73.56 },
          { month: 'Dec 24', value: 73.89 }
        ],
        nonPrimeTimePercentage: [
          { month: 'Jul 24', value: 15.47 },
          { month: 'Aug 24', value: 15.45 },
          { month: 'Sep 24', value: 15.43 },
          { month: 'Oct 24', value: 15.42 },
          { month: 'Nov 24', value: 15.40 },
          { month: 'Dec 24', value: 15.38 }
        ]
      }
    },
    'MARH IR': {
      primeTimeUtilization: 65.5,
      nonPrimeTimePercentage: 0.4,
      totalCases: 287,
      casesInPrimeTime: 285,
      casesInNonPrimeTime: 2,
      trends: {
        primeTimeUtilization: [
          { month: 'Jul 24', value: 62.18 },
          { month: 'Aug 24', value: 65.55 },
          { month: 'Sep 24', value: 68.92 },
          { month: 'Oct 24', value: 67.20 },
          { month: 'Nov 24', value: 65.47 },
          { month: 'Dec 24', value: 66.83 }
        ],
        nonPrimeTimePercentage: [
          { month: 'Jul 24', value: 0.42 },
          { month: 'Aug 24', value: 0.40 },
          { month: 'Sep 24', value: 0.38 },
          { month: 'Oct 24', value: 0.37 },
          { month: 'Nov 24', value: 0.35 },
          { month: 'Dec 24', value: 0.33 }
        ]
      }
    },
    'MEMH OR': {
      primeTimeUtilization: 75.2,
      nonPrimeTimePercentage: 16.8,
      totalCases: 1245,
      casesInPrimeTime: 1098,
      casesInNonPrimeTime: 147,
      trends: {
        primeTimeUtilization: [
          { month: 'Jul 24', value: 73.45 },
          { month: 'Aug 24', value: 74.32 },
          { month: 'Sep 24', value: 75.19 },
          { month: 'Oct 24', value: 74.87 },
          { month: 'Nov 24', value: 75.54 },
          { month: 'Dec 24', value: 76.21 }
        ],
        nonPrimeTimePercentage: [
          { month: 'Jul 24', value: 17.12 },
          { month: 'Aug 24', value: 16.95 },
          { month: 'Sep 24', value: 16.78 },
          { month: 'Oct 24', value: 16.61 },
          { month: 'Nov 24', value: 16.44 },
          { month: 'Dec 24', value: 16.27 }
        ]
      }
    },
    'MEMH MHAS OR': {
      primeTimeUtilization: 68.7,
      nonPrimeTimePercentage: 12.5,
      totalCases: 423,
      casesInPrimeTime: 392,
      casesInNonPrimeTime: 31,
      trends: {
        primeTimeUtilization: [
          { month: 'Jul 24', value: 66.85 },
          { month: 'Aug 24', value: 67.78 },
          { month: 'Sep 24', value: 68.71 },
          { month: 'Oct 24', value: 69.64 },
          { month: 'Nov 24', value: 70.57 },
          { month: 'Dec 24', value: 71.50 }
        ],
        nonPrimeTimePercentage: [
          { month: 'Jul 24', value: 13.10 },
          { month: 'Aug 24', value: 12.80 },
          { month: 'Sep 24', value: 12.50 },
          { month: 'Oct 24', value: 12.20 },
          { month: 'Nov 24', value: 11.90 },
          { month: 'Dec 24', value: 11.60 }
        ]
      }
    },
    'VORH OR': {
      primeTimeUtilization: 71.8,
      nonPrimeTimePercentage: 14.9,
      totalCases: 956,
      casesInPrimeTime: 856,
      casesInNonPrimeTime: 100,
      trends: {
        primeTimeUtilization: [
          { month: 'Jul 24', value: 69.95 },
          { month: 'Aug 24', value: 70.88 },
          { month: 'Sep 24', value: 71.81 },
          { month: 'Oct 24', value: 72.74 },
          { month: 'Nov 24', value: 73.67 },
          { month: 'Dec 24', value: 74.60 }
        ],
        nonPrimeTimePercentage: [
          { month: 'Jul 24', value: 15.50 },
          { month: 'Aug 24', value: 15.30 },
          { month: 'Sep 24', value: 15.10 },
          { month: 'Oct 24', value: 14.90 },
          { month: 'Nov 24', value: 14.70 },
          { month: 'Dec 24', value: 14.50 }
        ]
      }
    },
    'VORH JRI OR': {
      primeTimeUtilization: 76.8,
      nonPrimeTimePercentage: 17.2,
      totalCases: 290,
      casesInPrimeTime: 227,
      casesInNonPrimeTime: 63,
      trends: {
        primeTimeUtilization: [
          { month: 'Jul 24', value: 74.95 },
          { month: 'Aug 24', value: 75.88 },
          { month: 'Sep 24', value: 76.81 },
          { month: 'Oct 24', value: 77.74 },
          { month: 'Nov 24', value: 78.67 },
          { month: 'Dec 24', value: 79.60 }
        ],
        nonPrimeTimePercentage: [
          { month: 'Jul 24', value: 17.80 },
          { month: 'Aug 24', value: 17.60 },
          { month: 'Sep 24', value: 17.40 },
          { month: 'Oct 24', value: 17.20 },
          { month: 'Nov 24', value: 17.00 },
          { month: 'Dec 24', value: 16.80 }
        ]
      }
    }
  },

  // Day of week data
  weekdayData: {
    'MARH IR': {
      Monday: { utilization: 94.27, nonPrime: 0.00 },
      Tuesday: { utilization: 41.46, nonPrime: 0.00 },
      Wednesday: { utilization: 36.46, nonPrime: 0.00 },
      Thursday: { utilization: 53.75, nonPrime: 0.00 },
      Friday: { utilization: 49.11, nonPrime: 0.00 }
    },
    'MARH OR': {
      Monday: { utilization: 74.20, nonPrime: 20.77 },
      Tuesday: { utilization: 78.43, nonPrime: 15.04 },
      Wednesday: { utilization: 77.41, nonPrime: 15.17 },
      Thursday: { utilization: 69.92, nonPrime: 20.08 },
      Friday: { utilization: 83.64, nonPrime: 16.26 }
    },
    'MEMH OR': {
      Monday: { utilization: 76.35, nonPrime: 21.45 },
      Tuesday: { utilization: 80.58, nonPrime: 16.72 },
      Wednesday: { utilization: 79.56, nonPrime: 16.85 },
      Thursday: { utilization: 72.07, nonPrime: 21.76 },
      Friday: { utilization: 85.79, nonPrime: 17.94 }
    },
    'MEMH MHAS OR': {
      Monday: { utilization: 69.85, nonPrime: 14.95 },
      Tuesday: { utilization: 74.08, nonPrime: 10.22 },
      Wednesday: { utilization: 73.06, nonPrime: 10.35 },
      Thursday: { utilization: 65.57, nonPrime: 15.26 },
      Friday: { utilization: 79.29, nonPrime: 11.44 }
    },
    'VORH OR': {
      Monday: { utilization: 72.05, nonPrime: 18.15 },
      Tuesday: { utilization: 76.28, nonPrime: 13.42 },
      Wednesday: { utilization: 75.26, nonPrime: 13.55 },
      Thursday: { utilization: 67.77, nonPrime: 18.46 },
      Friday: { utilization: 81.49, nonPrime: 14.64 }
    },
    'VORH JRI OR': {
      Monday: { utilization: 77.05, nonPrime: 20.45 },
      Tuesday: { utilization: 81.28, nonPrime: 15.72 },
      Wednesday: { utilization: 80.26, nonPrime: 15.85 },
      Thursday: { utilization: 72.77, nonPrime: 20.76 },
      Friday: { utilization: 86.49, nonPrime: 16.94 }
    }
  },

  // Service-specific data
  services: {
    'Bariatrics': {
      primeTimeUtilization: 76.8,
      nonPrimeTimePercentage: 18.2,
      totalCases: 312,
      casesInPrimeTime: 267,
      casesInNonPrimeTime: 45
    },
    'Breast Surgery': {
      primeTimeUtilization: 73.2,
      nonPrimeTimePercentage: 14.6,
      totalCases: 287,
      casesInPrimeTime: 258,
      casesInNonPrimeTime: 29
    },
    'Colon and Rectal Surgery': {
      primeTimeUtilization: 78.5,
      nonPrimeTimePercentage: 19.8,
      totalCases: 245,
      casesInPrimeTime: 203,
      casesInNonPrimeTime: 42
    },
    'General Surgery': {
      primeTimeUtilization: 75.1,
      nonPrimeTimePercentage: 16.5,
      totalCases: 356,
      casesInPrimeTime: 312,
      casesInNonPrimeTime: 44
    },
    'Gynecologic Oncology': {
      primeTimeUtilization: 77.9,
      nonPrimeTimePercentage: 19.2,
      totalCases: 198,
      casesInPrimeTime: 167,
      casesInNonPrimeTime: 31
    },
    'Obstetrics and Gynecology': {
      primeTimeUtilization: 72.4,
      nonPrimeTimePercentage: 13.8,
      totalCases: 423,
      casesInPrimeTime: 382,
      casesInNonPrimeTime: 41
    },
    'Orthopaedic Surgery': {
      primeTimeUtilization: 79.8,
      nonPrimeTimePercentage: 21.1,
      totalCases: 378,
      casesInPrimeTime: 312,
      casesInNonPrimeTime: 66
    },
    'Otolaryngology': {
      primeTimeUtilization: 74.3,
      nonPrimeTimePercentage: 15.7,
      totalCases: 256,
      casesInPrimeTime: 227,
      casesInNonPrimeTime: 29
    },
    'Plastic Surgery': {
      primeTimeUtilization: 71.6,
      nonPrimeTimePercentage: 13.0,
      totalCases: 234,
      casesInPrimeTime: 214,
      casesInNonPrimeTime: 20
    },
    'Urology': {
      primeTimeUtilization: 73.7,
      nonPrimeTimePercentage: 15.1,
      totalCases: 289,
      casesInPrimeTime: 258,
      casesInNonPrimeTime: 31
    },
    'Vascular Surgery': {
      primeTimeUtilization: 76.2,
      nonPrimeTimePercentage: 17.6,
      totalCases: 267,
      casesInPrimeTime: 230,
      casesInNonPrimeTime: 37
    }
  },

  // Provider-specific data
  providers: {
    'WASSER, SAMUEL': {
      service: 'Bariatrics',
      primeTimeUtilization: 78.2,
      nonPrimeTimePercentage: 19.6,
      totalCases: 73,
      casesInPrimeTime: 62,
      casesInNonPrimeTime: 11
    },
    'VIRTUA BREAST CARE - VOORH': {
      service: 'Breast Surgery',
      primeTimeUtilization: 75.8,
      nonPrimeTimePercentage: 17.2,
      totalCases: 117,
      casesInPrimeTime: 102,
      casesInNonPrimeTime: 15
    },
    'VSG COLORECTAL MOORESTOWN': {
      service: 'Colon and Rectal Surgery',
      primeTimeUtilization: 80.1,
      nonPrimeTimePercentage: 21.5,
      totalCases: 89,
      casesInPrimeTime: 73,
      casesInNonPrimeTime: 16
    },
    'VIRTUA SURGICAL GROUP - MARLTON': {
      service: 'General Surgery',
      primeTimeUtilization: 76.7,
      nonPrimeTimePercentage: 18.1,
      totalCases: 124,
      casesInPrimeTime: 107,
      casesInNonPrimeTime: 17
    },
    'VIRTUA GYNECOLOGIC ONCOLOGY - VOORHEES': {
      service: 'Gynecologic Oncology',
      primeTimeUtilization: 79.5,
      nonPrimeTimePercentage: 20.9,
      totalCases: 82,
      casesInPrimeTime: 68,
      casesInNonPrimeTime: 14
    },
    'VIRTUA OB/GYN - VOORHEES': {
      service: 'Obstetrics and Gynecology',
      primeTimeUtilization: 74.0,
      nonPrimeTimePercentage: 15.4,
      totalCases: 156,
      casesInPrimeTime: 139,
      casesInNonPrimeTime: 17
    },
    'VIRTUA RECONSTRUCTIVE ORTHOPEDICS - VOORHEES': {
      service: 'Orthopaedic Surgery',
      primeTimeUtilization: 81.4,
      nonPrimeTimePercentage: 22.8,
      totalCases: 142,
      casesInPrimeTime: 115,
      casesInNonPrimeTime: 27
    }
  },

  // Service Analysis data
  serviceAnalysis: {
    'Grand Total': {
      primeTimeCurrent: 70.52,
      primeTimePrevious: 73.09,
      workDuringPrimeTimeCurrent: 14.8,
      workDuringPrimeTimePrevious: 14.5,
      numOfCasesCurrent: 1110,
      potentialCases: 1263,
      additionalCasePotential: 153,
      ORsPerWeekAvailable: 29.06,
      ORsPerWeekNeeded: 25.55,
      ORDifference: -3.51,
      numOfCasesWeekend: 22,
      ORsNeededPerWeekend: 0.37,
      percentWeekendWork: 38.5
    },
    'VSG General / Vascular / Colorectal': {
      primeTimeCurrent: 81.79,
      primeTimePrevious: 85.63,
      workDuringPrimeTimeCurrent: 15.8,
      workDuringPrimeTimePrevious: 12.5,
      numOfCasesCurrent: 626,
      potentialCases: 616,
      additionalCasePotential: -10,
      ORsPerWeekAvailable: 14.90,
      ORsPerWeekNeeded: 15.37,
      ORDifference: 0.48,
      numOfCasesWeekend: 12,
      ORsNeededPerWeekend: 0.23,
      percentWeekendWork: 39.3
    },
    'Urology': {
      primeTimeCurrent: 116.62,
      primeTimePrevious: 115.63,
      workDuringPrimeTimeCurrent: 12.9,
      workDuringPrimeTimePrevious: 12.0,
      numOfCasesCurrent: 267,
      potentialCases: 202,
      additionalCasePotential: -65,
      ORsPerWeekAvailable: 3.07,
      ORsPerWeekNeeded: 4.37,
      ORDifference: 1.30,
      numOfCasesWeekend: 8,
      ORsNeededPerWeekend: 0.10,
      percentWeekendWork: 24.6
    },
    'Neurosurgery': {
      primeTimeCurrent: 76.85,
      primeTimePrevious: 54.33,
      workDuringPrimeTimeCurrent: 9.7,
      workDuringPrimeTimePrevious: 15.2,
      numOfCasesCurrent: 46,
      potentialCases: 54,
      additionalCasePotential: 8,
      ORsPerWeekAvailable: 1.56,
      ORsPerWeekNeeded: 1.41,
      ORDifference: -0.15,
      numOfCasesWeekend: 0,
      ORsNeededPerWeekend: 0.00,
      percentWeekendWork: 0.0
    },
    'Podiatry': {
      primeTimeCurrent: 48.6,
      primeTimePrevious: 56.3,
      workDuringPrimeTimeCurrent: null,
      workDuringPrimeTimePrevious: null,
      numOfCasesCurrent: 30,
      potentialCases: 0,
      additionalCasePotential: 0,
      ORsPerWeekAvailable: 0.00,
      ORsPerWeekNeeded: 0.48,
      ORDifference: 0.48,
      numOfCasesWeekend: 1,
      ORsNeededPerWeekend: 0.01,
      percentWeekendWork: 0.0
    },
    'Thoracic Surgery': {
      primeTimeCurrent: 46.51,
      primeTimePrevious: 76.02,
      workDuringPrimeTimeCurrent: 15.8,
      workDuringPrimeTimePrevious: 19.7,
      numOfCasesCurrent: 23,
      potentialCases: 38,
      additionalCasePotential: 15,
      ORsPerWeekAvailable: 1.86,
      ORsPerWeekNeeded: 1.09,
      ORDifference: -0.77,
      numOfCasesWeekend: 0,
      ORsNeededPerWeekend: 0.00,
      percentWeekendWork: 0.0
    },
    'Colon and Rectal Surgery': {
      primeTimeCurrent: 218.20,
      primeTimePrevious: 128.39,
      workDuringPrimeTimeCurrent: 7.3,
      workDuringPrimeTimePrevious: 22.7,
      numOfCasesCurrent: 37,
      potentialCases: 19,
      additionalCasePotential: -18,
      ORsPerWeekAvailable: 0.51,
      ORsPerWeekNeeded: 1.27,
      ORDifference: 0.76,
      numOfCasesWeekend: 1,
      ORsNeededPerWeekend: 0.03,
      percentWeekendWork: 100.0
    },
    'Pulmonary Disease': {
      primeTimeCurrent: 68.80,
      primeTimePrevious: 29.74,
      workDuringPrimeTimeCurrent: 7.2,
      workDuringPrimeTimePrevious: 15.1,
      numOfCasesCurrent: 79,
      potentialCases: 101,
      additionalCasePotential: 22,
      ORsPerWeekAvailable: 1.69,
      ORsPerWeekNeeded: 1.49,
      ORDifference: -0.40,
      numOfCasesWeekend: 0,
      ORsNeededPerWeekend: 0.00,
      percentWeekendWork: 0.0
    },
    'Otolaryngology': {
      primeTimeCurrent: null,
      primeTimePrevious: null,
      workDuringPrimeTimeCurrent: null,
      workDuringPrimeTimePrevious: null,
      numOfCasesCurrent: 0,
      potentialCases: 0,
      additionalCasePotential: 0,
      ORsPerWeekAvailable: null,
      ORsPerWeekNeeded: 0.00,
      ORDifference: null,
      numOfCasesWeekend: 0,
      ORsNeededPerWeekend: 0.00,
      percentWeekendWork: null
    },
    'Oral Surgery': {
      primeTimeCurrent: 60.9,
      primeTimePrevious: 0.0,
      workDuringPrimeTimeCurrent: null,
      workDuringPrimeTimePrevious: null,
      numOfCasesCurrent: 2,
      potentialCases: 0,
      additionalCasePotential: 0,
      ORsPerWeekAvailable: 0.00,
      ORsPerWeekNeeded: 0.07,
      ORDifference: 0.07,
      numOfCasesWeekend: 0,
      ORsNeededPerWeekend: 0.00,
      percentWeekendWork: null
    },
    'Vascular Surgery': {
      primeTimeCurrent: null,
      primeTimePrevious: 0.0,
      workDuringPrimeTimeCurrent: null,
      workDuringPrimeTimePrevious: null,
      numOfCasesCurrent: 0,
      potentialCases: 0,
      additionalCasePotential: 0,
      ORsPerWeekAvailable: null,
      ORsPerWeekNeeded: 0.00,
      ORDifference: null,
      numOfCasesWeekend: 0,
      ORsNeededPerWeekend: 0.00,
      percentWeekendWork: null
    },
    'Orthopaedic Surgery': {
      primeTimeCurrent: 100.0,
      primeTimePrevious: null,
      workDuringPrimeTimeCurrent: null,
      workDuringPrimeTimePrevious: null,
      numOfCasesCurrent: 0,
      potentialCases: 0,
      additionalCasePotential: 0,
      ORsPerWeekAvailable: 0.00,
      ORsPerWeekNeeded: 0.00,
      ORDifference: null,
      numOfCasesWeekend: 0,
      ORsNeededPerWeekend: 0.00,
      percentWeekendWork: null
    },
    'Cardiology': {
      primeTimeCurrent: null,
      primeTimePrevious: null,
      workDuringPrimeTimeCurrent: null,
      workDuringPrimeTimePrevious: null,
      numOfCasesCurrent: 0,
      potentialCases: 0,
      additionalCasePotential: 0,
      ORsPerWeekAvailable: null,
      ORsPerWeekNeeded: 0.00,
      ORDifference: null,
      numOfCasesWeekend: 0,
      ORsNeededPerWeekend: 0.00,
      percentWeekendWork: null
    },
    'Plastic Surgery': {
      primeTimeCurrent: null,
      primeTimePrevious: null,
      workDuringPrimeTimeCurrent: null,
      workDuringPrimeTimePrevious: null,
      numOfCasesCurrent: 0,
      potentialCases: 0,
      additionalCasePotential: 0,
      ORsPerWeekAvailable: null,
      ORsPerWeekNeeded: 0.00,
      ORDifference: null,
      numOfCasesWeekend: 0,
      ORsNeededPerWeekend: 0.00,
      percentWeekendWork: null
    },
    'Trauma Surgery': {
      primeTimeCurrent: null,
      primeTimePrevious: null,
      workDuringPrimeTimeCurrent: null,
      workDuringPrimeTimePrevious: null,
      numOfCasesCurrent: 0,
      potentialCases: 0,
      additionalCasePotential: 0,
      ORsPerWeekAvailable: null,
      ORsPerWeekNeeded: 0.00,
      ORDifference: null,
      numOfCasesWeekend: 0,
      ORsNeededPerWeekend: 0.00,
      percentWeekendWork: null
    },
    'OPEN': {
      primeTimeCurrent: 0.00,
      primeTimePrevious: 0.00,
      workDuringPrimeTimeCurrent: null,
      workDuringPrimeTimePrevious: null,
      numOfCasesCurrent: 0,
      potentialCases: null,
      additionalCasePotential: null,
      ORsPerWeekAvailable: 5.28,
      ORsPerWeekNeeded: 0.00,
      ORDifference: -5.28,
      numOfCasesWeekend: 0,
      ORsNeededPerWeekend: 0.00,
      percentWeekendWork: null
    }
  },

  // Utilization range colors
  utilizationRanges: {
    low: { min: 0, max: 35, color: '#ff9999' },    // Light red
    medium: { min: 35, max: 65, color: '#ffcc99' }, // Light orange
    high: { min: 65, max: 100, color: '#99ff99' },  // Light green
    noData: { color: '#e6e6e6' }                   // Light gray
  }
};
