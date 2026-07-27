import { z } from 'zod';

/**
 * Tolerant Zod contracts for the Phase C adherence reads (plan §7.2/§8 C).
 * Same discipline as journeySchemas: nullish-generous so a server field
 * addition never bricks the wall; safeParse at the fetch boundary.
 */

export const caseVerdictSchema = z.object({
  pathway: z.string(),
  pathway_version: z.number().int().nullish(),
  conformant: z.boolean(),
  deviations: z.array(z.string()).nullish(),
  activity_timeline: z.record(z.string(), z.string().nullish()).nullish(),
  computed_at: z.string().nullish(),
});

export const caseConformanceSchema = z.object({
  available: z.boolean(),
  verdicts: z.array(caseVerdictSchema).nullish(),
  computed_at: z.string().nullish(),
});

export type CaseVerdict = z.infer<typeof caseVerdictSchema>;
export type CaseConformance = z.infer<typeof caseConformanceSchema>;

export const scenePathwayFlagSchema = z.object({
  pathway: z.string(),
  pathway_version: z.number().int().nullish(),
  deviations: z.array(z.string()).nullish(),
});

export const sceneConformanceSchema = z.object({
  available: z.boolean(),
  patients: z
    .array(z.object({ ref: z.string(), pathways: z.array(scenePathwayFlagSchema) }))
    .nullish(),
  as_of: z.string().nullish(),
  cadence_minutes: z.number().int().nullish(),
});

export type ScenePathwayFlag = z.infer<typeof scenePathwayFlagSchema>;
export type SceneConformance = z.infer<typeof sceneConformanceSchema>;

export const exceptionNoteResultSchema = z.object({
  action_uuid: z.string().nullish(),
  status: z.string().nullish(),
  approved: z.boolean().nullish(),
});

export type ExceptionNoteResult = z.infer<typeof exceptionNoteResultSchema>;
