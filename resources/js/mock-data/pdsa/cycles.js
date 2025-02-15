// Mock data for PDSA cycles
export const cycles = [
  {
    id: 1,
    title: 'Sample Cycle',
    plan: {
      objective: 'This is a sample objective for the cycle.',
    },
    status: 'in_progress',
    dueDate: new Date().toISOString(),
    progress: 50,
    priority: 'medium',
    owner: 'User A',
    team: ['User B', 'User C'],
  },
  // Add more mock cycles as needed
];
