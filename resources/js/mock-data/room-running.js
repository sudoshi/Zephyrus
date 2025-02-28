// Mock data for room running analytics
export const mockRoomRunning = {
  // Site-specific data
  sites: {
    'MARH OR': {
      averageRoomsRunning: 12.4,
      totalRooms: 16,
      utilizationRate: 77.5,
      totalCases: 2345,
      averageCaseDuration: 142,
      roomsRunningByHour: {
        '7:00': 8,
        '8:00': 14,
        '9:00': 16,
        '10:00': 16,
        '11:00': 15,
        '12:00': 13,
        '13:00': 14,
        '14:00': 15,
        '15:00': 14,
        '16:00': 12,
        '17:00': 9,
        '18:00': 6,
        '19:00': 4,
        '20:00': 2
      }
    },
    'VORH JRI OR': {
      averageRoomsRunning: 7.8,
      totalRooms: 10,
      utilizationRate: 78.0,
      totalCases: 1876,
      averageCaseDuration: 156,
      roomsRunningByHour: {
        '7:00': 5,
        '8:00': 9,
        '9:00': 10,
        '10:00': 10,
        '11:00': 9,
        '12:00': 8,
        '13:00': 9,
        '14:00': 9,
        '15:00': 8,
        '16:00': 7,
        '17:00': 6,
        '18:00': 4,
        '19:00': 3,
        '20:00': 1
      }
    },
    'VORH Main OR': {
      averageRoomsRunning: 14.2,
      totalRooms: 18,
      utilizationRate: 78.9,
      totalCases: 2103,
      averageCaseDuration: 138,
      roomsRunningByHour: {
        '7:00': 10,
        '8:00': 16,
        '9:00': 18,
        '10:00': 18,
        '11:00': 17,
        '12:00': 15,
        '13:00': 16,
        '14:00': 17,
        '15:00': 16,
        '16:00': 14,
        '17:00': 11,
        '18:00': 8,
        '19:00': 5,
        '20:00': 3
      }
    }
  },
  
  // Service-specific data
  services: {
    'Orthopedics': {
      averageRoomsRunning: 5.2,
      utilizationRate: 80.0
    },
    'General Surgery': {
      averageRoomsRunning: 6.8,
      utilizationRate: 77.3
    },
    'Neurosurgery': {
      averageRoomsRunning: 3.5,
      utilizationRate: 83.3
    },
    'Cardiothoracic': {
      averageRoomsRunning: 2.8,
      utilizationRate: 87.5
    },
    'ENT': {
      averageRoomsRunning: 4.1,
      utilizationRate: 75.9
    },
    'Urology': {
      averageRoomsRunning: 3.2,
      utilizationRate: 76.2
    },
    'Vascular': {
      averageRoomsRunning: 2.4,
      utilizationRate: 80.0
    },
    'Plastics': {
      averageRoomsRunning: 1.8,
      utilizationRate: 72.0
    }
  },
  
  // Weekday data
  weekdays: {
    averageRoomsRunning: [
      { time: '7:00', value: 8 },
      { time: '8:00', value: 14 },
      { time: '9:00', value: 16 },
      { time: '10:00', value: 16 },
      { time: '11:00', value: 15 },
      { time: '12:00', value: 13 },
      { time: '13:00', value: 14 },
      { time: '14:00', value: 15 },
      { time: '15:00', value: 14 },
      { time: '16:00', value: 12 },
      { time: '17:00', value: 9 },
      { time: '18:00', value: 6 },
      { time: '19:00', value: 4 },
      { time: '20:00', value: 2 }
    ],
    Monday: [
      { time: '7:00', value: 7 },
      { time: '8:00', value: 13 },
      { time: '9:00', value: 15 },
      { time: '10:00', value: 15 },
      { time: '11:00', value: 14 },
      { time: '12:00', value: 12 },
      { time: '13:00', value: 13 },
      { time: '14:00', value: 14 },
      { time: '15:00', value: 13 },
      { time: '16:00', value: 11 },
      { time: '17:00', value: 8 },
      { time: '18:00', value: 5 },
      { time: '19:00', value: 3 },
      { time: '20:00', value: 1 }
    ],
    Tuesday: [
      { time: '7:00', value: 9 },
      { time: '8:00', value: 15 },
      { time: '9:00', value: 17 },
      { time: '10:00', value: 17 },
      { time: '11:00', value: 16 },
      { time: '12:00', value: 14 },
      { time: '13:00', value: 15 },
      { time: '14:00', value: 16 },
      { time: '15:00', value: 15 },
      { time: '16:00', value: 13 },
      { time: '17:00', value: 10 },
      { time: '18:00', value: 7 },
      { time: '19:00', value: 5 },
      { time: '20:00', value: 3 }
    ],
    Wednesday: [
      { time: '7:00', value: 8 },
      { time: '8:00', value: 14 },
      { time: '9:00', value: 16 },
      { time: '10:00', value: 16 },
      { time: '11:00', value: 15 },
      { time: '12:00', value: 13 },
      { time: '13:00', value: 14 },
      { time: '14:00', value: 15 },
      { time: '15:00', value: 14 },
      { time: '16:00', value: 12 },
      { time: '17:00', value: 9 },
      { time: '18:00', value: 6 },
      { time: '19:00', value: 4 },
      { time: '20:00', value: 2 }
    ],
    Thursday: [
      { time: '7:00', value: 8 },
      { time: '8:00', value: 14 },
      { time: '9:00', value: 16 },
      { time: '10:00', value: 16 },
      { time: '11:00', value: 15 },
      { time: '12:00', value: 13 },
      { time: '13:00', value: 14 },
      { time: '14:00', value: 15 },
      { time: '15:00', value: 14 },
      { time: '16:00', value: 12 },
      { time: '17:00', value: 9 },
      { time: '18:00', value: 6 },
      { time: '19:00', value: 4 },
      { time: '20:00', value: 2 }
    ],
    Friday: [
      { time: '7:00', value: 7 },
      { time: '8:00', value: 13 },
      { time: '9:00', value: 15 },
      { time: '10:00', value: 15 },
      { time: '11:00', value: 14 },
      { time: '12:00', value: 12 },
      { time: '13:00', value: 13 },
      { time: '14:00', value: 14 },
      { time: '15:00', value: 13 },
      { time: '16:00', value: 11 },
      { time: '17:00', value: 8 },
      { time: '18:00', value: 5 },
      { time: '19:00', value: 3 },
      { time: '20:00', value: 1 }
    ]
  },
  
  // Weekend data
  weekend: [
    { time: '7:00', value: 3 },
    { time: '8:00', value: 5 },
    { time: '9:00', value: 6 },
    { time: '10:00', value: 6 },
    { time: '11:00', value: 5 },
    { time: '12:00', value: 4 },
    { time: '13:00', value: 5 },
    { time: '14:00', value: 5 },
    { time: '15:00', value: 4 },
    { time: '16:00', value: 3 },
    { time: '17:00', value: 2 },
    { time: '18:00', value: 1 },
    { time: '19:00', value: 0 },
    { time: '20:00', value: 0 }
  ],
  
  // Monthly trends
  monthlyTrends: {
    'MARH OR': [
      { month: 'Jan', value: 11.8 },
      { month: 'Feb', value: 12.1 },
      { month: 'Mar', value: 12.4 },
      { month: 'Apr', value: 12.6 },
      { month: 'May', value: 12.9 },
      { month: 'Jun', value: 13.2 },
      { month: 'Jul', value: 12.7 },
      { month: 'Aug', value: 12.2 },
      { month: 'Sep', value: 12.5 },
      { month: 'Oct', value: 12.8 },
      { month: 'Nov', value: 13.1 },
      { month: 'Dec', value: 12.6 }
    ],
    'VORH JRI OR': [
      { month: 'Jan', value: 7.2 },
      { month: 'Feb', value: 7.5 },
      { month: 'Mar', value: 7.8 },
      { month: 'Apr', value: 8.0 },
      { month: 'May', value: 8.3 },
      { month: 'Jun', value: 8.6 },
      { month: 'Jul', value: 8.1 },
      { month: 'Aug', value: 7.6 },
      { month: 'Sep', value: 7.9 },
      { month: 'Oct', value: 8.2 },
      { month: 'Nov', value: 8.5 },
      { month: 'Dec', value: 8.0 }
    ],
    'VORH Main OR': [
      { month: 'Jan', value: 13.6 },
      { month: 'Feb', value: 13.9 },
      { month: 'Mar', value: 14.2 },
      { month: 'Apr', value: 14.4 },
      { month: 'May', value: 14.7 },
      { month: 'Jun', value: 15.0 },
      { month: 'Jul', value: 14.5 },
      { month: 'Aug', value: 14.0 },
      { month: 'Sep', value: 14.3 },
      { month: 'Oct', value: 14.6 },
      { month: 'Nov', value: 14.9 },
      { month: 'Dec', value: 14.4 }
    ]
  },
  
  // MEMH OR data for rooms running chart
  memhOR: {
    filters: {
      orGroup: "MEMH OR",
      weekday: ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"],
      metricToShow: "Max Staffing"
    },
    // Data series for different metrics
    dataSeries: {
      maxStaffing: {
        id: "Max Staffing",
        color: "#4169E1", // Royal blue
        data: [
          { time: '0', value: 0 },
          { time: '1', value: 0 },
          { time: '2', value: 1.5 },
          { time: '3', value: 1.8 },
          { time: '4', value: 1.2 },
          { time: '5', value: 1 },
          { time: '6', value: 0.8 },
          { time: '7', value: 0.5 },
          { time: '7:30', value: 9.2 },
          { time: '8', value: 11 },
          { time: '9', value: 9.8 },
          { time: '10', value: 9.2 },
          { time: '11', value: 8.8 },
          { time: '12', value: 8.6 },
          { time: '13', value: 8.5 },
          { time: '14', value: 8.6 },
          { time: '15', value: 8.4 },
          { time: '15:30', value: 6.8 },
          { time: '16', value: 5.5 },
          { time: '17', value: 4.2 },
          { time: '17:30', value: 3.0 },
          { time: '18', value: 2.5 },
          { time: '19', value: 2.0 },
          { time: '20', value: 1.9 },
          { time: '21', value: 2.0 },
          { time: '22', value: 2.0 },
          { time: '23', value: 1.8 },
          { time: '24', value: 0 }
        ]
      },
      idealStaffing: {
        id: "Ideal Staffing",
        color: "#FFA500", // Orange
        data: [
          { time: '0', value: 0 },
          { time: '1', value: 0 },
          { time: '2', value: 1 },
          { time: '3', value: 1.2 },
          { time: '4', value: 1 },
          { time: '5', value: 0.8 },
          { time: '6', value: 0.6 },
          { time: '7', value: 0.3 },
          { time: '7:30', value: 8.5 },
          { time: '8', value: 8.8 },
          { time: '9', value: 8.2 },
          { time: '10', value: 7.5 },
          { time: '11', value: 7.2 },
          { time: '12', value: 7.0 },
          { time: '13', value: 6.8 },
          { time: '14', value: 6.9 },
          { time: '15', value: 6.7 },
          { time: '15:30', value: 5.5 },
          { time: '16', value: 4.2 },
          { time: '17', value: 3.2 },
          { time: '17:30', value: 2.5 },
          { time: '18', value: 2.0 },
          { time: '19', value: 1.8 },
          { time: '20', value: 1.6 },
          { time: '21', value: 1.7 },
          { time: '22', value: 1.8 },
          { time: '23', value: 1.6 },
          { time: '24', value: 0 }
        ]
      },
      avgTotalOccupied: {
        id: "Avg_TotalOccupied",
        color: "#FF4500", // OrangeRed
        data: [
          { time: '0', value: 0 },
          { time: '1', value: 0 },
          { time: '2', value: 0.8 },
          { time: '3', value: 1 },
          { time: '4', value: 0.8 },
          { time: '5', value: 0.6 },
          { time: '6', value: 0.4 },
          { time: '7', value: 0.2 },
          { time: '7:30', value: 6.8 },
          { time: '8', value: 6.5 },
          { time: '9', value: 6.2 },
          { time: '10', value: 5.8 },
          { time: '11', value: 5.6 },
          { time: '12', value: 5.5 },
          { time: '13', value: 5.3 },
          { time: '14', value: 5.4 },
          { time: '15', value: 5.2 },
          { time: '15:30', value: 4.0 },
          { time: '16', value: 3.0 },
          { time: '17', value: 2.2 },
          { time: '17:30', value: 1.8 },
          { time: '18', value: 1.5 },
          { time: '19', value: 1.3 },
          { time: '20', value: 1.2 },
          { time: '21', value: 1.3 },
          { time: '22', value: 1.4 },
          { time: '23', value: 1.2 },
          { time: '24', value: 0 }
        ]
      },
      maxOccupied: {
        id: "Max Occupied",
        color: "#20B2AA", // LightSeaGreen
        data: [
          { time: '0', value: 0 },
          { time: '1', value: 0 },
          { time: '2', value: 1 },
          { time: '3', value: 1.2 },
          { time: '4', value: 1 },
          { time: '5', value: 0.8 },
          { time: '6', value: 0.6 },
          { time: '7', value: 0.3 },
          { time: '7:30', value: 7.0 },
          { time: '8', value: 7.2 },
          { time: '9', value: 7.0 },
          { time: '10', value: 6.5 },
          { time: '11', value: 6.2 },
          { time: '12', value: 6.0 },
          { time: '13', value: 5.8 },
          { time: '14', value: 5.9 },
          { time: '15', value: 5.7 },
          { time: '15:30', value: 4.5 },
          { time: '16', value: 3.5 },
          { time: '17', value: 2.8 },
          { time: '17:30', value: 2.0 },
          { time: '18', value: 1.7 },
          { time: '19', value: 1.5 },
          { time: '20', value: 1.4 },
          { time: '21', value: 1.5 },
          { time: '22', value: 1.6 },
          { time: '23', value: 1.4 },
          { time: '24', value: 0 }
        ]
      }
    },
    // Statistics for the chart
    statistics: {
      timeOfDay: '18:30',
      average: 1.382,
      averagePlus1StdDev: 2.295,
      averagePlus2StdDev: 3.21,
      max: 3,
      sum: 55
    },
    // Vertical markers for key times
    verticalMarkers: [
      { time: '7:30', label: '07:30' },
      { time: '15:30', label: '15:30' },
      { time: '17:30', label: '17:30' }
    ]
  }
};
