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

export const arenaProcessModelSummarySchema = z.object({
  process_id: z.string(),
  process_number: z.number(),
  domain_code: z.string(),
  domain_name: z.string(),
  name: z.string(),
  core_interaction: z.string(),
  improvement_question: z.string(),
  evidence_grade: z.string(),
  priority: z.string(),
  interaction_pattern: z.string(),
  implementation_wave: z.string(),
  current_readiness: z.enum(['partial_projection', 'source_present_not_projected', 'reference_only']),
  readiness_note: z.string(),
});

export const arenaProcessLandscapeIndexSchema = z.object({
  available: z.literal(true),
  document: z.object({
    id: z.string(),
    version: z.string(),
    date: z.string(),
    catalog_count: z.number(),
    requested_count_note: z.string(),
    data_basis: z.literal('seeded_reference_models'),
    observed_claim: z.literal(false),
  }),
  counts: z.object({
    models: z.number(),
    domains: z.number(),
    priorities: z.record(z.string(), z.number()),
    readiness: z.record(z.string(), z.number()),
    waves: z.record(z.string(), z.number()),
  }),
  projection: z.object({
    projected_events: z.number(),
    projected_objects: z.number(),
    source_systems: z.number(),
    declared_object_types: z.number(),
    emitted_object_types: z.number(),
    target_object_types: z.number(),
    catalog_activities: z.number(),
  }),
  domains: z.array(z.object({ code: z.string(), name: z.string(), count: z.number() })),
  models: z.array(arenaProcessModelSummarySchema),
});

export const arenaProcessModelNodeSchema = z.object({
  node_key: z.string(),
  activity: z.string(),
  label: z.string(),
  node_kind: z.enum(['trigger', 'event', 'decision', 'exception', 'outcome']),
  ordinal: z.number(),
  object_types: z.array(z.string()),
  required: z.boolean(),
  source_basis: z.string(),
  observed_count: z.number(),
});

export const arenaProcessModelEdgeSchema = z.object({
  edge_key: z.string(),
  source_node_key: z.string(),
  target_node_key: z.string(),
  label: z.string(),
  relationship_type: z.string(),
  ordinal: z.number(),
  is_exception: z.boolean(),
});

export const arenaProcessModelDetailSchema = z.object({
  available: z.literal(true),
  data_basis: z.literal('seeded_reference_model'),
  observed_claim: z.literal(false),
  model: arenaProcessModelSummarySchema.extend({
    core_objects: z.array(z.string()),
    source_document: z.string(),
    catalog_version: z.number(),
  }),
  nodes: z.array(arenaProcessModelNodeSchema),
  edges: z.array(arenaProcessModelEdgeSchema),
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

// --- X4 governed AI copilot ---

export const arenaNarrativeFactSchema = z.object({
  claim: z.string(),
  source: z.string(),
  value: z.string(),
});

export const arenaNarrativeResponseSchema = z.union([
  z.object({
    available: z.literal(true),
    narrative: z.string(),
    provenance: z.array(arenaNarrativeFactSchema),
    ai_polished: z.boolean(),
    generated_label: z.string(),
  }),
  z.object({ available: z.literal(false), reason: z.string() }),
]);

export const arenaQuerySuggestionSchema = z.object({
  id: z.string(),
  label: z.string(),
  description: z.string(),
});

export const arenaQueryResponseSchema = z.union([
  z.object({
    available: z.literal(true),
    matched: z.boolean(),
    question: z.string().optional(),
    routed_by: z.string().optional(),
    query_id: z.string().optional(),
    label: z.string().optional(),
    columns: z.array(z.string()).optional(),
    rows: z.array(z.record(z.string(), z.unknown())).optional(),
    params: z.record(z.string(), z.unknown()).optional(),
    provenance: z.string().optional(),
    message: z.string().optional(),
    suggestions: z.array(arenaQuerySuggestionSchema).optional(),
  }),
  z.object({ available: z.literal(false), reason: z.string() }),
]);

export const arenaFitnessEdgeSchema = z.object({
  object_type: z.string(),
  source: z.string(),
  target: z.string(),
  frequency: z.number().optional(),
});

export const arenaAuthorMapResponseSchema = z.union([
  z.object({
    available: z.literal(true),
    published: z.boolean(),
    fitness: z.number(),
    precision: z.number(),
    fitness_floor: z.number(),
    reason: z.string().nullable().optional(),
    generated_label: z.string(),
    invented_edges: z.array(arenaFitnessEdgeSchema).optional(),
    missing_edges: z.array(arenaFitnessEdgeSchema).optional(),
  }),
  z.object({ available: z.literal(false), reason: z.string() }),
]);

export const arenaDraftActionSchema = z.object({
  action_uuid: z.string(),
  action_type: z.string(),
  tier: z.string().optional(),
  risk: z.string().optional(),
  title: z.string().optional(),
  status: z.string(),
  approved: z.boolean(),
  approval_uuid: z.string().optional(),
});

export const arenaDraftResponseSchema = z.object({
  available: z.boolean(),
  drafted: z.boolean().optional(),
  reason: z.string().optional(),
  action: arenaDraftActionSchema.optional(),
  pdsa: z.record(z.string(), z.string()).optional(),
});

export type ArenaHandoff = z.infer<typeof arenaHandoffSchema>;
export type ArenaSyncWait = z.infer<typeof arenaSyncWaitSchema>;
export type ArenaNarrativeResponse = z.infer<typeof arenaNarrativeResponseSchema>;
export type ArenaQueryResponse = z.infer<typeof arenaQueryResponseSchema>;
export type ArenaAuthorMapResponse = z.infer<typeof arenaAuthorMapResponseSchema>;
export type ArenaDraftResponse = z.infer<typeof arenaDraftResponseSchema>;
export type ArenaNode = z.infer<typeof arenaNodeSchema>;
export type ArenaEdge = z.infer<typeof arenaEdgeSchema>;
export type ArenaOcdfg = z.infer<typeof arenaOcdfgSchema>;
export type ArenaMapResponse = z.infer<typeof arenaMapResponseSchema>;
export type ArenaSummary = z.infer<typeof arenaSummarySchema>;
export type ArenaProcessModelSummary = z.infer<typeof arenaProcessModelSummarySchema>;
export type ArenaProcessLandscapeIndex = z.infer<typeof arenaProcessLandscapeIndexSchema>;
export type ArenaProcessModelNode = z.infer<typeof arenaProcessModelNodeSchema>;
export type ArenaProcessModelEdge = z.infer<typeof arenaProcessModelEdgeSchema>;
export type ArenaProcessModelDetail = z.infer<typeof arenaProcessModelDetailSchema>;
export type ArenaPathwayConformance = z.infer<typeof arenaPathwayConformanceSchema>;
export type ArenaConformanceResponse = z.infer<typeof arenaConformanceResponseSchema>;
