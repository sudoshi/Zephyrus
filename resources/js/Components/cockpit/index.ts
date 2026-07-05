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
// P6 — the one tier↔state severity mapping (Eddy risk → cockpit state).
export { RISK_TO_STATUS, statusForRisk } from './riskStatus';
export { Sparkline, type SparklineProps } from './Sparkline';
export { MeterBar, type MeterBarProps } from './MeterBar';
export { RadialGauge, type RadialGaugeProps, type RadialGaugeBand } from './RadialGauge';
export { StatusChip, type StatusChipProps } from './StatusChip';
export { CensusChip, type CensusChipProps } from './CensusChip';
export { Panel, type PanelProps } from './Panel';
export { DataTable, type DataTableProps } from './DataTable';
// P2 — the cockpit overview grammar.
export { Tile, MetricRow, type TileProps, type MetricRowProps } from './Tile';
export { ProvenanceBadge } from './ProvenanceBadge';
export { CommandBar } from './CommandBar';
export { CensusStrip } from './CensusStrip';
export { AlertTicker } from './AlertTicker';
export { DomainGrid } from './DomainGrid';
export { OkrScorecard, okrProgressPct } from './OkrScorecard';
export { CockpitOverview } from './CockpitOverview';
// P3 — the A2 drill surface.
export { DrillModal, type DrillModalProps } from './DrillModal';
// P8 WS-2b — the mount-anywhere altitude face (unit / department / service line).
export { ScopedFaceView, type ScopedFaceViewProps } from './ScopedFaceView';
// P8 WS-3 — the A2P patient lens (in-place drill + render surface).
export { PatientLens, type PatientLensProps } from './PatientLens';
export { PatientLensModal, type PatientLensModalProps } from './PatientLensModal';
// P6 — the silo pages reconciled into the cockpit (WS-5).
export { ActionInboxModal } from './ActionInboxModal';
export { ExecutiveBriefPanel } from './ExecutiveBriefPanel';
