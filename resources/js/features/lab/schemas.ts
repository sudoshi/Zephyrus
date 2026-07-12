import { z } from 'zod';
import { sourceFreshnessSchema, slaDefinitionSchema } from '@/Components/Ancillary/schemas';

const intervalSchema = z.object({
  count: z.number().int().nonnegative(),
  medianMinutes: z.number().nonnegative().nullable(),
  p90Minutes: z.number().nonnegative().nullable(),
  granularity: z.enum(['segmented', 'coarse']),
  definition: z.string(),
}).strict();

const decisionContextSchema = z.object({
  decision_class: z.string(),
  blocked_object_type: z.string(),
  blocked_object_id: z.number().int().positive(),
  explanation: z.string().min(1),
}).strict().nullable();

export const labFlowBoardSchema = z.object({
  generatedAt: z.string().datetime({ offset: true }),
  sourceCutoffAt: z.string().datetime({ offset: true }).nullable(),
  state: z.enum(['normal', 'stale', 'degraded', 'no_data', 'source_error']),
  stateMessage: z.string().min(1),
  freshness: sourceFreshnessSchema,
  filters: z.object({
    lens: z.enum(['all', 'ed', 'inpatient', 'discharge_gate', 'or_gate', 'degraded']),
    priority: z.enum(['stat', 'urgent', 'routine', 'timed', 'discharge']).nullable(),
    testFamily: z.string().nullable(), unitId: z.number().int().positive().nullable(), shift: z.enum(['am_draw', 'day', 'evening', 'night']).nullable(),
  }).strict(),
  filterOptions: z.object({
    lenses: z.array(z.string()), priorities: z.array(z.string()), testFamilies: z.array(z.string()),
    units: z.array(z.object({ unitId: z.number().int().positive(), label: z.string() }).strict()), shifts: z.array(z.string()),
  }).strict(),
  summary: z.object({
    currentOrders: z.number().int().nonnegative(), openOrders: z.number().int().nonnegative(),
    statOrders: z.number().int().nonnegative(), statCompliant: z.number().int().nonnegative(),
    statCompliancePercent: z.number().min(0).max(100).nullable(), pendingDecisions: z.number().int().nonnegative(),
    openCriticalCallbacks: z.number().int().nonnegative(), degradedOrders: z.number().int().nonnegative(),
  }).strict(),
  coverage: z.object({
    transport: z.object({ status: z.enum(['available', 'missing']), granularity: z.enum(['segmented', 'coarse']), explanation: z.string() }).strict(),
    middleware: z.object({ status: z.enum(['available', 'missing']), granularity: z.enum(['segmented', 'coarse']), explanation: z.string() }).strict(),
  }).strict(),
  stageDistribution: z.array(z.object({ stage: z.string(), label: z.string(), count: z.number().int().nonnegative() }).strict()),
  tat: z.object({ collectToReceive: intervalSchema, receiveToResult: intervalSchema }).strict(),
  criticalCallbacks: z.object({
    total: z.number().int().nonnegative(), open: z.number().int().nonnegative(), oldestOpenAgeMinutes: z.number().int().nonnegative().nullable(),
    byState: z.array(z.object({ state: z.string(), count: z.number().int().nonnegative() }).strict()),
  }).strict(),
  qualityStrip: z.array(z.object({
    key: z.string(), label: z.string(), count: z.number().int().nonnegative(), denominator: z.number().int().nonnegative(), ratePercent: z.number().min(0).max(100).nullable(),
    reference: z.object({ kind: z.enum(['benchmark', 'local_policy']), label: z.string(), valuePercent: z.number().min(0).max(100).nullable(), source: z.string() }).strict(),
  }).strict()),
  oldestItems: z.array(z.object({
    orderUuid: z.string().uuid(), label: z.string(), patientRef: z.string(), patientClass: z.string(), priority: z.string(), testFamily: z.string().nullable(),
    locationLabel: z.string().nullable(), currentStage: z.string().nullable(), ageMinutes: z.number().int().nonnegative(), encounterLinked: z.boolean(),
    decisionContext: decisionContextSchema, barrierCount: z.number().int().nonnegative(),
  }).strict()),
  barrierPareto: z.array(z.object({ reasonCode: z.string(), label: z.string(), count: z.number().int().nonnegative() }).strict()),
  barrierReasons: z.array(z.object({ reasonCode: z.string(), category: z.string(), label: z.string() }).strict()),
  definitions: z.array(slaDefinitionSchema), canAnnotateBarriers: z.boolean(), canViewPatientDetail: z.boolean(),
}).strict();

export type LabFlowBoard = z.infer<typeof labFlowBoardSchema>;
export type LabOldestItem = LabFlowBoard['oldestItems'][number];
export type LabBarrierReason = LabFlowBoard['barrierReasons'][number];
