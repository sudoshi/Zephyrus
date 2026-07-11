// resources/js/features/arena/reviewSchema.ts
//
// Zephyrus 2.0 Part X — Zod contract for the 48-Hour Flow Review artifact.
// The Review reads ONE persisted artifact (built by FlowReviewService::run)
// instead of four live sidecar calls: a ranked barrier list under one unified
// taxonomy (flow / care / human), the window's discovered OCDFG, and the
// performance index that colours its edges. Parsed at the boundary like every
// other Arena response (schema.ts), degrading to an in-place card, never a
// white screen.
import { z } from 'zod';
import {
  arenaDeviationSchema,
  arenaDraftActionSchema,
  arenaHandoffSchema,
  arenaOcdfgSchema,
  arenaSampleCaseSchema,
} from './schema';

// A single barrier in the unified taxonomy. `kind` discriminates the source
// (flow = sync-wait bottleneck, care = conformance deviation, human = an
// operator-flagged prod.barriers row); `severity` alone carries status colour.
export const rankedBarrierSchema = z.object({
  id: z.string(),
  kind: z.enum(['flow', 'care', 'human']),
  severity: z.enum(['critical', 'warning', 'watch']),
  title: z.string(),
  subtitle: z.string(),
  location: z.object({
    unit_id: z.number().nullable(),
    unit_label: z.string().nullable(),
  }),
  // Redacted server-side per the flow lens; null when the viewer's lens has no
  // patient identity for this barrier.
  encounter_ref: z.string().nullable(),
  // ISO time the barrier opened / was first observed in the window; drives the
  // chronobar marker. Null for purely aggregate barriers.
  opened_at: z.string().nullable().optional(),
  metric: z.object({
    value_label: z.string(),
    value_sec: z.number().nullable(),
    delta_pct: z.number().nullable(),
    direction: z.enum(['up', 'down', 'flat']),
  }),
  provenance: z.object({ source: z.string(), note: z.string() }),
  // The activities/transitions this barrier lives on, so selecting it can focus
  // the discovered map. Edge ids follow the ocdfgLayout convention `${src} ${tgt}`.
  map_focus: z.object({
    node_ids: z.array(z.string()),
    edge_ids: z.array(z.string()),
  }),
  deviations: z.array(arenaDeviationSchema).optional(),
  sample_cases: z.array(arenaSampleCaseSchema).optional(),
  corrective_action: z
    .object({
      draft: arenaDraftActionSchema.optional(),
      prior_outcome: z
        .object({ label: z.string(), moved_sec: z.number() })
        .nullable(),
    })
    .optional(),
});

export const arenaReviewResponseSchema = z.union([
  z.object({
    available: z.literal(true),
    cached: z.boolean().optional(),
    stale: z.boolean().optional(),
    window: z.object({ from: z.string(), to: z.string(), label: z.string() }),
    prior_window_label: z.string().nullable(),
    generated_at: z.string(),
    stats: z.object({
      open_barriers: z.number(),
      new_barriers: z.number(),
      actions_pending: z.number(),
      worst_handoff: z.object({
        label: z.string(),
        value_label: z.string(),
        delta_pct: z.number().nullable(),
      }),
      worst_pathway: z.object({
        label: z.string(),
        rate: z.number().nullable(),
        delta_pt: z.number().nullable(),
      }),
    }),
    barriers: z.array(rankedBarrierSchema),
    map: arenaOcdfgSchema,
    performance_index: z.array(arenaHandoffSchema),
  }),
  z.object({ available: z.literal(false), reason: z.string() }),
]);

export type RankedBarrier = z.infer<typeof rankedBarrierSchema>;
export type BarrierKind = RankedBarrier['kind'];
export type BarrierSeverity = RankedBarrier['severity'];
export type ArenaReviewResponse = z.infer<typeof arenaReviewResponseSchema>;
