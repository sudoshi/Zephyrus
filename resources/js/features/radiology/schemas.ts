import { z } from 'zod';
import { sourceFreshnessSchema, slaDefinitionSchema } from '@/Components/Ancillary/schemas';

const nullableIso = z.string().datetime({ offset: true }).nullable();

export const radiologyFlowBoardSchema = z.object({
  generatedAt: z.string().datetime({ offset: true }),
  sourceCutoffAt: nullableIso,
  state: z.enum(['normal', 'stale', 'degraded', 'no_data', 'source_error']),
  stateMessage: z.string().min(1),
  freshness: sourceFreshnessSchema,
  filters: z.object({
    lens: z.enum(['all', 'ed', 'inpatient', 'discharge', 'degraded']),
    priority: z.enum(['stat', 'urgent', 'routine', 'discharge']).nullable(),
    modality: z.string().nullable(),
    unitId: z.number().int().positive().nullable(),
  }).strict(),
  filterOptions: z.object({
    lenses: z.array(z.enum(['all', 'ed', 'inpatient', 'discharge', 'degraded'])),
    priorities: z.array(z.string()),
    modalities: z.array(z.object({ code: z.string(), label: z.string() }).strict()),
    units: z.array(z.object({ unitId: z.number().int().positive(), label: z.string() }).strict()),
  }).strict(),
  summary: z.object({
    openOrders: z.number().int().nonnegative(),
    openBreaches: z.number().int().nonnegative(),
    dischargeBlocking: z.number().int().nonnegative(),
    degradedOrders: z.number().int().nonnegative(),
  }).strict(),
  thresholds: z.object({
    warningMinutes: z.number().int().nonnegative().nullable(),
    breachMinutes: z.number().int().nonnegative().nullable(),
    definitions: z.array(slaDefinitionSchema),
  }).strict(),
  heatmap: z.array(z.object({
    key: z.string(), rowLabel: z.string(), columnLabel: z.string(),
    count: z.number().int().nonnegative().nullable(),
    state: z.enum(['normal', 'warning', 'breach', 'no_data']),
  }).strict()),
  oldestItems: z.array(z.object({
    orderId: z.number().int().positive(), orderUuid: z.string().uuid(), label: z.string(),
    patientRef: z.string(), patientClass: z.string(), priority: z.string(), modality: z.string().nullable(),
    locationLabel: z.string().nullable(), currentState: z.string(), currentMilestoneCode: z.string().nullable(),
    ageMinutes: z.number().int().nonnegative(), status: z.enum(['normal', 'warning', 'breach', 'degraded']),
    barrierCount: z.number().int().nonnegative(), encounterLinked: z.boolean(), sourceCutoffAt: z.string().datetime({ offset: true }),
  }).strict()),
  worklistHref: z.string(),
  barrierPareto: z.array(z.object({ reasonCode: z.string(), label: z.string(), count: z.number().int().nonnegative() }).strict()),
  barrierReasons: z.array(z.object({ reasonCode: z.string(), category: z.string(), label: z.string() }).strict()),
  scanners: z.object({
    total: z.number().int().nonnegative(), operational: z.number().int().nonnegative(), downtime: z.number().int().nonnegative(),
    items: z.array(z.object({
      scannerUuid: z.string().uuid(), label: z.string(), modality: z.string(), capacity: z.number().int().positive(),
      state: z.string(), reasonCode: z.string().nullable(), downtimeEndsAt: nullableIso,
    }).strict()),
  }).strict(),
  canAnnotateBarriers: z.boolean(),
}).strict();

export type RadiologyFlowBoard = z.infer<typeof radiologyFlowBoardSchema>;
export type RadiologyOldestItem = RadiologyFlowBoard['oldestItems'][number];
export type RadiologyBarrierReason = RadiologyFlowBoard['barrierReasons'][number];

const timelineMilestoneSchema = z.object({
  code: z.string(), label: z.string(), state: z.enum(['done', 'current', 'pending_required', 'missing_optional', 'terminal', 'exception']),
  required: z.boolean(), occurredAt: nullableIso, selectedSource: z.string().nullable(), assertionCount: z.number().int().nonnegative(), conflict: z.boolean(),
}).strict();
const selectedClockSchema = z.object({
  metricKey: z.string(), label: z.string(), state: z.enum(['not_started', 'running', 'warning', 'breached', 'complete', 'unknown']),
  startMilestoneCode: z.string(), stopMilestoneCode: z.string(), startedAt: nullableIso, stoppedAt: nullableIso,
  elapsedMinutes: z.number().nonnegative().nullable(), warningMinutes: z.number().int().nonnegative().nullable(), breachMinutes: z.number().int().nonnegative().nullable(), definitionUuid: z.string().uuid(),
}).strict();

export const radiologyWorklistSchema = z.object({
  generatedAt: z.string().datetime({ offset: true }),
  freshness: sourceFreshnessSchema,
  filters: z.object({
    lens: z.enum(['all', 'ed', 'inpatient', 'discharge', 'degraded']), priority: z.string().nullable(), modality: z.string().nullable(), unitId: z.number().int().positive().nullable(),
    state: z.enum(['normal', 'warning', 'breach', 'degraded']).nullable(), sort: z.enum(['oldest', 'newest', 'priority', 'breach_risk']), search: z.string().nullable(),
    source: z.enum(['flow_board', 'ancillary_services', 'ed', 'rtdc', 'periop', 'cockpit']).nullable(), perPage: z.number().int().positive().max(50), cursor: z.string().nullable(),
  }).strict(),
  filterOptions: z.object({
    lenses: z.array(z.string()), priorities: z.array(z.string()), modalities: z.array(z.object({ code: z.string(), label: z.string() }).strict()),
    units: z.array(z.object({ unitId: z.number().int().positive(), label: z.string() }).strict()), sorts: z.array(z.string()), deepLinkSources: z.array(z.string()),
  }).strict(),
  predictiveSort: z.object({ available: z.boolean(), enabled: z.boolean(), explanation: z.string() }).strict(),
  data: z.array(z.object({
    orderId: z.number().int().positive(), orderUuid: z.string().uuid(), label: z.string(), patientRef: z.string(), patientClass: z.string(), priority: z.string(), modality: z.string().nullable(),
    locationLabel: z.string().nullable(), ageMinutes: z.number().int().nonnegative(), status: z.enum(['normal', 'warning', 'breach', 'degraded']), currentState: z.string(),
    downstreamImpact: z.object({ edDecision: z.boolean(), dischargeBlocking: z.boolean(), orCaseId: z.number().int().positive().nullable() }).strict(),
    barriers: z.array(z.object({ barrierId: z.number().int().positive(), reasonCode: z.string().nullable(), label: z.string(), owner: z.string().nullable(), openedAt: z.string().datetime({ offset: true }) }).strict()),
    sourceAssertions: z.array(z.object({ milestoneUuid: z.string().uuid(), code: z.string(), occurredAt: z.string().datetime({ offset: true }), receivedAt: z.string().datetime({ offset: true }), sourceKey: z.string(), sourceRank: z.number().int(), selected: z.boolean() }).strict()),
    transportSegment: z.array(timelineMilestoneSchema).nullable(),
    timeline: z.object({ orderUuid: z.string().uuid(), label: z.string(), milestones: z.array(timelineMilestoneSchema), clock: selectedClockSchema.nullable(), freshness: sourceFreshnessSchema, degradedMode: z.boolean(), degradedExplanation: z.string().nullable() }).strict(),
  }).strict()),
  meta: z.object({ perPage: z.number().int().positive(), count: z.number().int().nonnegative(), hasMore: z.boolean(), nextCursor: z.string().nullable(), previousCursor: z.string().nullable() }).strict(),
}).strict();

export type RadiologyWorklist = z.infer<typeof radiologyWorklistSchema>;
