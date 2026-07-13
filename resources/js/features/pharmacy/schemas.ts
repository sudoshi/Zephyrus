import { z } from 'zod';
import { sourceFreshnessSchema, slaDefinitionSchema } from '@/Components/Ancillary/schemas';

const iso = z.string().datetime({ offset: true });
const nullableIso = iso.nullable();

const clockClassSchema = z.enum(['stat', 'first_dose', 'sepsis', 'routine', 'timed', 'discharge']);
const branchSchema = z.enum(['adc', 'iv_room', 'central', 'unknown']);

const segmentClockSchema = z.object({
  count: z.number().int().nonnegative(),
  medianMinutes: z.number().nonnegative().nullable(),
  p90Minutes: z.number().nonnegative().nullable(),
  definition: z.string().min(1),
  freshness: sourceFreshnessSchema,
}).strict();

const orderToDispenseSchema = segmentClockSchema.extend({ basis: z.literal('real_time') }).strict();

// The warehouse tail is structurally as-of: it can never parse as a real-time claim.
const dispenseToAdminSchema = segmentClockSchema.extend({
  basis: z.literal('as_of_cutoff'),
  sourceCutoffAt: nullableIso,
}).strict().superRefine((value, context) => {
  if (value.freshness.status === 'fresh') {
    context.addIssue({ code: 'custom', path: ['freshness', 'status'], message: 'The dispense-to-administration tail can never render as real-time.' });
  }
});

const clockClassRowSchema = z.object({
  clockClass: z.enum(['stat', 'first_dose', 'sepsis']),
  metricKey: z.string().min(1),
  label: z.string().min(1),
  definition: slaDefinitionSchema,
  openOrders: z.number().int().nonnegative(),
  openBreaches: z.number().int().nonnegative(),
  openWarnings: z.number().int().nonnegative().nullable(),
  clearedBreaches: z.number().int().nonnegative(),
  oldestOpenBreachAgeMinutes: z.number().int().nonnegative().nullable(),
  adminTail: z.boolean(),
  state: z.enum(['normal', 'warning', 'breach', 'unknown']),
  explanation: z.string().min(1),
}).strict();

const sepsisStageSchema = z.object({
  code: z.string().min(1),
  label: z.string().min(1),
  at: nullableIso,
  state: z.enum(['complete', 'pending']),
}).strict();

const adminSegmentSchema = z.object({
  state: z.enum(['administered_as_of', 'no_evidence_as_of_cutoff', 'unknown']),
  administeredAt: nullableIso,
  sourceCutoffAt: nullableIso,
  elapsedMinutes: z.number().int().nonnegative().nullable(),
  explanation: z.string().min(1),
}).strict().superRefine((value, context) => {
  if (value.state === 'administered_as_of' && value.administeredAt === null) {
    context.addIssue({ code: 'custom', path: ['administeredAt'], message: 'An administered segment requires the observed administration time.' });
  }
  if (value.state !== 'administered_as_of' && value.administeredAt !== null) {
    context.addIssue({ code: 'custom', path: ['administeredAt'], message: 'A non-administered segment cannot carry an administration time.' });
  }
});

const sepsisTimerSchema = z.object({
  orderUuid: z.string().uuid(),
  label: z.string().min(1),
  patientRef: z.string().min(1),
  patientClass: z.string().min(1),
  locationLabel: z.string().nullable(),
  orderedAt: iso,
  elapsedMinutes: z.number().int().nonnegative(),
  metricKey: z.string().min(1),
  state: z.enum(['complete', 'breached', 'warning', 'running', 'unknown']),
  stateExplanation: z.string().min(1),
  segments: z.array(sepsisStageSchema),
  adminSegment: adminSegmentSchema,
}).strict();

const oldestItemSchema = z.object({
  orderUuid: z.string().uuid(),
  label: z.string().min(1),
  patientRef: z.string().min(1),
  patientClass: z.string().min(1),
  clockClass: clockClassSchema,
  preparationBranch: branchSchema,
  orderStatus: z.string().min(1),
  locationLabel: z.string().nullable(),
  currentStage: z.string().nullable(),
  ageMinutes: z.number().int().nonnegative(),
  onShortage: z.boolean(),
  isControlled: z.boolean(),
  encounterLinked: z.boolean(),
  slaState: z.enum(['normal', 'warning', 'breach', 'unknown']),
  slaExplanation: z.string().min(1),
  barrierCount: z.number().int().nonnegative(),
}).strict();

export const pharmacyFlowBoardSchema = z.object({
  generatedAt: iso,
  sourceCutoffAt: nullableIso,
  freshnessStatus: z.enum(['fresh', 'stale', 'batch', 'unknown']),
  degradedMode: z.boolean(),
  state: z.enum(['normal', 'stale', 'degraded', 'no_data', 'source_error']),
  stateMessage: z.string().min(1),
  freshness: sourceFreshnessSchema,
  administrationFreshness: sourceFreshnessSchema,
  filters: z.object({
    lens: z.enum(['all', 'stat', 'first_dose', 'sepsis', 'shortage', 'discharge', 'degraded']),
    clockClass: clockClassSchema.nullable(),
    branch: branchSchema.nullable(),
    status: z.string().nullable(),
    unitId: z.number().int().positive().nullable(),
    source: z.enum(['flow_board', 'ancillary_services', 'ed', 'rtdc', 'periop', 'cockpit']).nullable(),
  }).strict(),
  filterOptions: z.object({
    lenses: z.array(z.string()),
    clockClasses: z.array(z.string()),
    branches: z.array(z.string()),
    statuses: z.array(z.string()),
    units: z.array(z.object({ unitId: z.number().int().positive(), label: z.string() }).strict()),
  }).strict(),
  appliedSlaDefinitions: z.array(slaDefinitionSchema),
  data: z.object({
    summary: z.object({
      currentOrders: z.number().int().nonnegative(),
      openOrders: z.number().int().nonnegative(),
      statOrders: z.number().int().nonnegative(),
      statCompliant: z.number().int().nonnegative(),
      statCompliancePercent: z.number().min(0).max(100).nullable(),
      verificationQueueDepth: z.number().int().nonnegative(),
      openBreaches: z.number().int().nonnegative(),
      shortageOrders: z.number().int().nonnegative(),
      dischargeOrders: z.number().int().nonnegative(),
      controlledOrders: z.number().int().nonnegative(),
      degradedOrders: z.number().int().nonnegative(),
    }).strict(),
    verificationQueue: z.object({
      depth: z.number().int().nonnegative(),
      oldestAgeMinutes: z.number().int().nonnegative().nullable(),
      medianAgeMinutes: z.number().nonnegative().nullable(),
      ageDistribution: z.array(z.object({ key: z.string(), label: z.string(), count: z.number().int().nonnegative() }).strict()),
    }).strict(),
    clockClasses: z.array(clockClassRowSchema),
    segments: z.object({ orderToDispense: orderToDispenseSchema, dispenseToAdmin: dispenseToAdminSchema }).strict(),
    preparationBranches: z.object({
      branches: z.array(z.object({
        branch: branchSchema,
        label: z.string().min(1),
        orders: z.number().int().nonnegative(),
        openOrders: z.number().int().nonnegative(),
        degradedOrders: z.number().int().nonnegative(),
      }).strict()),
      ivwms: z.object({
        status: z.enum(['available', 'partial']),
        degradedOrders: z.number().int().nonnegative(),
        explanation: z.string().min(1),
      }).strict(),
    }).strict(),
    sepsisTimers: z.array(sepsisTimerSchema),
    oldestItems: z.array(oldestItemSchema),
    barrierPareto: z.array(z.object({ reasonCode: z.string(), label: z.string(), count: z.number().int().nonnegative() }).strict()),
  }).strict(),
  barrierReasons: z.array(z.object({ reasonCode: z.string(), category: z.string(), label: z.string() }).strict()),
  privacy: z.object({
    directPatientIdentifiersIncluded: z.literal(false),
    doseInstructionsIncluded: z.literal(false),
    individualPerformanceIncluded: z.literal(false),
    identifierPolicy: z.string().min(1),
  }).strict(),
  canAnnotateBarriers: z.boolean(),
  canViewPatientDetail: z.boolean(),
}).strict();

export type PharmacyFlowBoard = z.infer<typeof pharmacyFlowBoardSchema>;
export type PharmacyClockClassRow = PharmacyFlowBoard['data']['clockClasses'][number];
export type PharmacySepsisTimer = PharmacyFlowBoard['data']['sepsisTimers'][number];
export type PharmacyOldestItem = PharmacyFlowBoard['data']['oldestItems'][number];
export type PharmacyBarrierReason = PharmacyFlowBoard['barrierReasons'][number];
