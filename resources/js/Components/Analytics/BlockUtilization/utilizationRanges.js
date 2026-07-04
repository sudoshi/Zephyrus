// Utilization color legend for the block-utilization heat cells (categorical
// chart palette — sanctioned; not status color). Extracted from the deleted
// mock-data/block-utilization.js bundle (P5).
//
// noBlock was referenced by DayOfWeekAnalysis/ProviderDetails for null values
// but never existed in the mock (a latent TypeError) — defined here as the
// neutral no-data grey.
export const utilizationRanges = {
  low: { min: 0, max: 50, color: '#ef4444' },
  medium: { min: 50, max: 70, color: '#f59e0b' },
  high: { min: 70, max: 85, color: '#10b981' },
  optimal: { min: 85, max: 100, color: '#3b82f6' },
  noBlock: { color: '#e6e6e6' },
};
