/**
 * Mock data for OR Utilization dashboard
 */

// Utilization trends over time
export const mockTrendsData = [
  { date: '2024-01', utilization: 0.72 },
  { date: '2024-02', utilization: 0.75 },
  { date: '2024-03', utilization: 0.73 },
  { date: '2024-04', utilization: 0.78 },
  { date: '2024-05', utilization: 0.80 },
  { date: '2024-06', utilization: 0.82 },
  { date: '2024-07', utilization: 0.79 },
  { date: '2024-08', utilization: 0.77 },
  { date: '2024-09', utilization: 0.81 },
  { date: '2024-10', utilization: 0.83 },
  { date: '2024-11', utilization: 0.84 },
  { date: '2024-12', utilization: 0.82 }
];

// Comparison data for previous year
export const mockComparisonTrendsData = [
  { date: '2023-01', utilization: 0.68 },
  { date: '2023-02', utilization: 0.70 },
  { date: '2023-03', utilization: 0.69 },
  { date: '2023-04', utilization: 0.72 },
  { date: '2023-05', utilization: 0.74 },
  { date: '2023-06', utilization: 0.76 },
  { date: '2023-07', utilization: 0.73 },
  { date: '2023-08', utilization: 0.71 },
  { date: '2023-09', utilization: 0.75 },
  { date: '2023-10', utilization: 0.77 },
  { date: '2023-11', utilization: 0.78 },
  { date: '2023-12', utilization: 0.76 }
];

// Day of week utilization
export const mockDayOfWeekData = [
  { day: 'Monday', utilization: 0.82, cases: 24 },
  { day: 'Tuesday', utilization: 0.87, cases: 26 },
  { day: 'Wednesday', utilization: 0.85, cases: 25 },
  { day: 'Thursday', utilization: 0.82, cases: 23 },
  { day: 'Friday', utilization: 0.78, cases: 21 },
  { day: 'Saturday', utilization: 0.65, cases: 12 },
  { day: 'Sunday', utilization: 0.45, cases: 8 }
];

// Time of day utilization
export const mockTimeOfDayData = [
  { hour: '07:00', utilization: 0.65 },
  { hour: '08:00', utilization: 0.85 },
  { hour: '09:00', utilization: 0.92 },
  { hour: '10:00', utilization: 0.95 },
  { hour: '11:00', utilization: 0.93 },
  { hour: '12:00', utilization: 0.75 },
  { hour: '13:00', utilization: 0.88 },
  { hour: '14:00', utilization: 0.90 },
  { hour: '15:00', utilization: 0.87 },
  { hour: '16:00', utilization: 0.82 },
  { hour: '17:00', utilization: 0.75 },
  { hour: '18:00', utilization: 0.60 },
  { hour: '19:00', utilization: 0.45 }
];

// Mock data for room utilization
export const mockRoomData = [
  { id: 'OR-01', name: 'OR 1', utilization: 0.88, cases: 120, turnoverTime: 32 },
  { id: 'OR-02', name: 'OR 2', utilization: 0.82, cases: 115, turnoverTime: 28 },
  { id: 'OR-03', name: 'OR 3', utilization: 0.91, cases: 125, turnoverTime: 25 },
  { id: 'OR-04', name: 'OR 4', utilization: 0.75, cases: 95, turnoverTime: 35 },
  { id: 'OR-05', name: 'OR 5', utilization: 0.79, cases: 105, turnoverTime: 30 },
  { id: 'OR-06', name: 'OR 6', utilization: 0.86, cases: 118, turnoverTime: 27 },
  { id: 'OR-07', name: 'OR 7', utilization: 0.72, cases: 90, turnoverTime: 38 },
  { id: 'OR-08', name: 'OR 8', utilization: 0.84, cases: 110, turnoverTime: 29 }
];

// Mock data for room turnover analysis
export const mockRoomTurnoverData = [
  { room: 'OR 1', actual: 32, benchmark: 25 },
  { room: 'OR 2', actual: 28, benchmark: 25 },
  { room: 'OR 3', actual: 25, benchmark: 25 },
  { room: 'OR 4', actual: 35, benchmark: 25 },
  { room: 'OR 5', actual: 30, benchmark: 25 },
  { room: 'OR 6', actual: 27, benchmark: 25 },
  { room: 'OR 7', actual: 38, benchmark: 25 },
  { room: 'OR 8', actual: 29, benchmark: 25 }
];

// Mock data for specialty distribution
export const mockSpecialtyData = {
  'Orthopedics': { cases: 245, utilization: 0.87, turnoverTime: 29, caseDuration: 118 },
  'General Surgery': { cases: 210, utilization: 0.82, turnoverTime: 32, caseDuration: 95 },
  'Cardiology': { cases: 180, utilization: 0.89, turnoverTime: 27, caseDuration: 142 },
  'Neurosurgery': { cases: 120, utilization: 0.85, turnoverTime: 34, caseDuration: 187 },
  'ENT': { cases: 150, utilization: 0.78, turnoverTime: 28, caseDuration: 68 },
  'Urology': { cases: 135, utilization: 0.76, turnoverTime: 31, caseDuration: 76 },
  'Plastic Surgery': { cases: 95, utilization: 0.72, turnoverTime: 33, caseDuration: 103 },
  'OB/GYN': { cases: 165, utilization: 0.81, turnoverTime: 31, caseDuration: 82 }
};

// Mock data for specialty case duration accuracy
export const mockSpecialtyCaseDurationData = [
  { specialty: 'Orthopedics', scheduled: 120, actual: 132, variance: 10 },
  { specialty: 'General Surgery', scheduled: 95, actual: 102, variance: 7.4 },
  { specialty: 'Cardiology', scheduled: 150, actual: 158, variance: 5.3 },
  { specialty: 'Neurosurgery', scheduled: 180, actual: 205, variance: 13.9 },
  { specialty: 'ENT', scheduled: 75, actual: 72, variance: -4 },
  { specialty: 'Urology', scheduled: 90, actual: 98, variance: 8.9 },
  { specialty: 'Plastic Surgery', scheduled: 110, actual: 118, variance: 7.3 },
  { specialty: 'OB/GYN', scheduled: 85, actual: 92, variance: 8.2 }
];

// Mock data for block time optimization
export const mockBlockOptimizationData = [
  { specialty: 'Orthopedics', allocated: 48, utilized: 41.8, opportunity: 6.2 },
  { specialty: 'General Surgery', allocated: 32, utilized: 26.2, opportunity: 5.8 },
  { specialty: 'Cardiology', allocated: 32, utilized: 28.5, opportunity: 3.5 },
  { specialty: 'Neurosurgery', allocated: 16, utilized: 13.6, opportunity: 2.4 },
  { specialty: 'ENT', allocated: 16, utilized: 12.5, opportunity: 3.5 },
  { specialty: 'Urology', allocated: 16, utilized: 12.2, opportunity: 3.8 },
  { specialty: 'Plastic Surgery', allocated: 16, utilized: 11.5, opportunity: 4.5 },
  { specialty: 'OB/GYN', allocated: 16, utilized: 13.0, opportunity: 3.0 }
];

// Block optimization data for OpportunityAnalysisView
export const mockBlockOptimizationDataOpportunityAnalysis = [
  { specialty: 'Orthopedics', allocated: 120, utilized: 90, opportunity: 30 },
  { specialty: 'Cardiology', allocated: 100, utilized: 85, opportunity: 15 },
  { specialty: 'Neurology', allocated: 80, utilized: 60, opportunity: 20 },
  { specialty: 'General Surgery', allocated: 150, utilized: 120, opportunity: 30 },
  { specialty: 'ENT', allocated: 70, utilized: 45, opportunity: 25 },
  { specialty: 'Urology', allocated: 60, utilized: 50, opportunity: 10 }
];

// Efficiency improvement data for OpportunityAnalysisView
export const mockEfficiencyImprovementDataOpportunityAnalysis = [
  { category: 'First Case Delay', current: 18, benchmark: 10, opportunity: 8 },
  { category: 'Turnover Time', current: 35, benchmark: 25, opportunity: 10 },
  { category: 'Scheduling Accuracy', current: 75, benchmark: 90, opportunity: 15 },
  { category: 'Cancellation Rate', current: 12, benchmark: 5, opportunity: 7 },
  { category: 'Late Starts', current: 22, benchmark: 12, opportunity: 10 }
];

// Financial impact data for OpportunityAnalysisView
export const mockFinancialImpactDataOpportunityAnalysis = [
  { category: 'Current Revenue', value: 25000000 },
  { category: 'Current Costs', value: 18000000 },
  { category: 'Current Margin', value: 7000000 },
  { category: 'Potential Additional Revenue', value: 4500000 },
  { category: 'Potential Cost Savings', value: 1200000 },
  { category: 'Potential Margin Improvement', value: 5700000 }
];

// Mock data for efficiency improvement opportunities
export const mockEfficiencyImprovementData = [
  { category: 'Turnover Time', current: 32, benchmark: 25, opportunity: 7 },
  { category: 'First Case Delays', current: 18, benchmark: 10, opportunity: 8 },
  { category: 'Case Duration Accuracy', current: 85, benchmark: 95, opportunity: 10 },
  { category: 'Scheduling Accuracy', current: 92, benchmark: 98, opportunity: 6 },
  { category: 'Non-operative Time', current: 22, benchmark: 15, opportunity: 7 }
];

// Mock data for financial impact analysis
export const mockFinancialImpactData = [
  { category: 'Current Revenue', value: 24500000 },
  { category: 'Potential Additional Revenue', value: 3850000 },
  { category: 'Current Costs', value: 18200000 },
  { category: 'Potential Cost Savings', value: 1250000 },
  { category: 'Current Margin', value: 6300000 },
  { category: 'Potential Margin', value: 11400000 }
];

// Mock data for room utilization heatmap
export const mockRoomHeatmapData = [
  { room: 'OR 1', '07:00': 0.5, '08:00': 0.9, '09:00': 0.95, '10:00': 0.95, '11:00': 0.9, '12:00': 0.7, '13:00': 0.85, '14:00': 0.9, '15:00': 0.85, '16:00': 0.8, '17:00': 0.7, '18:00': 0.5 },
  { room: 'OR 2', '07:00': 0.6, '08:00': 0.85, '09:00': 0.9, '10:00': 0.9, '11:00': 0.85, '12:00': 0.75, '13:00': 0.8, '14:00': 0.85, '15:00': 0.8, '16:00': 0.75, '17:00': 0.65, '18:00': 0.45 },
  { room: 'OR 3', '07:00': 0.7, '08:00': 0.95, '09:00': 0.98, '10:00': 0.98, '11:00': 0.95, '12:00': 0.8, '13:00': 0.9, '14:00': 0.95, '15:00': 0.9, '16:00': 0.85, '17:00': 0.75, '18:00': 0.55 },
  { room: 'OR 4', '07:00': 0.45, '08:00': 0.8, '09:00': 0.85, '10:00': 0.85, '11:00': 0.8, '12:00': 0.65, '13:00': 0.75, '14:00': 0.8, '15:00': 0.75, '16:00': 0.7, '17:00': 0.6, '18:00': 0.4 },
  { room: 'OR 5', '07:00': 0.5, '08:00': 0.85, '09:00': 0.9, '10:00': 0.9, '11:00': 0.85, '12:00': 0.7, '13:00': 0.8, '14:00': 0.85, '15:00': 0.8, '16:00': 0.75, '17:00': 0.65, '18:00': 0.45 },
  { room: 'OR 6', '07:00': 0.65, '08:00': 0.9, '09:00': 0.95, '10:00': 0.95, '11:00': 0.9, '12:00': 0.75, '13:00': 0.85, '14:00': 0.9, '15:00': 0.85, '16:00': 0.8, '17:00': 0.7, '18:00': 0.5 },
  { room: 'OR 7', '07:00': 0.4, '08:00': 0.75, '09:00': 0.8, '10:00': 0.8, '11:00': 0.75, '12:00': 0.6, '13:00': 0.7, '14:00': 0.75, '15:00': 0.7, '16:00': 0.65, '17:00': 0.55, '18:00': 0.35 },
  { room: 'OR 8', '07:00': 0.55, '08:00': 0.85, '09:00': 0.9, '10:00': 0.9, '11:00': 0.85, '12:00': 0.7, '13:00': 0.8, '14:00': 0.85, '15:00': 0.8, '16:00': 0.75, '17:00': 0.65, '18:00': 0.45 }
];

// Mock data for room scheduling
export const mockRoomSchedulingData = [
  { 
    room: 'OR 1', 
    schedule: [
      { start: '07:30', end: '09:30', specialty: 'Orthopedics', surgeon: 'Dr. Smith', procedure: 'Total Knee Replacement' },
      { start: '10:00', end: '12:00', specialty: 'Orthopedics', surgeon: 'Dr. Johnson', procedure: 'ACL Reconstruction' },
      { start: '13:00', end: '15:30', specialty: 'Orthopedics', surgeon: 'Dr. Smith', procedure: 'Total Hip Replacement' },
      { start: '16:00', end: '17:30', specialty: 'Orthopedics', surgeon: 'Dr. Williams', procedure: 'Shoulder Arthroscopy' }
    ]
  },
  { 
    room: 'OR 2', 
    schedule: [
      { start: '07:30', end: '09:00', specialty: 'General Surgery', surgeon: 'Dr. Brown', procedure: 'Laparoscopic Cholecystectomy' },
      { start: '09:30', end: '11:30', specialty: 'General Surgery', surgeon: 'Dr. Davis', procedure: 'Hernia Repair' },
      { start: '12:30', end: '14:30', specialty: 'General Surgery', surgeon: 'Dr. Brown', procedure: 'Appendectomy' },
      { start: '15:00', end: '17:00', specialty: 'General Surgery', surgeon: 'Dr. Miller', procedure: 'Colon Resection' }
    ]
  },
  { 
    room: 'OR 3', 
    schedule: [
      { start: '07:30', end: '10:30', specialty: 'Cardiology', surgeon: 'Dr. Wilson', procedure: 'CABG' },
      { start: '11:00', end: '14:00', specialty: 'Cardiology', surgeon: 'Dr. Moore', procedure: 'Valve Replacement' },
      { start: '14:30', end: '17:30', specialty: 'Cardiology', surgeon: 'Dr. Wilson', procedure: 'Pacemaker Insertion' }
    ]
  },
  { 
    room: 'OR 4', 
    schedule: [
      { start: '07:30', end: '10:00', specialty: 'Neurosurgery', surgeon: 'Dr. Taylor', procedure: 'Craniotomy' },
      { start: '10:30', end: '13:30', specialty: 'Neurosurgery', surgeon: 'Dr. Anderson', procedure: 'Spinal Fusion' },
      { start: '14:00', end: '16:30', specialty: 'Neurosurgery', surgeon: 'Dr. Taylor', procedure: 'Laminectomy' }
    ]
  },
  { 
    room: 'OR 5', 
    schedule: [
      { start: '07:30', end: '08:30', specialty: 'ENT', surgeon: 'Dr. Thomas', procedure: 'Tonsillectomy' },
      { start: '09:00', end: '10:00', specialty: 'ENT', surgeon: 'Dr. Jackson', procedure: 'Septoplasty' },
      { start: '10:30', end: '12:00', specialty: 'ENT', surgeon: 'Dr. Thomas', procedure: 'Sinus Surgery' },
      { start: '13:00', end: '14:00', specialty: 'ENT', surgeon: 'Dr. Jackson', procedure: 'Tympanoplasty' },
      { start: '14:30', end: '16:00', specialty: 'ENT', surgeon: 'Dr. Thomas', procedure: 'Thyroidectomy' }
    ]
  },
  { 
    room: 'OR 6', 
    schedule: [
      { start: '07:30', end: '09:00', specialty: 'Urology', surgeon: 'Dr. White', procedure: 'Cystoscopy' },
      { start: '09:30', end: '11:30', specialty: 'Urology', surgeon: 'Dr. Harris', procedure: 'Prostatectomy' },
      { start: '12:30', end: '14:00', specialty: 'Urology', surgeon: 'Dr. White', procedure: 'Nephrectomy' },
      { start: '14:30', end: '16:30', specialty: 'Urology', surgeon: 'Dr. Harris', procedure: 'TURP' }
    ]
  },
  { 
    room: 'OR 7', 
    schedule: [
      { start: '07:30', end: '09:30', specialty: 'Plastic Surgery', surgeon: 'Dr. Martin', procedure: 'Breast Reconstruction' },
      { start: '10:00', end: '12:00', specialty: 'Plastic Surgery', surgeon: 'Dr. Thompson', procedure: 'Rhinoplasty' },
      { start: '13:00', end: '15:00', specialty: 'Plastic Surgery', surgeon: 'Dr. Martin', procedure: 'Facelift' }
    ]
  },
  { 
    room: 'OR 8', 
    schedule: [
      { start: '07:30', end: '09:00', specialty: 'OB/GYN', surgeon: 'Dr. Garcia', procedure: 'Hysterectomy' },
      { start: '09:30', end: '11:00', specialty: 'OB/GYN', surgeon: 'Dr. Martinez', procedure: 'Cesarean Section' },
      { start: '11:30', end: '13:00', specialty: 'OB/GYN', surgeon: 'Dr. Garcia', procedure: 'Myomectomy' },
      { start: '13:30', end: '15:00', specialty: 'OB/GYN', surgeon: 'Dr. Martinez', procedure: 'Oophorectomy' },
      { start: '15:30', end: '17:00', specialty: 'OB/GYN', surgeon: 'Dr. Garcia', procedure: 'Tubal Ligation' }
    ]
  }
];
