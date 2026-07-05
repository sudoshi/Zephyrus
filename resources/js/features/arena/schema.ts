// resources/js/features/arena/schema.ts
//
// Zephyrus 2.0 Part X (X1). Zod contract for the Arena serving API. The page
// keeps data `unknown` end to end and parses here at the boundary, degrading to
// an in-place error state rather than white-screening the Study — the same
// discipline the cockpit uses for its snapshot.
import { z } from 'zod';

export const arenaNodeSchema = z.object({
  id: z.string(),
  activity: z.string(),
  frequency: z.number(),
  object_types: z.array(z.string()),
});

export const arenaEdgeSchema = z.object({
  source: z.string(),
  target: z.string(),
  object_type: z.string(),
  frequency: z.number(),
});

export const arenaOcdfgSchema = z.object({
  object_types: z.array(z.string()),
  nodes: z.array(arenaNodeSchema),
  edges: z.array(arenaEdgeSchema),
  stats: z.record(z.string(), z.number()).optional(),
});

export const arenaMapResponseSchema = z.union([
  z.object({
    available: z.literal(true),
    cached: z.boolean().optional(),
    stale: z.boolean().optional(),
    scope: z.string(),
    source_signature: z.string().optional(),
    mined_at: z.string().optional(),
    map: arenaOcdfgSchema,
  }),
  z.object({
    available: z.literal(false),
    reason: z.string(),
    scope: z.string().optional(),
  }),
]);

export const arenaSummarySchema = z.object({
  events: z.number(),
  objects: z.number(),
  object_types: z.record(z.string(), z.number()),
  activities: z.record(z.string(), z.number()),
});

export const arenaDeviationSchema = z.object({
  code: z.string(),
  label: z.string(),
  count: z.number(),
});

export const arenaSampleCaseSchema = z.object({
  case_id: z.string(),
  deviations: z.array(z.string()),
});

export const arenaPathwayConformanceSchema = z.object({
  pathway: z.string(),
  label: z.string(),
  version: z.number(),
  owner: z.string(),
  case_type: z.string(),
  cases: z.number(),
  conformant: z.number(),
  deviant: z.number(),
  conformance_rate: z.number().nullable(),
  deviations: z.array(arenaDeviationSchema),
  sample_deviant_cases: z.array(arenaSampleCaseSchema),
});

export const arenaConformanceResponseSchema = z.union([
  z.object({ available: z.literal(true), pathways: z.array(arenaPathwayConformanceSchema) }),
  z.object({ available: z.literal(false), reason: z.string() }),
]);

export const arenaHandoffSchema = z.object({
  object_type: z.string(),
  source: z.string(),
  target: z.string(),
  count: z.number(),
  median_sec: z.number(),
  p90_sec: z.number(),
  mean_sec: z.number(),
});

export const arenaSyncWaitSchema = z.object({
  activity: z.string(),
  object_type: z.string(),
  count: z.number(),
  median_wait_sec: z.number(),
  p90_wait_sec: z.number(),
});

export const arenaPerformanceResponseSchema = z.union([
  z.object({
    available: z.literal(true),
    handoffs: z.array(arenaHandoffSchema),
    synchronization: z.array(arenaSyncWaitSchema),
  }),
  z.object({ available: z.literal(false), reason: z.string() }),
]);

export type ArenaHandoff = z.infer<typeof arenaHandoffSchema>;
export type ArenaSyncWait = z.infer<typeof arenaSyncWaitSchema>;
export type ArenaNode = z.infer<typeof arenaNodeSchema>;
export type ArenaEdge = z.infer<typeof arenaEdgeSchema>;
export type ArenaOcdfg = z.infer<typeof arenaOcdfgSchema>;
export type ArenaMapResponse = z.infer<typeof arenaMapResponseSchema>;
export type ArenaSummary = z.infer<typeof arenaSummarySchema>;
export type ArenaPathwayConformance = z.infer<typeof arenaPathwayConformanceSchema>;
export type ArenaConformanceResponse = z.infer<typeof arenaConformanceResponseSchema>;
