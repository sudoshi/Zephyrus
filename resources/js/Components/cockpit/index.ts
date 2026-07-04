// resources/js/Components/cockpit/index.ts
//
// The shared Zephyrus 2.0 cockpit primitive library (P0). Everything here
// layers on the ONE Surface primitive and the ONE STATUS_VAR color bridge,
// driven by the single statusStyle() helper that mirrors the server
// StatusEngine. See docs/ZEPHYRUS-2.0-PLAN.md Part V.
export {
  statusStyle,
  cockpitStatusStyle,
  COCKPIT_STATE_TO_LEVEL,
  LEVEL_TO_COCKPIT_STATE,
  type StatusStyle,
  type StatusGlyph,
} from './statusStyle';
export { Sparkline, type SparklineProps } from './Sparkline';
export { MeterBar, type MeterBarProps } from './MeterBar';
export { RadialGauge, type RadialGaugeProps, type RadialGaugeBand } from './RadialGauge';
export { StatusChip, type StatusChipProps } from './StatusChip';
export { CensusChip, type CensusChipProps } from './CensusChip';
export { Panel, type PanelProps } from './Panel';
export { DataTable, type DataTableProps } from './DataTable';
