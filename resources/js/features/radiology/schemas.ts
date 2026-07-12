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

const modalityPatientMixSchema = z.object({
  ed: z.number().int().nonnegative(),
  inpatient: z.number().int().nonnegative(),
  outpatient: z.number().int().nonnegative(),
  other: z.number().int().nonnegative(),
  total: z.number().int().nonnegative(),
}).strict();

const nullableMinutes = z.number().nonnegative().nullable();

export const modalityUtilizationSchema = z.object({
  generatedAt: z.string().datetime({ offset: true }),
  sourceCutoffAt: nullableIso,
  state: z.enum(['normal', 'degraded', 'no_data']),
  stateMessage: z.string().min(1),
  filters: z.object({
    date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
    startTime: z.string().regex(/^\d{2}:\d{2}$/),
    endTime: z.string().regex(/^\d{2}:\d{2}$/),
    modality: z.string().nullable(),
  }).strict(),
  filterOptions: z.object({
    modalities: z.array(z.object({ code: z.string(), label: z.string() }).strict()),
  }).strict(),
  coverage: z.object({
    status: z.enum(['complete', 'partial', 'missing', 'no_data']),
    mppsFeedPresent: z.boolean(),
    scannerCount: z.number().int().nonnegative(),
    coveredScannerCount: z.number().int().nonnegative(),
    candidateExamCount: z.number().int().nonnegative(),
    coveredExamCount: z.number().int().nonnegative(),
    percent: z.number().min(0).max(100),
    warning: z.string().nullable(),
  }).strict(),
  summary: z.object({
    scannerCount: z.number().int().nonnegative(),
    availableMinutes: z.number().nonnegative(),
    examMinutes: nullableMinutes,
    plannedDowntimeMinutes: z.number().nonnegative(),
    unplannedDowntimeMinutes: z.number().nonnegative(),
    idleMinutes: nullableMinutes,
    utilizationPercent: z.number().min(0).max(100).nullable(),
    dataCoveragePercent: z.number().min(0).max(100),
    patientMix: modalityPatientMixSchema,
    reconciliationDeltaMinutes: z.number().nullable(),
  }).strict(),
  definitions: z.object({
    available: z.string(), exam: z.string(), downtime: z.string(), idle: z.string(), utilization: z.string(), referenceLine: z.string(),
  }).strict(),
  referenceLines: z.array(z.object({
    key: z.string(), label: z.string(), value: z.number().min(0).max(100), definition: z.string(),
  }).strict()),
  scanners: z.array(z.object({
    scannerUuid: z.string().uuid(), label: z.string(), modality: z.string(), capacity: z.number().int().positive(), timezone: z.string(),
    availableWindows: z.array(z.object({ startAt: z.string().datetime({ offset: true }), endAt: z.string().datetime({ offset: true }) }).strict()),
    availableMinutes: z.number().nonnegative(), examMinutes: nullableMinutes,
    plannedDowntimeMinutes: z.number().nonnegative(), unplannedDowntimeMinutes: z.number().nonnegative(), idleMinutes: nullableMinutes,
    utilizationPercent: z.number().min(0).max(100).nullable(), reconciliationDeltaMinutes: z.number().nullable(),
    coverage: z.object({
      status: z.enum(['complete', 'partial', 'missing_feed', 'missing_schedule']), percent: z.number().min(0).max(100),
      candidateExamCount: z.number().int().nonnegative(), coveredExamCount: z.number().int().nonnegative(), warning: z.string().nullable(),
    }).strict(),
    patientMix: modalityPatientMixSchema,
    segments: z.array(z.object({
      startAt: z.string().datetime({ offset: true }), endAt: z.string().datetime({ offset: true }),
      type: z.enum(['exam', 'planned_downtime', 'unplanned_downtime', 'idle', 'unknown']), minutes: z.number().positive(), label: z.string(),
    }).strict()),
  }).strict()),
}).strict();

export type ModalityUtilization = z.infer<typeof modalityUtilizationSchema>;

const readPrioritySummarySchema = z.object({
  priority: z.string(), count: z.number().int().nonnegative(), oldestAgeMinutes: z.number().int().nonnegative(),
}).strict();

const readDistributionSchema = z.object({
  count: z.number().int().nonnegative(), medianMinutes: nullableMinutes, p90Minutes: nullableMinutes,
}).strict();

export const radiologyReadsSchema = z.object({
  generatedAt: z.string().datetime({ offset: true }),
  sourceCutoffAt: nullableIso,
  state: z.enum(['normal', 'stale', 'degraded', 'no_data', 'source_error', 'missing_feed']),
  stateMessage: z.string().min(1),
  freshness: sourceFreshnessSchema,
  filters: z.object({
    state: z.enum(['unread', 'no_report', 'preliminary', 'final', 'corrected']),
    priority: z.string().nullable(), subspecialty: z.string().nullable(), modality: z.string().nullable(),
    windowHours: z.number().int().positive(), limit: z.number().int().positive().max(50),
  }).strict(),
  filterOptions: z.object({
    states: z.array(z.string()), priorities: z.array(z.string()),
    subspecialties: z.array(z.object({ code: z.string(), label: z.string() }).strict()),
    modalities: z.array(z.object({ code: z.string(), label: z.string() }).strict()),
    windowHours: z.array(z.number().int().positive()),
  }).strict(),
  health: z.object({
    unreadCount: z.number().int().nonnegative(), oldestUnreadAgeMinutes: z.number().int().nonnegative().nullable(),
    unreadByPriority: z.array(readPrioritySummarySchema), openCriticalLoopCount: z.number().int().nonnegative(),
    oldestCriticalLoopAgeMinutes: z.number().int().nonnegative().nullable(),
    sourceState: z.enum(['fresh', 'stale', 'missing', 'error']), sourceCutoffAt: nullableIso,
  }).strict(),
  unread: z.object({
    total: z.number().int().nonnegative(), oldestAgeMinutes: z.number().int().nonnegative().nullable(),
    byPriority: z.array(readPrioritySummarySchema),
    bySubspecialty: z.array(z.object({ code: z.string(), label: z.string(), count: z.number().int().nonnegative(), oldestAgeMinutes: z.number().int().nonnegative() }).strict()),
  }).strict(),
  reportStates: z.array(z.object({ state: z.string(), count: z.number().int().nonnegative() }).strict()),
  backlog: z.object({
    bucketMinutes: z.number().int().positive(), windowStart: z.string().datetime({ offset: true }), windowEnd: z.string().datetime({ offset: true }), comparable: z.boolean(),
    points: z.array(z.object({
      bucketStart: z.string().datetime({ offset: true }), bucketEnd: z.string().datetime({ offset: true }),
      openAtEnd: z.number().int().nonnegative(), entered: z.number().int().nonnegative(), finalized: z.number().int().nonnegative(), netChange: z.number().int(),
    }).strict()),
    missing: z.object({ completionTimestampCount: z.number().int().nonnegative(), finalTimestampCount: z.number().int().nonnegative() }).strict(),
    definition: z.string(),
  }).strict(),
  preliminaryToFinal: z.object({
    count: z.number().int().nonnegative(), medianMinutes: nullableMinutes, p90Minutes: nullableMinutes, maxMinutes: nullableMinutes,
    missingPreliminaryCount: z.number().int().nonnegative(), excludedNegativeCount: z.number().int().nonnegative(), definition: z.string(),
  }).strict(),
  criticalLoops: z.object({
    summary: z.object({
      total: z.number().int().nonnegative(), open: z.number().int().nonnegative(), oldestOpenAgeMinutes: z.number().int().nonnegative().nullable(),
      byState: z.array(z.object({ state: z.string(), count: z.number().int().nonnegative() }).strict()),
    }).strict(),
    timings: z.object({ identifiedToNotified: readDistributionSchema, notifiedToAcknowledged: readDistributionSchema }).strict(),
    openItems: z.array(z.object({
      criticalResultUuid: z.string().uuid(), examUuid: z.string().uuid(), findingClass: z.string(), state: z.string(), priority: z.string(),
      modality: z.string().nullable(), identifiedAt: z.string().datetime({ offset: true }), ageMinutes: z.number().int().nonnegative(),
      recipientRole: z.string().nullable(), drillHref: z.string(),
    }).strict()),
  }).strict(),
  items: z.array(z.object({
    examUuid: z.string().uuid(), orderUuid: z.string().uuid(), patientRef: z.string(), label: z.string(), priority: z.string(), patientClass: z.string(),
    modality: z.string().nullable(), subspecialtyCode: z.string().nullable(), subspecialtyLabel: z.string().nullable(),
    reportState: z.enum(['no_report', 'preliminary', 'final', 'corrected']),
    urgency: z.enum(['normal', 'warning', 'breach', 'stale', 'degraded', 'unconfigured']), ageMinutes: z.number().int().nonnegative(),
    completedAt: nullableIso, firstPreliminaryAt: nullableIso, firstFinalAt: nullableIso, latestCorrectedAt: nullableIso,
    latestReadUuid: z.string().uuid().nullable(), sourceReportVersion: z.string().nullable(), correctionCount: z.number().int().nonnegative(), isTeleradiology: z.boolean(),
    definition: z.object({ definitionUuid: z.string().uuid(), label: z.string(), startMilestoneCode: z.string(), stopMilestoneCode: z.string(), warningMinutes: z.number().int().nonnegative().nullable(), breachMinutes: z.number().int().nonnegative().nullable() }).strict().nullable(),
    drillHref: z.string(),
  }).strict()),
  privacy: z.object({ clinicalReportTextIncluded: z.literal(false), identifierPolicy: z.string() }).strict(),
}).strict();

export type RadiologyReads = z.infer<typeof radiologyReadsSchema>;
