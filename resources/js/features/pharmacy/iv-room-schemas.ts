import { z } from 'zod';
import { sourceFreshnessSchema } from '@/Components/Ancillary/schemas';

const iso = z.string().datetime({ offset: true });
const nullableIso = iso.nullable();

const prepTypeSchema = z.enum(['iv_batch', 'chemo', 'tpn', 'compound', 'repack', 'other']);
const prepStateSchema = z.enum(['pending', 'in_progress', 'complete', 'checked', 'cancelled']);
const budStateSchema = z.enum(['none', 'within_window', 'expiring', 'expired']);

const prepStageSchema = z.object({
  code: z.enum(['started', 'completed', 'checked']),
  label: z.string().min(1),
  at: nullableIso,
  state: z.enum(['pending', 'complete']),
}).strict();

// A prep row for the chemo timeline and the active-work queue: measured stages
// only, an elapsed value flagged as measured, and a policy-derived BUD state.
const prepRowSchema = z.object({
  prepUuid: z.string().uuid(),
  label: z.string().min(1),
  patientRef: z.string().min(1),
  patientClass: z.string().min(1),
  locationLabel: z.string().nullable(),
  prepType: prepTypeSchema,
  prepTypeLabel: z.string().min(1),
  batchRef: z.string().nullable(),
  prepState: prepStateSchema,
  prepStateLabel: z.string().min(1),
  elapsedMinutes: z.number().int().nonnegative().nullable(),
  elapsedIsMeasured: z.boolean(),
  budExpiresAt: nullableIso,
  budMinutesRemaining: z.number().int().nullable(),
  budState: budStateSchema,
  stages: z.array(prepStageSchema),
}).strict().superRefine((value, context) => {
  // An unmeasured elapsed value can never carry a number: no fabricated zero.
  if (!value.elapsedIsMeasured && value.elapsedMinutes !== null) {
    context.addIssue({ code: 'custom', path: ['elapsedMinutes'], message: 'An unmeasured preparation cannot report an elapsed duration.' });
  }
});

const batchSchema = z.object({
  key: z.string().min(1),
  batchRef: z.string().nullable(),
  batched: z.boolean(),
  prepType: prepTypeSchema,
  prepTypeLabel: z.string().min(1),
  prepCount: z.number().int().positive(),
  activeCount: z.number().int().nonnegative(),
  stateCounts: z.record(z.string(), z.number().int().nonnegative()),
  earliestStartedAt: nullableIso,
  latestCompletedAt: nullableIso,
  budExpiresAt: nullableIso,
  budMinutesRemaining: z.number().int().nullable(),
  budState: budStateSchema,
  budCrossesDayBoundary: z.boolean(),
}).strict();

// A degraded IVWMS-absent order: only a coarse verify-to-dispense interval, no
// prep stages, no batch, no BUD. Coarse minutes may be null but never zero-faked.
const degradedOrderSchema = z.object({
  orderUuid: z.string().uuid(),
  label: z.string().min(1),
  patientRef: z.string().min(1),
  patientClass: z.string().min(1),
  locationLabel: z.string().nullable(),
  orderStatus: z.string().min(1),
  verifiedAt: nullableIso,
  dispensedAt: nullableIso,
  coarseVerifyToDispenseMinutes: z.number().int().nonnegative().nullable(),
  clockResolution: z.literal('coarse'),
}).strict();

export const pharmacyIvRoomSchema = z.object({
  generatedAt: iso,
  sourceCutoffAt: nullableIso,
  freshnessStatus: z.enum(['fresh', 'stale', 'batch', 'unknown']),
  degradedMode: z.boolean(),
  state: z.enum(['normal', 'stale', 'degraded', 'no_data']),
  stateMessage: z.string().min(1),
  freshness: sourceFreshnessSchema,
  filters: z.object({ prepType: prepTypeSchema.nullable() }).strict(),
  filterOptions: z.object({ prepType: z.array(z.string()) }).strict(),
  appliedSlaDefinitions: z.array(z.unknown()),
  policy: z.object({
    kind: z.literal('configuration'),
    tpnCutoff: z.object({
      label: z.string().min(1),
      localHour: z.number().int().min(0).max(23),
      timezone: z.string().min(1),
      nextCutoffAt: iso,
      description: z.string().min(1),
    }).strict(),
    budWarningWindow: z.object({
      label: z.string().min(1),
      minutes: z.number().int().positive(),
      description: z.string().min(1),
    }).strict(),
  }).strict(),
  data: z.object({
    summary: z.object({
      totalPreps: z.number().int().nonnegative(),
      activePreps: z.number().int().nonnegative(),
      batches: z.number().int().nonnegative(),
      chemoPreps: z.number().int().nonnegative(),
      tpnPreps: z.number().int().nonnegative(),
      budExpiringSoon: z.number().int().nonnegative(),
      budExpired: z.number().int().nonnegative(),
      degradedOrders: z.number().int().nonnegative(),
    }).strict(),
    batches: z.array(batchSchema),
    chemoTimeline: z.array(prepRowSchema),
    activeWork: z.array(prepRowSchema),
    waste: z.object({
      wasteEvents: z.number().int().nonnegative(),
      wasteQuantity: z.number().nonnegative(),
      denominatorLabel: z.string().min(1),
      denominatorCount: z.number().int().nonnegative(),
      wastePerHundredVends: z.number().nonnegative().nullable(),
      windowHours: z.number().int().positive(),
      windowStartAt: iso,
      windowEndAt: iso,
      basis: z.string().min(1),
    }).strict(),
    degradedOrders: z.object({
      coverage: z.enum(['available', 'partial']),
      orders: z.array(degradedOrderSchema),
      coverageStatement: z.string().min(1),
    }).strict(),
  }).strict(),
  privacy: z.object({
    directPatientIdentifiersIncluded: z.literal(false),
    doseInstructionsIncluded: z.literal(false),
    compoundingRecipeIncluded: z.literal(false),
    individualPerformanceIncluded: z.literal(false),
    identifierPolicy: z.string().min(1),
  }).strict(),
  canViewPatientDetail: z.boolean(),
}).strict();

export type PharmacyIvRoom = z.infer<typeof pharmacyIvRoomSchema>;
export type PharmacyIvRoomBatch = PharmacyIvRoom['data']['batches'][number];
export type PharmacyIvRoomPrep = PharmacyIvRoom['data']['activeWork'][number];
export type PharmacyIvRoomDegradedOrder = PharmacyIvRoom['data']['degradedOrders']['orders'][number];
