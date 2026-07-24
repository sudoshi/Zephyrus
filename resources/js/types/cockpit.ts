// resources/js/types/cockpit.ts
//
// Zephyrus 2.0 cockpit contract additions. These EXTEND types/commandCenter.ts
// (the frozen Command Center Zod contract) — they never replace it. See
// docs/product/ZEPHYRUS-2.0-PLAN.md Part IV (status vocabulary) and Part V §3 (Cell
// grammar). Per decision D7 the ISA-101 logical vocabulary is an alias layer
// over the existing StatusLevel enum — no physical rename anywhere.
import { z } from 'zod';
import { statusLevels } from './commandCenter';

// The five ISA-101 logical states the server StatusEngine emits.
export const cockpitStates = ['normal', 'ok', 'watch', 'warn', 'crit'] as const;
export const cockpitStateSchema = z.enum(cockpitStates);
export type CockpitState = z.infer<typeof cockpitStateSchema>;

export const metricDirections = ['up', 'down', 'neutral'] as const;
export const metricDirectionSchema = z.enum(metricDirections);
export type MetricDirection = z.infer<typeof metricDirectionSchema>;

// Spec §3.1 MetricValue — the shape app/Support/Cockpit/MetricValue.php emits.
// `status` is the LOGICAL state; the canon token bridge happens client-side in
// Components/cockpit/statusStyle.ts (the backend stays canon-agnostic).
export const cockpitMetricValueSchema = z.object({
  key: z.string(),
  label: z.string(),
  value: z.number(),
  display: z.string(),
  unit: z.string().nullable(),
  sub: z.string().nullable(),
  status: cockpitStateSchema,
  target: z.number().nullable(),
  direction: metricDirectionSchema,
  trend: z.array(z.number()),
  trendLabel: z.string().nullable(),
  updatedAt: z.string(),
  // Carries provenance ('demo' for mocked domains per D5) and watch-band config.
  metadata: z.record(z.string(), z.unknown()).optional(),
});
export type CockpitMetricValue = z.infer<typeof cockpitMetricValueSchema>;

// Spec §6.4 Cell grammar — the single typed vocabulary for every drill detail
// table (cockpit/DataTable.tsx renders it; the P1 DrillBuilder emits it).
export const cellBarSchema = z.object({
  bar: z.object({
    pct: z.number(),
    status: z.enum(statusLevels),
    label: z.string().optional(),
  }),
});
export const cellChipSchema = z.object({ chip: z.enum(statusLevels) });
export const cellTagSchema = z.object({
  tag: z.object({ text: z.string(), status: z.enum(statusLevels) }),
});
export const cellTextSchema = z.object({
  v: z.union([z.string(), z.number()]),
  strong: z.boolean().optional(),
  dim: z.boolean().optional(),
  status: z.enum(statusLevels).optional(),
  href: z.string().startsWith('/').optional(),
});
// P8 WS-4 — the row-drill cell: a bed/board/patient row that descends to the
// A2P patient lens (kills the old onDrill → no-op). `patientRef` is an opaque
// ptok_ context token; DataTable renders it as a drill button when the table is
// given an onRowDrill handler, else as plain text (graceful, static-safe). RBAC
// is enforced at the destination (/cockpit/patient is EnforceFlowLens-gated), so
// the token is safe to emit — the affordance never leaks patient data itself.
export const cellDrillSchema = z.object({
  drill: z.object({
    patientRef: z.string(),
    text: z.string(),
    strong: z.boolean().optional(),
  }),
});
// P8 density — a small-multiple trend inside a board row (e.g. per-unit 24h
// census on the service-line board). Decorative like the Tile sparkline; the
// numeric columns beside it are the accessible values. Fewer than 2 points
// renders an em-dash, never a broken chart.
export const cellSparkSchema = z.object({
  spark: z.object({
    data: z.array(z.number()),
    status: z.enum(statusLevels).optional(),
  }),
});
export const cellSchema = z.union([
  z.string(),
  z.number(),
  cellBarSchema,
  cellChipSchema,
  cellTagSchema,
  cellDrillSchema,
  cellSparkSchema,
  cellTextSchema,
]);
export type Cell = z.infer<typeof cellSchema>;

export const columnSchema = z.object({
  key: z.string(),
  header: z.string(),
  align: z.enum(['left', 'right']).optional(),
  note: z.string().optional(),
});
export type Column = z.infer<typeof columnSchema>;

export const drillTableSchema = z.object({
  caption: z.string(),
  columns: z.array(columnSchema),
  rows: z.array(z.record(z.string(), cellSchema)),
});
export type DrillTable = z.infer<typeof drillTableSchema>;

// ---------------------------------------------------------------------------
// Spec §3.2 snapshot sections (P1). These are ADDITIVE keys on the existing
// /api/cockpit/snapshot payload — the legacy commandCenter.ts contract keys
// coexist untouched until P2 flips the page. Parse the sections with
// cockpitSnapshotSectionsSchema; unknown extra keys are ignored by design.
// ---------------------------------------------------------------------------

export const capacityStatusSchema = z.object({
  level: z.string(),
  code: z.enum(['green', 'yellow', 'red']),
  status: cockpitStateSchema,
});
export type CapacityStatus = z.infer<typeof capacityStatusSchema>;

export const cockpitAlertSchema = z.object({
  // HFE Phase 1: lifecycle row id for the acknowledge endpoint. Optional so
  // pre-migration snapshots still parse (additive contract change only).
  id: z.number().optional(),
  key: z.string(),
  status: z.enum(['warn', 'crit']),
  text: z.string(),
  provenance: z.literal('demo').optional(),
  // P6: present once the AlertEngine persists the flap-damped lifecycle —
  // the ISO instant the alert actually OPENED (not this snapshot's time).
  openedAt: z.string().nullable().optional(),
  // HFE Phase 1: acknowledgement = ownership, not suppression. Cleared
  // server-side when the condition escalates warn→crit.
  acknowledgedAt: z.string().nullable().optional(),
  acknowledgedBy: z.string().nullable().optional(),
  // P6 WS-4: the server-resolved Eddy catalog action this alert pre-seeds
  // (EddyActionService::actionForAlert) + its human label for the hand-off.
  action: z.string().optional(),
  actionLabel: z.string().optional(),
});
export type CockpitAlert = z.infer<typeof cockpitAlertSchema>;

export const okrCardSchema = cockpitMetricValueSchema.extend({
  objective: z.string().nullable(),
  keyResult: z.string(),
  owner: z.string().nullable(),
});
export type OkrCard = z.infer<typeof okrCardSchema>;

export const domainProvenances = ['live', 'partial', 'demo'] as const;
export const cockpitDomainSchema = z.object({
  provenance: z.enum(domainProvenances),
  gaugeKey: z.string().nullable(),
  tiles: z.array(cockpitMetricValueSchema),
});
export type CockpitDomain = z.infer<typeof cockpitDomainSchema>;

// Spec §3.3 drill payload — what GET /api/cockpit/drill/{domain} returns and
// the P3 DrillModal consumes. Tables are the §6.4 Cell grammar (DataTable).
export const drillPayloadSchema = z.object({
  domain: z.string(),
  title: z.string(),
  sub: z.string().nullable(),
  asOf: z.string(),
  kpis: z.array(cockpitMetricValueSchema),
  tables: z.array(drillTableSchema),
  drilldownHref: z.string(),
});
export type DrillPayload = z.infer<typeof drillPayloadSchema>;

// INT-OBS 6 — per-source health ride-along. Additive/optional so the frozen
// contract and every existing consumer/test keep parsing untouched; a stale or
// degraded upstream source is now visible on the snapshot ('No silent
// fallback'). PHI-free by construction: stable keys, statuses, and mode only.
export const cockpitSourceHealthEntrySchema = z.object({
  sourceKey: z.string(),
  systemClass: z.string(),
  environment: z.string(),
  mode: z.enum(['live', 'synthetic']),
  status: z.string(),
  recordedStatus: z.string().nullable(),
  stale: z.boolean(),
  lastObservedAtIso: z.string().nullable(),
  freshUntilIso: z.string().nullable(),
});
export type CockpitSourceHealthEntry = z.infer<typeof cockpitSourceHealthEntrySchema>;

export const cockpitSourceHealthSchema = z.object({
  generatedAtIso: z.string(),
  overallStatus: z.string(),
  anyStale: z.boolean(),
  anyDegraded: z.boolean(),
  liveSourceCount: z.number(),
  syntheticSourceCount: z.number(),
  sources: z.array(cockpitSourceHealthEntrySchema),
});
export type CockpitSourceHealth = z.infer<typeof cockpitSourceHealthSchema>;

export const cockpitSnapshotSectionsSchema = z.object({
  asOf: z.string(),
  facility: z.object({
    name: z.string(),
    licensedBeds: z.number().nullable(),
    level: z.string().nullable(),
  }),
  capacityStatus: capacityStatusSchema,
  census: z.array(cockpitMetricValueSchema),
  alerts: z.array(cockpitAlertSchema),
  okrs: z.array(okrCardSchema),
  domains: z.record(z.string(), cockpitDomainSchema),
  // Optional + nullable: a snapshot built before this field, or one whose
  // digest failed, still parses cleanly.
  sourceHealth: cockpitSourceHealthSchema.nullish(),
});
export type CockpitSnapshotSections = z.infer<typeof cockpitSnapshotSectionsSchema>;

// The drillable domain registry (server: DrillBuilder::DOMAINS). ?drill= values
// outside this list are ignored on read, so a mangled deep link degrades to the
// bare cockpit instead of holding junk state.
export const cockpitDrillDomains = [
  'rtdc', 'ed', 'periop', 'staffing', 'flow', 'quality', 'service', 'financial',
  'radiology', 'lab', 'pharmacy', 'home', 'okr',
] as const;
export type CockpitDrillDomain = (typeof cockpitDrillDomains)[number];

export function isCockpitDrillDomain(value: string | null): value is CockpitDrillDomain {
  return value !== null && (cockpitDrillDomains as readonly string[]).includes(value);
}

// ---------------------------------------------------------------------------
// Spec P8 (WS-1/WS-2) — the Mount-Anywhere Cockpit. A mount scope is one of
// four altitudes; GET /api/cockpit/face returns the altitude-APPROPRIATE face
// for a resolved scope (App\Support\Cockpit\CockpitScope + ScopedFaceBuilder).
// ---------------------------------------------------------------------------

export const cockpitScopeLevels = ['house', 'service_line', 'department', 'unit'] as const;
export const cockpitScopeRefSchema = z.object({
  level: z.enum(cockpitScopeLevels),
  key: z.string().nullable(),
  label: z.string(),
  token: z.string(),
});
export type CockpitScopeLevel = (typeof cockpitScopeLevels)[number];
export type CockpitScopeRef = z.infer<typeof cockpitScopeRefSchema>;

// The face is a discriminated union on `render`:
//  - 'grid' → the mount resolved to house; the page keeps rendering the
//             DomainGrid from the untouched snapshot (this variant carries no
//             metrics — it is only a marker for which surface to mount).
//  - 'face' → an altitude-appropriate face in the SAME drill grammar (kpis +
//             §6.4 Cell tables), so the existing Tile / DataTable render every
//             altitude. Reuse-first: a unit/dept mount is altitude-appropriate
//             tiles, never the house grid shrunk.
const cockpitGridFaceSchema = z.object({
  scope: cockpitScopeRefSchema,
  render: z.literal('grid'),
  title: z.string(),
  sub: z.string().nullable(),
});
const cockpitDetailFaceSchema = z.object({
  scope: cockpitScopeRefSchema,
  render: z.literal('face'),
  title: z.string(),
  sub: z.string().nullable(),
  asOf: z.string(),
  kpis: z.array(cockpitMetricValueSchema),
  tables: z.array(drillTableSchema),
  // Present only for a department face — it reuses the domain drill verbatim.
  domain: z.string().optional(),
  drilldownHref: z.string().optional(),
});
export const cockpitFaceSchema = z.discriminatedUnion('render', [
  cockpitGridFaceSchema,
  cockpitDetailFaceSchema,
]);
export type CockpitFace = z.infer<typeof cockpitFaceSchema>;
export type CockpitDetailFace = z.infer<typeof cockpitDetailFaceSchema>;

export type SafeCockpitFace =
  | { ok: true; data: CockpitFace }
  | { ok: false; error: string };

export function safeParseCockpitFace(input: unknown): SafeCockpitFace {
  const result = cockpitFaceSchema.safeParse(input);
  if (result.success) return { ok: true, data: result.data };
  const first = result.error.issues[0];
  const where = first?.path?.length ? ` (at ${first.path.join('.')})` : '';
  return { ok: false, error: `${first?.message ?? 'Invalid cockpit face payload'}${where}` };
}

// A non-house mount token ('unit:MICU' | 'service_line:*' | 'department:*').
// The page only fetches /api/cockpit/face for these; an absent or 'house'
// token keeps the default house overview with zero extra fetch — the default
// /dashboard is unchanged.
export function isScopedMount(token: string | null): token is string {
  return token !== null && token.trim() !== '' && token !== 'house';
}

export type SafeCockpitSections =
  | { ok: true; data: CockpitSnapshotSections }
  | { ok: false; error: string };

// Mirror of safeParseCommandCenterData: the cockpit grammar renders only from
// a payload that parses cleanly; anything else degrades to the classic view
// (never white-screen — the legacy contract is the deeper fallback).
export function safeParseCockpitSections(input: unknown): SafeCockpitSections {
  const result = cockpitSnapshotSectionsSchema.safeParse(input);
  if (result.success) return { ok: true, data: result.data };
  const first = result.error.issues[0];
  const where = first?.path?.length ? ` (at ${first.path.join('.')})` : '';
  return { ok: false, error: `${first?.message ?? 'Invalid cockpit sections payload'}${where}` };
}
