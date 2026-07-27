/**
 * Deviation pattern-translation layer (FLOW-4D plan §8 C2, finding CF-3).
 *
 * Mirrors the sidecar's versioned pathway registry (arena/app/pathways.py —
 * the SSOT for codes, labels, owners, and activity order) so the drawer can
 * speak clinician pattern language ("late" / "missing" / "out of sequence" /
 * "flagged") instead of rule codes. Pinned by tests against every current
 * deviation code of the three pathways; an unknown code degrades to a
 * humanized label, never a crash — the sidecar may version ahead of the UI.
 *
 * Pure data + functions: no fetch, no three.js, no React.
 */

export type DeviationPattern = 'late' | 'missing' | 'sequence' | 'flagged';

export interface DeviationTranslation {
  code: string;
  pattern: DeviationPattern | 'other';
  /** Clinician copy — the registry's deviation_labels wording. */
  label: string;
}

export interface PathwayActivity {
  key: string;
  label: string;
}

/** One headline element (ERAS vertical-compliance framing): met unless any of
 * its codes fired. Elements group the codes that judge one bundle element. */
export interface PathwayElement {
  key: string;
  label: string;
  codes: string[];
}

export interface PathwaySpec {
  key: string;
  label: string;
  version: number;
  /** Registry owner id (e.g. clinical:critical-care) — provenance line. */
  owner: string;
  ownerLabel: string;
  activities: PathwayActivity[];
  elements: PathwayElement[];
  deviations: Record<string, { pattern: DeviationPattern; label: string }>;
}

export const PATHWAY_SPECS: Record<string, PathwaySpec> = {
  sepsis: {
    key: 'sepsis',
    label: 'Sepsis bundle (SEP-3)',
    version: 1,
    owner: 'clinical:critical-care',
    ownerLabel: 'critical care',
    activities: [
      { key: 'sepsis_recognition', label: 'Sepsis recognition' },
      { key: 'vitals_sirs', label: 'SIRS vitals' },
      { key: 'lactate_order', label: 'Lactate ordered' },
      { key: 'lactate_result', label: 'Lactate result' },
      { key: 'blood_culture_order', label: 'Blood cultures ordered' },
      { key: 'antibiotic_administration', label: 'Antibiotics administered' },
      { key: 'fluid_bolus_30mlkg', label: 'Fluid bolus 30 mL/kg' },
      { key: 'vasopressor_start', label: 'Vasopressor start' },
      { key: 'repeat_lactate_order', label: 'Repeat lactate ordered' },
      { key: 'repeat_lactate_result', label: 'Repeat lactate result' },
    ],
    elements: [
      { key: 'lactate', label: 'Initial lactate', codes: ['no_lactate'] },
      { key: 'antibiotics', label: 'Antibiotics within 3 h', codes: ['no_antibiotic', 'antibiotic_late'] },
      { key: 'cultures', label: 'Cultures before antibiotics', codes: ['culture_after_antibiotic'] },
      { key: 'repeat_lactate', label: 'Repeat lactate', codes: ['no_repeat_lactate'] },
    ],
    deviations: {
      no_lactate: { pattern: 'missing', label: 'Lactate not ordered' },
      no_antibiotic: { pattern: 'missing', label: 'No antibiotic administered' },
      antibiotic_late: { pattern: 'late', label: 'Antibiotic beyond the 3-hour target' },
      culture_after_antibiotic: { pattern: 'sequence', label: 'Blood cultures drawn after antibiotics' },
      no_repeat_lactate: { pattern: 'missing', label: 'Repeat lactate not documented' },
    },
  },
  surgical_safety: {
    key: 'surgical_safety',
    label: 'WHO Surgical Safety Checklist',
    version: 1,
    owner: 'clinical:perioperative',
    ownerLabel: 'perioperative',
    activities: [{ key: 'Safety_Check', label: 'Safety check (Sign-In / Time-Out / Sign-Out)' }],
    elements: [
      { key: 'steps_present', label: 'All three checklist steps', codes: ['safety_step_missing'] },
      { key: 'steps_clean', label: 'No step flagged', codes: ['safety_check_flagged'] },
    ],
    deviations: {
      safety_step_missing: { pattern: 'missing', label: 'A checklist step (Sign-In / Time-Out / Sign-Out) is missing' },
      safety_check_flagged: { pattern: 'flagged', label: 'A checklist step was flagged incomplete' },
    },
  },
  home_hospital: {
    key: 'home_hospital',
    label: 'Home Hospital virtual-ward pathway (AHCAH)',
    version: 1,
    owner: 'clinical:home-hospital',
    ownerLabel: 'home hospital',
    activities: [
      { key: 'home-refer', label: 'Referral' },
      { key: 'home-activate', label: 'Activation' },
      { key: 'home-visit-complete', label: 'In-person visit' },
      { key: 'home-escalation-open', label: 'Escalation opened' },
      { key: 'home-escalation-resolve', label: 'Escalation resolved' },
      { key: 'home-discharge', label: 'Discharge' },
    ],
    elements: [
      { key: 'activation', label: 'Activation within 24 h', codes: ['activation_beyond_sla'] },
      { key: 'visits', label: 'Two visits per ward day', codes: ['visit_cadence_below_floor'] },
      { key: 'escalations', label: 'Escalations resolved', codes: ['escalation_unresolved'] },
      { key: 'response', label: 'Response within 30 min', codes: ['escalation_response_late'] },
    ],
    deviations: {
      activation_beyond_sla: { pattern: 'late', label: 'Referral-to-activation beyond the 24-hour SLA' },
      visit_cadence_below_floor: { pattern: 'missing', label: 'In-person visits below the 2-per-day waiver floor' },
      escalation_unresolved: { pattern: 'missing', label: 'An escalation was opened but never resolved' },
      escalation_response_late: { pattern: 'late', label: 'Escalation response beyond the 30-minute floor' },
    },
  },
};

export const PATTERN_LABELS: Record<DeviationPattern | 'other', string> = {
  late: 'late',
  missing: 'not yet observed',
  sequence: 'out of sequence',
  flagged: 'flagged',
  other: 'deviation',
};

/** Registry pathway label, falling back to a humanized key. */
export function pathwayLabel(pathwayKey: string): string {
  return PATHWAY_SPECS[pathwayKey]?.label ?? humanize(pathwayKey);
}

export function translateDeviation(pathwayKey: string, code: string): DeviationTranslation {
  const known = PATHWAY_SPECS[pathwayKey]?.deviations[code];
  if (known) return { code, pattern: known.pattern, label: known.label };
  return { code, pattern: 'other', label: humanize(code) };
}

/** ERAS elements-met headline: an element is met unless one of its codes fired.
 * Codes outside the element map (a newer sidecar) count as one extra unmet
 * element each, so the headline can never overstate compliance. */
export function elementsMet(pathwayKey: string, deviationCodes: string[]): { met: number; total: number } {
  const spec = PATHWAY_SPECS[pathwayKey];
  const fired = new Set(deviationCodes);
  if (!spec) return { met: 0, total: fired.size };

  const mapped = new Set(spec.elements.flatMap((element) => element.codes));
  const unmetElements = spec.elements.filter((element) => element.codes.some((code) => fired.has(code))).length;
  const unknownFired = [...fired].filter((code) => !mapped.has(code)).length;
  const total = spec.elements.length + unknownFired;
  return { met: total - unmetElements - unknownFired, total };
}

/** The two-lane strip rows: spec activities in order, each with the observed
 * first-occurrence time (or null = not yet observed). */
export function expectedObservedLanes(
  pathwayKey: string,
  activityTimeline: Record<string, string | null | undefined>,
): Array<{ key: string; label: string; observedAt: string | null }> {
  const spec = PATHWAY_SPECS[pathwayKey];
  if (!spec) return [];
  return spec.activities.map((activity) => ({
    key: activity.key,
    label: activity.label,
    observedAt: typeof activityTimeline[activity.key] === 'string' ? (activityTimeline[activity.key] as string) : null,
  }));
}

// Targets mirrored from the registry constants (SEPSIS_ABX_TARGET_MIN etc.).
const EVIDENCE_RULES: Record<string, { from: string; to: string; targetMinutes: number; noun: string }> = {
  antibiotic_late: {
    from: 'sepsis_recognition',
    to: 'antibiotic_administration',
    targetMinutes: 180,
    noun: 'Antibiotics',
  },
  activation_beyond_sla: {
    from: 'home-refer',
    to: 'home-activate',
    targetMinutes: 24 * 60,
    noun: 'Activation',
  },
};

/**
 * Evidence line for a timing deviation, computed from the case's observed
 * timeline: "Antibiotics 42 min past the 3 h target". Null when the timeline
 * cannot support the arithmetic — the label alone still stands.
 */
export function deviationEvidence(
  code: string,
  activityTimeline: Record<string, string | null | undefined>,
): string | null {
  const rule = EVIDENCE_RULES[code];
  if (!rule) return null;
  const fromMs = parseIso(activityTimeline[rule.from]);
  const toMs = parseIso(activityTimeline[rule.to]);
  if (fromMs === null || toMs === null) return null;
  const overMinutes = Math.round((toMs - fromMs) / 60_000) - rule.targetMinutes;
  if (overMinutes <= 0) return null;
  return `${rule.noun} ${durationLabel(overMinutes)} past the ${durationLabel(rule.targetMinutes)} target`;
}

/** Hover-chip state wording (§7.2): "sepsis · late step" — state, no identity. */
export function sceneChipLabel(pathways: Array<{ pathway: string; deviations: string[] }>): string {
  if (pathways.length === 0) return 'pathway deviation';
  const first = pathways[0];
  const short = shortPathwayLabel(first.pathway);
  const totalDeviations = pathways.reduce((sum, entry) => sum + entry.deviations.length, 0);
  if (pathways.length === 1 && first.deviations.length === 1) {
    return `${short} · ${stepWording(translateDeviation(first.pathway, first.deviations[0]).pattern)}`;
  }
  const suffix = pathways.length > 1 ? ` +${pathways.length - 1}` : '';
  return `${short}${suffix} · ${totalDeviations} deviations`;
}

/** Compact pathway name for in-scene chips (full label stays in the drawer). */
export function shortPathwayLabel(pathwayKey: string): string {
  switch (pathwayKey) {
    case 'sepsis': return 'sepsis';
    case 'surgical_safety': return 'surgical safety';
    case 'home_hospital': return 'home hospital';
    default: return humanize(pathwayKey).toLowerCase();
  }
}

function stepWording(pattern: DeviationPattern | 'other'): string {
  switch (pattern) {
    case 'late': return 'late step';
    case 'missing': return 'missing step';
    case 'sequence': return 'out-of-sequence step';
    case 'flagged': return 'flagged step';
    default: return 'deviation';
  }
}

function humanize(value: string): string {
  const text = value.replaceAll(/[_-]+/g, ' ').trim();
  return text.length > 0 ? text[0].toUpperCase() + text.slice(1) : value;
}

function parseIso(value: string | null | undefined): number | null {
  if (typeof value !== 'string' || value === '') return null;
  const ms = Date.parse(value);
  return Number.isNaN(ms) ? null : ms;
}

function durationLabel(minutes: number): string {
  if (minutes < 60) return `${minutes} min`;
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`;
}
