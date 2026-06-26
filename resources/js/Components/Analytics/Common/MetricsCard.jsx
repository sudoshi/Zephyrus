// Unified with the single canonical metric card. This module previously held a
// near-duplicate of Components/Common/MetricsCard (both already rendered on the
// canon Card surface, differing only in icon treatment / grid gap). It now
// re-exports the canonical implementation so there is ONE metric card look
// across the app. Existing importers of this path keep working unchanged.
export { default, MetricsCardGroup } from '@/Components/Common/MetricsCard';
