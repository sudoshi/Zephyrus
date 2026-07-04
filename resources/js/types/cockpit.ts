// resources/js/types/cockpit.ts
//
// Zephyrus 2.0 cockpit contract additions. These EXTEND types/commandCenter.ts
// (the frozen Command Center Zod contract) — they never replace it. See
// docs/ZEPHYRUS-2.0-PLAN.md Part IV (status vocabulary) and Part V §3 (Cell
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
});
export const cellSchema = z.union([
  z.string(),
  z.number(),
  cellBarSchema,
  cellChipSchema,
  cellTagSchema,
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
  key: z.string(),
  status: z.enum(['warn', 'crit']),
  text: z.string(),
  provenance: z.literal('demo').optional(),
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
});
export type CockpitSnapshotSections = z.infer<typeof cockpitSnapshotSectionsSchema>;

// The drillable domain registry (server: DrillBuilder::DOMAINS). ?drill= values
// outside this list are ignored on read, so a mangled deep link degrades to the
// bare cockpit instead of holding junk state.
export const cockpitDrillDomains = [
  'rtdc', 'ed', 'periop', 'staffing', 'flow', 'quality', 'service', 'financial', 'okr',
] as const;
export type CockpitDrillDomain = (typeof cockpitDrillDomains)[number];

export function isCockpitDrillDomain(value: string | null): value is CockpitDrillDomain {
  return value !== null && (cockpitDrillDomains as readonly string[]).includes(value);
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
