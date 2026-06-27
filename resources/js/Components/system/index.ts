// resources/js/Components/system/index.ts
//
// THE Zephyrus design system — the single gold-standard component vocabulary.
// Every page builds from these primitives (promoted from the Command Center,
// the reference surface). Do NOT introduce another card/tile/panel component;
// if a metric or panel looks different on two screens, one of them is wrong.
//
//   import { Section, MetricGrid, metric, Panel, UnitHeatStrip } from '@/Components/system';
//
// - metric()      build the rich KpiMetric contract from minimal input
// - MetricGrid    the one dense metric wall (auto-fit KpiTiles, sparklines on)
// - Section       the one section header (icon + title + summary + drill link)
// - Panel         the one surface (Surface)
// - KpiTile       the one metric tile (gauge/number + sparkline + trend + detail)
// - Gauge         radial gauge for a single % value
// - UnitHeatStrip dense per-unit occupancy strip
// - StrainIndex   composite strain instrument
// - Band          full Command Center band (header + tile grid + subgroups)
// - EmptyState    the one empty state
// - STATUS_VAR    status level → CSS color var (the four-color vocabulary)

export { KpiTile } from '@/Components/CommandCenter/KpiTile';
export { Panel } from '@/Components/CommandCenter/Panel';
export { Gauge } from '@/Components/CommandCenter/Gauge';
export { Band } from '@/Components/CommandCenter/Band';
export { UnitHeatStrip } from '@/Components/CommandCenter/UnitHeatStrip';
export { StrainIndex } from '@/Components/CommandCenter/StrainIndex';
export { EmptyState } from '@/Components/CommandCenter/states';
export { STATUS_VAR } from '@/Components/CommandCenter/status';

export { Section } from './Section';
export type { SectionProps } from './Section';
export { MetricGrid } from './MetricGrid';
export type { MetricGridProps } from './MetricGrid';
export { metric } from './metric';
export type { MetricInput, KpiMetric, StatusLevel } from './metric';
export type { UnitCensus } from '@/types/commandCenter';
