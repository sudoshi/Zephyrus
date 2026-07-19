// Scene vocabulary — the single source of truth for every shape/color the 4D
// Navigator renders (advancement plan §5.1). NavigatorScene builds materials
// from these constants and NavigatorLegend renders rows from LEGEND_SECTIONS,
// so one edit updates both — the legend can never lie about the scene.
//
// Pure data: no three.js import, so the legend and tests stay in the main
// bundle while the scene chunk lazy-loads.
import type { BarrierSeverity } from './projections';
import type { OccupancyTimerStatus, PatientLayerState } from './types';
import { ROUND_STOP_COLORS } from '@/features/virtualRounds/roundsScene';

// ---------------------------------------------------------------------------
// Color constants consumed by NavigatorScene materials
// ---------------------------------------------------------------------------

/** Future-half ghost palette — cool operational tones only, never coral. */
export const GHOST_COLORS: Record<string, number> = {
  expected_discharge: 0x2dd4bf, // teal
  transport_due: 0x38bdf8, // sky
  evs_due: 0x7dd3fc, // light sky
  scheduled_or_case: 0x60a5fa, // blue
};

/**
 * Open-barrier marker palette — the earned-urgency ration (watch sky /
 * warning amber / critical coral), the same status language the 48h Review
 * map paints. Coral is reserved for a barrier standing open past 48h.
 */
export const BARRIER_COLORS: Record<BarrierSeverity, { color: number; emissive: number }> = {
  critical: { color: 0xf06755, emissive: 0x5a140d }, // coral
  warning: { color: 0xeaa640, emissive: 0x4a2e08 }, // amber
  watch: { color: 0x38bdf8, emissive: 0x0c3a4d }, // sky
};

/** Census disk status colors (green ok / amber watch / coral delayed). */
export const OCCUPANCY_STATUS_COLORS: Record<OccupancyTimerStatus, { color: number; emissive: number }> = {
  ok: { color: 0x77c06f, emissive: 0x143d17 },
  watch: { color: 0xe0a33f, emissive: 0x4a3210 },
  delayed: { color: 0xf06755, emissive: 0x5a140d },
};

/** Timer pip colors — brighter siblings of the disk statuses. */
export const TIMER_PIP_COLORS: Record<OccupancyTimerStatus, number> = {
  ok: 0x93e088,
  watch: 0xffd166,
  delayed: 0xff8a75,
};

/** Predicted-census pillar (future half). */
export const FORECAST_COLOR = 0x64bfd0;

export { ROUND_STOP_COLORS };

/** Pinned round stops render amber regardless of state. */
export const ROUND_PINNED_COLOR = 0xeaa640;

/**
 * Patient-token identity hue is clamped to 160°–280° (teal → blue → violet)
 * so a token can never impersonate amber/coral status colors (finding E-3).
 */
export const PATIENT_HUE_MIN = 160;
export const PATIENT_HUE_SPAN = 120;

/** Deterministic identity hue for a patient id, inside the clamped range. */
export function patientHue(value: string): number {
  let hash = 0;
  for (let index = 0; index < value.length; index += 1) {
    hash = ((hash << 5) - hash) + value.charCodeAt(index);
  }
  return PATIENT_HUE_MIN + (Math.abs(hash) % PATIENT_HUE_SPAN);
}

// ---------------------------------------------------------------------------
// Base-model category treatments (plan §5.2) — glTF `extras.category` is
// already in mesh.userData; these tints make hallway/room/bed/ED legible.
// All tints stay in the blue/slate operational family. `floor` and unknown
// categories keep the model's own material (clone + opacity) as the datum.
// ---------------------------------------------------------------------------

export interface BaseCategoryStyle {
  color: number;
  opacity: number;
  /** Emissive as a fraction of color (0 = none). */
  emissiveScale: number;
}

export const BASE_CATEGORY_STYLES: Record<string, BaseCategoryStyle> = {
  corridor: { color: 0x3d434b, opacity: 0.35, emissiveScale: 0 }, // circulation reads as negative space
  patient_room: { color: 0x59616d, opacity: 0.6, emissiveScale: 0 }, // the default "room"
  bed: { color: 0x7189a8, opacity: 0.85, emissiveScale: 0.12 }, // the care asset
  care_unit: { color: 0x475569, opacity: 0.16, emissiveScale: 0 }, // grouping shell, not object
  emergency_department: { color: 0x4f8dab, opacity: 0.55, emissiveScale: 0.06 }, // high-tempo zone
  imaging: { color: 0x5b7db1, opacity: 0.55, emissiveScale: 0.06 }, // interventional
  procedure_room: { color: 0x5b7db1, opacity: 0.55, emissiveScale: 0.06 },
  procedure_support: { color: 0x5b7db1, opacity: 0.45, emissiveScale: 0 },
  elevator: { color: 0x9aa1a6, opacity: 0.85, emissiveScale: 0 }, // vertical circulation landmark
  helipad: { color: 0x4a4f4c, opacity: 0.5, emissiveScale: 0 }, // context only
  support_infrastructure: { color: 0x4a4f4c, opacity: 0.5, emissiveScale: 0 },
};

// ---------------------------------------------------------------------------
// Element labels — `userData.kind` → operator-readable element type, used by
// the hover chip and the inspector title prefix (findings E-4/E-5).
// ---------------------------------------------------------------------------

export const ELEMENT_LABELS: Record<string, string> = {
  'patient-token': 'Patient',
  'patient-trail': 'Movement trail',
  'occupancy-marker': 'Census disk',
  'occupancy-timer': 'Timer',
  'projection-ghost': 'Projection',
  'forecast-heat': 'Forecast census',
  barrier: 'Open barrier',
  'round-stop': 'Round stop',
};

export const CATEGORY_LABELS: Record<string, string> = {
  floor: 'Floor plate',
  corridor: 'Corridor',
  patient_room: 'Patient room',
  bed: 'Bed',
  care_unit: 'Care unit shell',
  emergency_department: 'Emergency department',
  imaging: 'Imaging',
  procedure_room: 'Procedure room',
  procedure_support: 'Procedure support',
  elevator: 'Elevator',
  helipad: 'Helipad',
  support_infrastructure: 'Support infrastructure',
};

/** Operator-readable element type for any raycast hit's userData. */
export function elementLabelFor(data: Record<string, unknown>): string | null {
  const kind = typeof data.kind === 'string' ? data.kind : null;
  if (kind && ELEMENT_LABELS[kind]) return ELEMENT_LABELS[kind];
  const category = typeof data.category === 'string' ? data.category : null;
  if (category && CATEGORY_LABELS[category]) return CATEGORY_LABELS[category];
  return null;
}

// ---------------------------------------------------------------------------
// Legend (finding E-1) — rendered verbatim by NavigatorLegend. Every entry
// carries the layer it belongs to so hidden layers can dim their rows, and
// status colors always appear WITH their worded meaning (never color alone).
// ---------------------------------------------------------------------------

export type SceneShape =
  | 'sphere'
  | 'line'
  | 'disk'
  | 'pip'
  | 'ghost'
  | 'pillar'
  | 'diamond'
  | 'ring'
  | 'block';

export interface LegendEntry {
  key: string;
  label: string;
  shape: SceneShape;
  colorHex: number;
  description: string;
  /** Scene layer this element lives on (`base` = the building itself). */
  layer: keyof PatientLayerState;
}

export interface LegendSection {
  title: string;
  entries: LegendEntry[];
}

export const LEGEND_SECTIONS: LegendSection[] = [
  {
    title: 'People & movement',
    entries: [
      {
        key: 'patient-token',
        label: 'Patient',
        shape: 'sphere',
        colorHex: 0x49c6df, // a true renderable token tone: hsl(190, 70%, 58%)
        description: 'One patient. Color is identity (teal→violet only), never status.',
        layer: 'tokens',
      },
      {
        key: 'patient-trail',
        label: 'Movement trail',
        shape: 'line',
        colorHex: 0x49c6df,
        description: 'Where that patient has been up to the scrubbed time.',
        layer: 'trails',
      },
    ],
  },
  {
    title: 'Occupancy & timers',
    entries: [
      {
        key: 'occupancy-ok',
        label: 'Occupied — on track',
        shape: 'disk',
        colorHex: OCCUPANCY_STATUS_COLORS.ok.color,
        description: 'Disk radius grows with stay length. Green means on track.',
        layer: 'heat',
      },
      {
        key: 'occupancy-watch',
        label: 'Occupied — watch',
        shape: 'disk',
        colorHex: OCCUPANCY_STATUS_COLORS.watch.color,
        description: 'Amber means a timer is approaching its target.',
        layer: 'heat',
      },
      {
        key: 'occupancy-delayed',
        label: 'Occupied — delayed',
        shape: 'disk',
        colorHex: OCCUPANCY_STATUS_COLORS.delayed.color,
        description: 'Coral means a timer is past target — an elapsed-time signal, not a verified barrier.',
        layer: 'heat',
      },
      {
        key: 'occupancy-timer',
        label: 'Timer pip',
        shape: 'pip',
        colorHex: TIMER_PIP_COLORS.watch,
        description: 'Up to four individual timers around a disk, same status colors.',
        layer: 'heat',
      },
    ],
  },
  {
    title: 'Barriers',
    entries: [
      {
        key: 'barrier-watch',
        label: 'Open barrier — under 24h',
        shape: 'diamond',
        colorHex: BARRIER_COLORS.watch.color,
        description: 'A logged operational barrier (sky). Severity is earned from open-age.',
        layer: 'barriers',
      },
      {
        key: 'barrier-warning',
        label: 'Open barrier — 24h+',
        shape: 'diamond',
        colorHex: BARRIER_COLORS.warning.color,
        description: 'Amber: standing open a full day.',
        layer: 'barriers',
      },
      {
        key: 'barrier-critical',
        label: 'Open barrier — 48h+',
        shape: 'diamond',
        colorHex: BARRIER_COLORS.critical.color,
        description: 'Coral: a real breach — two days unresolved. Larger diamond, same meaning.',
        layer: 'barriers',
      },
    ],
  },
  {
    title: 'Forecast',
    entries: [
      {
        key: 'projection-ghost',
        label: 'Projected event',
        shape: 'ghost',
        colorHex: GHOST_COLORS.expected_discharge,
        description: 'Translucent sphere in the future half — discharge (teal), transport (sky), EVS (light sky), OR case (blue). Opacity = confidence.',
        layer: 'ghosts',
      },
      {
        key: 'forecast-heat',
        label: 'Predicted census',
        shape: 'pillar',
        colorHex: FORECAST_COLOR,
        description: 'Cyan pillar per unit; height = predicted census at the scrubbed future time.',
        layer: 'ghosts',
      },
    ],
  },
  {
    title: 'Rounds',
    entries: [
      {
        key: 'round-stop',
        label: 'Round stop',
        shape: 'ring',
        colorHex: ROUND_STOP_COLORS.queued,
        description: 'Flat ring = a Virtual Rounds visit. Slate queued, blue in progress, amber awaiting input, sky ready for review, teal rounded.',
        layer: 'rounds',
      },
      {
        key: 'round-stop-pinned',
        label: 'Pinned round stop',
        shape: 'ring',
        colorHex: ROUND_PINNED_COLOR,
        description: 'Amber, slightly larger: pinned for attention.',
        layer: 'rounds',
      },
    ],
  },
  {
    title: 'Building',
    entries: [
      {
        key: 'category-bed',
        label: 'Bed',
        shape: 'block',
        colorHex: BASE_CATEGORY_STYLES.bed.color,
        description: 'Slate-blue: the care asset.',
        layer: 'base',
      },
      {
        key: 'category-patient_room',
        label: 'Patient room',
        shape: 'block',
        colorHex: BASE_CATEGORY_STYLES.patient_room.color,
        description: 'Neutral slate room volume.',
        layer: 'base',
      },
      {
        key: 'category-corridor',
        label: 'Corridor',
        shape: 'block',
        colorHex: BASE_CATEGORY_STYLES.corridor.color,
        description: 'Darker slate — circulation reads as negative space.',
        layer: 'base',
      },
      {
        key: 'category-emergency_department',
        label: 'Emergency department',
        shape: 'block',
        colorHex: BASE_CATEGORY_STYLES.emergency_department.color,
        description: 'Muted sky zone tint.',
        layer: 'base',
      },
      {
        key: 'category-imaging',
        label: 'Imaging / procedures',
        shape: 'block',
        colorHex: BASE_CATEGORY_STYLES.imaging.color,
        description: 'Muted blue interventional zones.',
        layer: 'base',
      },
      {
        key: 'category-elevator',
        label: 'Elevator',
        shape: 'block',
        colorHex: BASE_CATEGORY_STYLES.elevator.color,
        description: 'Light neutral landmark, visible on every floor filter.',
        layer: 'base',
      },
    ],
  },
];
