// Mock data for PDSA cycles
export const currentCycles = [
  {
    id: 1,
    title: 'Reduce ED Wait Times',
    objective: 'Decrease average ED wait time by 25%',
    phase: 'do',
    startDate: '2025-01-15',
    endDate: '2025-03-15',
    metrics: 'Average wait time in minutes',
    expectedOutcome: '25% reduction in wait time',
    progress: 45,
    status: 'active'
  },
  {
    id: 2,
    title: 'Improve Patient Handoffs',
    objective: 'Standardize handoff process across departments',
    phase: 'study',
    startDate: '2025-02-01',
    endDate: '2025-04-01',
    metrics: 'Handoff errors per month',
    expectedOutcome: '50% reduction in handoff errors',
    progress: 30,
    status: 'active'
  }
];

// Mock data for PDSA barriers
export const barriers = [
  {
    id: 1,
    description: 'Sample barrier',
    priority: 'medium',
    status: 'identified',
    mitigation: 'Sample mitigation plan',
  },
  // Add more mock barriers as needed
];
