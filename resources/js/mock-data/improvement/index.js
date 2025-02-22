// Mock data for improvement opportunities
export const opportunities = [
  {
    id: 1,
    title: 'Sample Opportunity',
    description: 'This is a placeholder opportunity',
    status: 'active',
    priority: 'medium',
    createdAt: new Date().toISOString(),
  }
];

export const activePDSACycles = [
  {
    id: 1,
    title: 'Reduce ED Wait Times',
    status: 'active',
    phase: 'do',
    startDate: '2025-01-15',
    endDate: '2025-03-15',
    progress: 45
  },
  {
    id: 2,
    title: 'Improve Patient Handoffs',
    status: 'active',
    phase: 'study',
    startDate: '2025-02-01',
    endDate: '2025-04-01',
    progress: 30
  }
];

export const improvementStats = {
  totalOpportunities: 10,
  activeOpportunities: 5,
  completedOpportunities: 5,
  totalCycles: 3,
  activeCycles: 2,
  completedCycles: 1,
};

export const metrics = {
  total: 1,
  active: 1,
  completed: 0
};
