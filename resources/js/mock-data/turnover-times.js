// Mock data for turnover times analytics
export const mockTurnoverTimes = {
  // Site-specific data
  sites: {
    'MARH OR': {
      medianTurnoverTime: 32,
      averageTurnoverTime: 36.5,
      totalCases: 2345,
      totalTurnovers: 2129,
      turnoverDistribution: {
        '0-15 min': 124,
        '15-30 min': 743,
        '30-45 min': 865,
        '45-60 min': 312,
        '60-90 min': 76,
        '90+ min': 9
      },
      rooms: [
        { room: 'OR 1', medianTurnoverTime: 30, averageTurnoverTime: 34.2 },
        { room: 'OR 2', medianTurnoverTime: 31, averageTurnoverTime: 35.8 },
        { room: 'OR 3', medianTurnoverTime: 33, averageTurnoverTime: 37.5 },
        { room: 'OR 4', medianTurnoverTime: 32, averageTurnoverTime: 36.3 },
        { room: 'OR 5', medianTurnoverTime: 34, averageTurnoverTime: 38.1 },
        { room: 'OR 6', medianTurnoverTime: 31, averageTurnoverTime: 35.4 },
        { room: 'OR 7', medianTurnoverTime: 33, averageTurnoverTime: 37.2 },
        { room: 'OR 8', medianTurnoverTime: 32, averageTurnoverTime: 36.7 }
      ],
      trends: {
        medianTurnoverTime: [
          { month: 'Jan', value: 34 },
          { month: 'Feb', value: 33 },
          { month: 'Mar', value: 33 },
          { month: 'Apr', value: 32 },
          { month: 'May', value: 32 },
          { month: 'Jun', value: 31 },
          { month: 'Jul', value: 31 },
          { month: 'Aug', value: 32 },
          { month: 'Sep', value: 32 },
          { month: 'Oct', value: 31 },
          { month: 'Nov', value: 31 },
          { month: 'Dec', value: 32 }
        ],
        averageTurnoverTime: [
          { month: 'Jan', value: 38.5 },
          { month: 'Feb', value: 37.8 },
          { month: 'Mar', value: 37.5 },
          { month: 'Apr', value: 36.8 },
          { month: 'May', value: 36.5 },
          { month: 'Jun', value: 35.8 },
          { month: 'Jul', value: 35.5 },
          { month: 'Aug', value: 36.2 },
          { month: 'Sep', value: 36.5 },
          { month: 'Oct', value: 35.8 },
          { month: 'Nov', value: 35.5 },
          { month: 'Dec', value: 36.2 }
        ]
      }
    },
    'VORH JRI OR': {
      medianTurnoverTime: 30,
      averageTurnoverTime: 34.2,
      totalCases: 1876,
      totalTurnovers: 1704,
      turnoverDistribution: {
        '0-15 min': 136,
        '15-30 min': 682,
        '30-45 min': 648,
        '45-60 min': 187,
        '60-90 min': 46,
        '90+ min': 5
      },
      rooms: [
        { room: 'OR 1', medianTurnoverTime: 28, averageTurnoverTime: 32.5 },
        { room: 'OR 2', medianTurnoverTime: 29, averageTurnoverTime: 33.7 },
        { room: 'OR 3', medianTurnoverTime: 31, averageTurnoverTime: 35.4 },
        { room: 'OR 4', medianTurnoverTime: 30, averageTurnoverTime: 34.2 },
        { room: 'OR 5', medianTurnoverTime: 32, averageTurnoverTime: 36.0 },
        { room: 'OR 6', medianTurnoverTime: 29, averageTurnoverTime: 33.3 }
      ],
      trends: {
        medianTurnoverTime: [
          { month: 'Jan', value: 32 },
          { month: 'Feb', value: 31 },
          { month: 'Mar', value: 31 },
          { month: 'Apr', value: 30 },
          { month: 'May', value: 30 },
          { month: 'Jun', value: 29 },
          { month: 'Jul', value: 29 },
          { month: 'Aug', value: 30 },
          { month: 'Sep', value: 30 },
          { month: 'Oct', value: 29 },
          { month: 'Nov', value: 29 },
          { month: 'Dec', value: 30 }
        ],
        averageTurnoverTime: [
          { month: 'Jan', value: 36.2 },
          { month: 'Feb', value: 35.5 },
          { month: 'Mar', value: 35.2 },
          { month: 'Apr', value: 34.5 },
          { month: 'May', value: 34.2 },
          { month: 'Jun', value: 33.5 },
          { month: 'Jul', value: 33.2 },
          { month: 'Aug', value: 33.9 },
          { month: 'Sep', value: 34.2 },
          { month: 'Oct', value: 33.5 },
          { month: 'Nov', value: 33.2 },
          { month: 'Dec', value: 33.9 }
        ]
      }
    },
    'VORH Main OR': {
      medianTurnoverTime: 33,
      averageTurnoverTime: 37.8,
      totalCases: 2103,
      totalTurnovers: 1912,
      turnoverDistribution: {
        '0-15 min': 115,
        '15-30 min': 612,
        '30-45 min': 803,
        '45-60 min': 294,
        '60-90 min': 82,
        '90+ min': 6
      },
      rooms: [
        { room: 'OR 1', medianTurnoverTime: 31, averageTurnoverTime: 35.6 },
        { room: 'OR 2', medianTurnoverTime: 32, averageTurnoverTime: 36.9 },
        { room: 'OR 3', medianTurnoverTime: 34, averageTurnoverTime: 38.7 },
        { room: 'OR 4', medianTurnoverTime: 33, averageTurnoverTime: 37.5 },
        { room: 'OR 5', medianTurnoverTime: 35, averageTurnoverTime: 39.3 },
        { room: 'OR 6', medianTurnoverTime: 32, averageTurnoverTime: 36.6 },
        { room: 'OR 7', medianTurnoverTime: 34, averageTurnoverTime: 38.4 },
        { room: 'OR 8', medianTurnoverTime: 33, averageTurnoverTime: 37.9 },
        { room: 'OR 9', medianTurnoverTime: 35, averageTurnoverTime: 39.1 },
        { room: 'OR 10', medianTurnoverTime: 32, averageTurnoverTime: 36.8 }
      ],
      trends: {
        medianTurnoverTime: [
          { month: 'Jan', value: 35 },
          { month: 'Feb', value: 34 },
          { month: 'Mar', value: 34 },
          { month: 'Apr', value: 33 },
          { month: 'May', value: 33 },
          { month: 'Jun', value: 32 },
          { month: 'Jul', value: 32 },
          { month: 'Aug', value: 33 },
          { month: 'Sep', value: 33 },
          { month: 'Oct', value: 32 },
          { month: 'Nov', value: 32 },
          { month: 'Dec', value: 33 }
        ],
        averageTurnoverTime: [
          { month: 'Jan', value: 39.8 },
          { month: 'Feb', value: 39.1 },
          { month: 'Mar', value: 38.8 },
          { month: 'Apr', value: 38.1 },
          { month: 'May', value: 37.8 },
          { month: 'Jun', value: 37.1 },
          { month: 'Jul', value: 36.8 },
          { month: 'Aug', value: 37.5 },
          { month: 'Sep', value: 37.8 },
          { month: 'Oct', value: 37.1 },
          { month: 'Nov', value: 36.8 },
          { month: 'Dec', value: 37.5 }
        ]
      }
    }
  },
  
  // Service-specific data
  services: {
    'Orthopedics': {
      medianTurnoverTime: 34,
      averageTurnoverTime: 38.5,
      totalCases: 1106,
      totalTurnovers: 1003
    },
    'General Surgery': {
      medianTurnoverTime: 31,
      averageTurnoverTime: 35.2,
      totalCases: 982,
      totalTurnovers: 891
    },
    'Neurosurgery': {
      medianTurnoverTime: 36,
      averageTurnoverTime: 40.8,
      totalCases: 543,
      totalTurnovers: 492
    },
    'Cardiothoracic': {
      medianTurnoverTime: 38,
      averageTurnoverTime: 42.5,
      totalCases: 421,
      totalTurnovers: 382
    },
    'ENT': {
      medianTurnoverTime: 29,
      averageTurnoverTime: 33.7,
      totalCases: 678,
      totalTurnovers: 615
    },
    'Urology': {
      medianTurnoverTime: 30,
      averageTurnoverTime: 34.3,
      totalCases: 524,
      totalTurnovers: 475
    },
    'Vascular': {
      medianTurnoverTime: 35,
      averageTurnoverTime: 39.2,
      totalCases: 387,
      totalTurnovers: 351
    },
    'Plastics': {
      medianTurnoverTime: 32,
      averageTurnoverTime: 36.4,
      totalCases: 312,
      totalTurnovers: 283
    }
  },
  
  // Day of week analysis
  dayOfWeek: {
    'MARH OR': {
      'Monday': { median: 33, average: 37.5 },
      'Tuesday': { median: 31, average: 35.8 },
      'Wednesday': { median: 32, average: 36.5 },
      'Thursday': { median: 32, average: 36.3 },
      'Friday': { median: 34, average: 38.2 }
    },
    'VORH JRI OR': {
      'Monday': { median: 31, average: 35.2 },
      'Tuesday': { median: 29, average: 33.5 },
      'Wednesday': { median: 30, average: 34.2 },
      'Thursday': { median: 30, average: 34.0 },
      'Friday': { median: 32, average: 35.9 }
    },
    'VORH Main OR': {
      'Monday': { median: 34, average: 38.8 },
      'Tuesday': { median: 32, average: 36.1 },
      'Wednesday': { median: 33, average: 37.8 },
      'Thursday': { median: 33, average: 37.6 },
      'Friday': { median: 35, average: 39.5 }
    }
  },
  
  // Time of day analysis
  timeOfDay: {
    'MARH OR': {
      '7:00-9:00': { median: 30, average: 34.2 },
      '9:00-11:00': { median: 31, average: 35.3 },
      '11:00-13:00': { median: 34, average: 38.6 },
      '13:00-15:00': { median: 32, average: 36.5 },
      '15:00-17:00': { median: 33, average: 37.4 },
      '17:00-19:00': { median: 35, average: 39.8 }
    },
    'VORH JRI OR': {
      '7:00-9:00': { median: 28, average: 31.9 },
      '9:00-11:00': { median: 29, average: 33.0 },
      '11:00-13:00': { median: 32, average: 36.3 },
      '13:00-15:00': { median: 30, average: 34.2 },
      '15:00-17:00': { median: 31, average: 35.1 },
      '17:00-19:00': { median: 33, average: 37.5 }
    },
    'VORH Main OR': {
      '7:00-9:00': { median: 31, average: 35.5 },
      '9:00-11:00': { median: 32, average: 36.6 },
      '11:00-13:00': { median: 35, average: 39.9 },
      '13:00-15:00': { median: 33, average: 37.8 },
      '15:00-17:00': { median: 34, average: 38.7 },
      '17:00-19:00': { median: 36, average: 41.1 }
    }
  }
};
