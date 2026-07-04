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
