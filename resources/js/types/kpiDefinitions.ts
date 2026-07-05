// resources/js/types/kpiDefinitions.ts
//
// P8 WS-6b — the admin THRESHOLD EDITOR contract. GET /api/cockpit/kpi-definitions
// (admin-gated) returns the tunable KPI band edges the CMIO can retune without a
// deploy; PUT .../{metricKey} (admin-gated, audited) persists a change. The
// payload is parsed defensively (safeParseKpiDefinitions) — the editor renders
// only from a clean parse and shows a canon error card otherwise. Mirrors
// safeParseCockpitFace in '@/types/cockpit': first issue message + path on fail.
//
// Tolerant by design: server may add fields (watch_band_pct, future edge knobs),
// so the definition object is .passthrough() and the maybe-absent scalars are
// .nullable().optional(). We never white-screen on an extra key.
import { z } from 'zod';

// The status band edges for one KPI. `direction` decides which side of an edge
// is worse ('up' = higher is worse, 'down' = lower is worse). ok/warn/crit are
// the numeric band boundaries; any may be null when that band is unset.
export const kpiEdgesSchema = z
  .object({
    direction: z.string(),
    ok: z.number().nullable().optional(),
    warn: z.number().nullable().optional(),
    crit: z.number().nullable().optional(),
    watch_band_pct: z.number().nullable().optional(),
  })
  .passthrough();
export type KpiEdges = z.infer<typeof kpiEdgesSchema>;

export const kpiDefinitionSchema = z
  .object({
    key: z.string(),
    label: z.string(),
    domain: z.string().nullable(),
    unit: z.string().nullable(),
    direction: z.string().nullable(),
    target: z.number().nullable(),
    edges: kpiEdgesSchema,
    refreshSecs: z.number().nullable(),
    alertTemplate: z.string().nullable(),
    facilityKey: z.string().nullable(),
    isActive: z.boolean(),
  })
  .passthrough();
export type KpiDefinition = z.infer<typeof kpiDefinitionSchema>;

export const kpiDefinitionsResponseSchema = z.object({
  definitions: z.array(kpiDefinitionSchema),
});

export type SafeKpiDefinitions =
  | { ok: true; data: { definitions: KpiDefinition[] } }
  | { ok: false; error: string };

export function safeParseKpiDefinitions(input: unknown): SafeKpiDefinitions {
  const result = kpiDefinitionsResponseSchema.safeParse(input);
  if (result.success) return { ok: true, data: result.data };
  const first = result.error.issues[0];
  const where = first?.path?.length ? ` (at ${first.path.join('.')})` : '';
  return { ok: false, error: `${first?.message ?? 'Invalid KPI definitions payload'}${where}` };
}
