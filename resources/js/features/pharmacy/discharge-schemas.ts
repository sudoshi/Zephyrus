import { z } from 'zod';
import { sourceFreshnessSchema } from '@/Components/Ancillary/schemas';

const iso = z.string().datetime({ offset: true });
const nullableIso = iso.nullable();

const pipelineStatusSchema = z.enum([
  'not_started',
  'prior_auth_pending',
  'verification',
  'filling',
  'ready',
  'delivered',
  'unknown',
]);

const pipelineStageSchema = z.object({
  status: pipelineStatusSchema,
  label: z.string().min(1),
  blocking: z.boolean(),
  count: z.number().int().nonnegative(),
  oldestAgeMinutes: z.number().int().nonnegative().nullable(),
}).strict();

const itemSchema = z.object({
  queueUuid: z.string().uuid(),
  orderUuid: z.string().uuid().nullable(),
  encounterId: z.number().int().positive(),
  patientRef: z.string().min(1),
  medicationLabel: z.string().min(1),
  unitLabel: z.string().min(1),
  pipelineStatus: pipelineStatusSchema,
  pipelineLabel: z.string().min(1),
  blocking: z.boolean(),
  ageMinutes: z.number().int().nonnegative(),
  plannedDischargeAt: nullableIso,
  targetRelativeMinutes: z.number().int(),
  targetState: z.enum(['on_track', 'overdue', 'met', 'late', 'unknown']),
  priorAuthPending: z.boolean(),
  drillHref: z.string().nullable(),
  rtdcHref: z.string().nullable(),
}).strict();

export const pharmacyDischargeSchema = z.object({
  generatedAt: iso,
  sourceCutoffAt: nullableIso,
  freshnessStatus: z.enum(['fresh', 'stale', 'batch', 'unknown']),
  degradedMode: z.boolean(),
  state: z.enum(['normal', 'stale', 'degraded', 'no_data']),
  stateMessage: z.string().min(1),
  freshness: sourceFreshnessSchema,
  filters: z.object({
    pipeline: pipelineStatusSchema.nullable(),
    encounterId: z.number().int().positive().nullable(),
    source: z.enum(['flow_board', 'ancillary_services', 'ed', 'rtdc', 'periop', 'cockpit']).nullable(),
  }).strict(),
  filterOptions: z.object({
    pipeline: z.array(z.string()),
    sources: z.array(z.string()),
  }).strict(),
  cohortDefinition: z.string().min(1),
  data: z.object({
    summary: z.object({
      candidates: z.number().int().nonnegative(),
      queueRows: z.number().int().nonnegative(),
      blocking: z.number().int().nonnegative(),
      satisfied: z.number().int().nonnegative(),
      overdueAgainstTarget: z.number().int().nonnegative(),
      priorAuthPending: z.number().int().nonnegative(),
      readyByTargetPercent: z.number().min(0).max(100).nullable(),
    }).strict(),
    pipeline: z.array(pipelineStageSchema),
    items: z.array(itemSchema),
  }).strict(),
  privacy: z.object({
    directPatientIdentifiersIncluded: z.literal(false),
    doseInstructionsIncluded: z.literal(false),
    individualPerformanceIncluded: z.literal(false),
    identifierPolicy: z.string().min(1),
  }).strict(),
  canViewPatientDetail: z.boolean(),
}).strict();

export type PharmacyDischarge = z.infer<typeof pharmacyDischargeSchema>;
export type PharmacyDischargeItem = PharmacyDischarge['data']['items'][number];
export type PharmacyDischargeStage = PharmacyDischarge['data']['pipeline'][number];
