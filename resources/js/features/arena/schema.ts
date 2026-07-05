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

export type ArenaNode = z.infer<typeof arenaNodeSchema>;
export type ArenaEdge = z.infer<typeof arenaEdgeSchema>;
export type ArenaOcdfg = z.infer<typeof arenaOcdfgSchema>;
export type ArenaMapResponse = z.infer<typeof arenaMapResponseSchema>;
export type ArenaSummary = z.infer<typeof arenaSummarySchema>;
