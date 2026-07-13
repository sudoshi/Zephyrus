import { z } from 'zod';
import { sourceFreshnessSchema, slaDefinitionSchema } from '@/Components/Ancillary/schemas';

const nullableIso = z.string().datetime({ offset: true }).nullable();

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
    lens: z.enum(['all', 'ed', 'inpatient', 'discharge_gate', 'or_gate', 'critical_callbacks', 'degraded']),
    priority: z.enum(['stat', 'urgent', 'routine', 'timed', 'discharge']).nullable(),
    testFamily: z.string().nullable(), unitId: z.number().int().positive().nullable(), shift: z.enum(['am_draw', 'day', 'evening', 'night']).nullable(),
    source: z.enum(['flow_board', 'ancillary_services', 'ed', 'rtdc', 'periop', 'cockpit']).nullable(),
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

const specimenTimelineStageSchema = z.object({
  code: z.string(), label: z.string(), at: nullableIso, state: z.enum(['complete', 'pending', 'not_asserted', 'exception']),
}).strict();

export const labSpecimensSchema = z.object({
  generatedAt: z.string().datetime({ offset: true }),
  state: z.enum(['normal', 'degraded', 'no_data', 'stale', 'source_error']), stateMessage: z.string(), freshness: sourceFreshnessSchema,
  filters: z.object({
    status: z.string().nullable(), testFamily: z.string().nullable(), unitId: z.number().int().positive().nullable(), priority: z.string().nullable(),
    rejection: z.enum(['all', 'rejected', 'recollect', 'none']), age: z.enum(['all', '0_29', '30_59', '60_119', '120_plus']),
    orderUuid: z.string().uuid().nullable(), perPage: z.number().int().positive().max(50), cursor: z.string().nullable(),
  }).strict(),
  filterOptions: z.object({
    statuses: z.array(z.string()), testFamilies: z.array(z.string()),
    units: z.array(z.object({ unitId: z.number().int().positive(), label: z.string() }).strict()), priorities: z.array(z.string()),
    rejections: z.array(z.string()), ageBands: z.array(z.string()),
  }).strict(),
  coverage: z.object({ transport: z.object({ status: z.enum(['available', 'missing']), columnVisible: z.boolean(), explanation: z.string() }).strict() }).strict(),
  data: z.array(z.object({
    specimenUuid: z.string().uuid(), orderUuid: z.string().uuid(),
    accessionIdentity: z.object({ sourceSpecimenKey: z.string(), sourceAccessionKey: z.string().nullable(), sourceKey: z.string() }).strict(),
    patientRef: z.string(), patientClass: z.string(), priority: z.string(), testFamily: z.string().nullable(), unitLabel: z.string().nullable(),
    specimenType: z.string(), containerType: z.string().nullable(), collectorRole: z.string().nullable(), collectionMethod: z.string().nullable(),
    status: z.string(), rejectionReasonCode: z.string().nullable(), ageMinutes: z.number().int().nonnegative(),
    timeline: z.array(specimenTimelineStageSchema),
    result: z.object({
      resultUuid: z.string().uuid(), testLabel: z.string(), status: z.string(), stage: z.string(), abnormalFlag: z.string(),
      autoVerified: z.boolean(), critical: z.boolean(), resultedAt: nullableIso, verifiedAt: nullableIso, correctedAt: nullableIso,
      versionCount: z.number().int().positive(),
    }).strict().nullable(),
    chain: z.object({
      rootSpecimenUuid: z.string().uuid(), depth: z.number().int().nonnegative(), position: z.number().int().positive(), length: z.number().int().positive(),
      parentSpecimenUuid: z.string().uuid().nullable(), childSpecimenUuids: z.array(z.string().uuid()), representativeSpecimenUuid: z.string().uuid(),
    }).strict(),
    downstreamImpact: decisionContextSchema,
    decisionRepresentedBySpecimenUuid: z.string().uuid().nullable(), sourceCutoffAt: z.string().datetime({ offset: true }),
  }).strict()),
  privacy: z.object({ patientContextIncluded: z.boolean(), directPatientIdentifiersIncluded: z.literal(false), resultContentIncluded: z.literal(false), identifierPolicy: z.string() }).strict(),
  meta: z.object({ perPage: z.number().int().positive(), count: z.number().int().nonnegative(), hasMore: z.boolean(), nextCursor: z.string().nullable(), previousCursor: z.string().nullable() }).strict(),
}).strict();

export type LabSpecimens = z.infer<typeof labSpecimensSchema>;

const pendingUrgencySchema = z.enum(['breach', 'warning', 'normal', 'unconfigured', 'degraded', 'stale']);

export const labDecisionPendingSchema = z.object({
  generatedAt: z.string().datetime({ offset: true }),
  state: z.enum(['normal', 'degraded', 'no_data', 'stale', 'source_error']),
  stateMessage: z.string().min(1),
  freshness: sourceFreshnessSchema,
  filters: z.object({
    decisionClass: z.enum(['all', 'or_gate', 'discharge_gate', 'ed_disposition']),
    priority: z.string().nullable(), unitId: z.number().int().positive().nullable(),
    urgency: z.enum(['all', 'breach', 'warning', 'normal', 'unconfigured', 'degraded', 'stale']),
    orderUuid: z.string().uuid().nullable(),
    source: z.enum(['flow_board', 'ancillary_services', 'ed', 'rtdc', 'periop', 'cockpit']).nullable(),
    limit: z.number().int().positive().max(100),
  }).strict(),
  filterOptions: z.object({
    decisionClasses: z.array(z.string()), priorities: z.array(z.string()),
    units: z.array(z.object({ unitId: z.number().int().positive(), label: z.string() }).strict()),
    urgencies: z.array(z.string()),
  }).strict(),
  rankingRule: z.string().min(1),
  summary: z.object({
    visible: z.number().int().nonnegative(), resolvedBeforeLimit: z.number().int().nonnegative(),
    orGates: z.number().int().nonnegative(), dischargeGates: z.number().int().nonnegative(),
    edDispositions: z.number().int().nonnegative(), unresolvedDestinations: z.number().int().nonnegative(),
    breached: z.number().int().nonnegative(),
  }).strict(),
  exclusions: z.object({
    noGateCatalog: z.number().int().nonnegative(), completedOrCancelled: z.number().int().nonnegative(),
    unresolved: z.array(z.object({ orderUuid: z.string().uuid(), decisionClass: z.string(), destinationId: z.number().int().positive().nullable(), reason: z.string() }).strict()),
    explanation: z.string().min(1),
  }).strict(),
  data: z.array(z.object({
    pendingKey: z.string().min(1), orderUuid: z.string().uuid(), resultUuid: z.string().uuid().nullable(), specimenUuid: z.string().uuid().nullable(),
    label: z.string().min(1), testFamily: z.string().min(1), catalogKey: z.string().min(1),
    patientRef: z.string().min(1), patientClass: z.string().min(1), priority: z.string().min(1),
    locationLabel: z.string().nullable(), encounterLinked: z.boolean(), currentStage: z.string().min(1),
    resultState: z.object({ status: z.string(), stage: z.string(), critical: z.boolean(), abnormalFlag: z.string() }).strict(),
    ageMinutes: z.number().int().nonnegative(), sourceCutoffAt: z.string().datetime({ offset: true }),
    decisionClass: z.enum(['or_gate', 'discharge_gate', 'ed_disposition']), decisionContext: decisionContextSchema.unwrap(),
    destination: z.object({
      objectType: z.enum(['or_case', 'encounter_discharge', 'ed_visit']), id: z.number().int().positive(), label: z.string().min(1),
      active: z.literal(true), href: z.string().min(1), scheduledAt: nullableIso, expectedDischargeDate: z.string().date().nullable(),
      bedImpact: z.number().int().nonnegative(), rankReason: z.string().min(1),
    }).strict(),
    gateEvidence: z.object({ catalogDecisionClass: z.string(), identitySource: z.enum(['result_decision_context', 'order_linkage']), validated: z.literal(true), explanation: z.string().min(1) }).strict(),
    sla: z.object({ definition: slaDefinitionSchema.nullable(), startAt: nullableIso, elapsedMinutes: z.number().int().nonnegative().nullable(), urgency: pendingUrgencySchema, explanation: z.string().min(1) }).strict(),
    ranking: z.object({ impactRank: z.number().int().min(0).max(2), priorityRank: z.number().int().nonnegative(), sortKey: z.string(), reasons: z.array(z.string().min(1)).min(3), position: z.number().int().positive() }).strict(),
    drill: z.object({ specimenHref: z.string().min(1), destinationHref: z.string().min(1) }).strict(),
    barrierCount: z.number().int().nonnegative(),
  }).strict()),
  destinationAggregates: z.array(z.object({
    decisionClass: z.enum(['or_gate', 'discharge_gate', 'ed_disposition']), destinationId: z.number().int().positive(),
    destinationHref: z.string().min(1), pendingCount: z.number().int().positive(), oldestAgeMinutes: z.number().int().nonnegative(),
    topOrderUuid: z.string().uuid(), resultUuids: z.array(z.string().uuid()),
  }).strict()),
  privacy: z.object({ patientContextIncluded: z.boolean(), directPatientIdentifiersIncluded: z.literal(false), resultContentIncluded: z.literal(false), identifierPolicy: z.string() }).strict(),
  canAnnotateBarriers: z.boolean(),
  barrierReasons: z.array(z.object({ reasonCode: z.string(), category: z.string(), label: z.string() }).strict()),
}).strict();

export type LabDecisionPending = z.infer<typeof labDecisionPendingSchema>;
export type LabDecisionPendingItem = LabDecisionPending['data'][number];

export const bloodBankCaseGateSchema = z.object({
  caseId: z.number().int().positive(), caseLabel: z.string().min(1), surgeryDate: z.string().date(),
  scheduledStartAt: z.string().datetime({ offset: true }), scheduledDurationMinutes: z.number().int().positive(),
  minutesToStart: z.number().int(), startTiming: z.enum(['upcoming', 'past_due']),
  roomLabel: z.string().min(1), serviceLabel: z.string().min(1), locationLabel: z.string().min(1),
  required: z.boolean(), state: z.enum(['blocked', 'ready', 'not_applicable', 'mtp_active', 'unknown']),
  ready: z.boolean(), blocking: z.boolean(), mtpActive: z.boolean(), explanation: z.string().min(1),
  requestCount: z.number().int().nonnegative(), productClasses: z.array(z.string()),
  units: z.object({ requested: z.number().int().nonnegative(), allocated: z.number().int().nonnegative(), issued: z.number().int().nonnegative() }).strict(),
  typeScreenState: z.enum(['not_applicable', 'not_required', 'pending', 'ready', 'expired', 'incompatible', 'unknown']),
  crossmatchState: z.enum(['not_applicable', 'not_required', 'pending', 'ready', 'expired', 'incompatible', 'unknown']),
  issueState: z.enum(['not_applicable', 'not_issued', 'partial', 'issued']),
  neededByAt: nullableIso, neededByAligned: z.boolean().nullable(), sourceCutoffAt: nullableIso,
  freshness: sourceFreshnessSchema,
  coverage: z.object({ status: z.enum(['complete', 'degraded', 'not_applicable']), explanation: z.string().min(1) }).strict(),
  requests: z.array(z.object({
    readinessUuid: z.string().uuid(), orderUuid: z.string().uuid(), productClass: z.string(), readinessState: z.string(),
    typeScreenState: z.string(), crossmatchState: z.string(), unitsRequested: z.number().int().positive(),
    unitsAllocated: z.number().int().nonnegative(), unitsIssued: z.number().int().nonnegative(),
    orderedAt: z.string().datetime({ offset: true }), neededByAt: nullableIso, typeScreenReadyAt: nullableIso,
    crossmatchReadyAt: nullableIso, allocatedAt: nullableIso, issuedAt: nullableIso, expiresAt: nullableIso,
    mtpActivatedAt: nullableIso, sourceKey: z.string().min(1),
  }).strict()),
  drillHref: z.string().min(1),
}).strict().superRefine((gate, context) => {
  if (!gate.required && gate.state !== 'not_applicable' && gate.state !== 'unknown') {
    context.addIssue({ code: 'custom', path: ['state'], message: 'A case without a requirement must be not applicable unless freshness is unknown.' });
  }
  if (gate.ready && gate.state !== 'ready') {
    context.addIssue({ code: 'custom', path: ['ready'], message: 'Ready flag must match ready state.' });
  }
  if (gate.blocking !== ['blocked', 'mtp_active'].includes(gate.state)) {
    context.addIssue({ code: 'custom', path: ['blocking'], message: 'Blocking flag must match a blocking gate state.' });
  }
});

export const bloodBankReadinessSchema = z.object({
  generatedAt: z.string().datetime({ offset: true }), operatingDate: z.string().date().nullable(),
  operatingDateMode: z.enum(['latest_operating_day', 'exact_case']),
  state: z.enum(['normal', 'degraded', 'no_data', 'stale', 'source_error']), stateMessage: z.string().min(1),
  freshness: sourceFreshnessSchema,
  filters: z.object({
    state: z.enum(['all', 'blocked', 'ready', 'not_applicable', 'mtp_active', 'unknown']),
    productClass: z.enum(['all', 'red_cells', 'plasma', 'platelets', 'cryo', 'whole_blood', 'mixed', 'other']),
    service: z.string().nullable(), room: z.string().nullable(), caseId: z.number().int().positive().nullable(),
  }).strict(),
  filterOptions: z.object({ states: z.array(z.string()), productClasses: z.array(z.string()), services: z.array(z.string()), rooms: z.array(z.string()) }).strict(),
  summary: z.object({
    cases: z.number().int().nonnegative(), required: z.number().int().nonnegative(), blocked: z.number().int().nonnegative(),
    ready: z.number().int().nonnegative(), notApplicable: z.number().int().nonnegative(), unknown: z.number().int().nonnegative(), mtpActive: z.number().int().nonnegative(),
  }).strict(),
  data: z.array(bloodBankCaseGateSchema),
  privacy: z.object({ directPatientIdentifiersIncluded: z.literal(false), bloodProductAllocationControlIncluded: z.literal(false), writebackIncluded: z.literal(false), explanation: z.string().min(1) }).strict(),
}).strict();

export type BloodBankReadiness = z.infer<typeof bloodBankReadinessSchema>;
export type BloodBankCaseGate = z.infer<typeof bloodBankCaseGateSchema>;

export const frozenSectionTimerSchema = z.object({
  caseId: z.number().int().positive(), apCaseUuid: z.string().uuid(), label: z.string().min(1),
  startedAt: z.string().datetime({ offset: true }), elapsedMinutes: z.number().int().nonnegative(), blocking: z.literal(true),
  explanation: z.string().min(1), sourceCutoffAt: z.string().datetime({ offset: true }), drillHref: z.string().min(1),
}).strict();

const pathologyTimelineStageSchema = z.object({
  stage: z.enum(['received', 'grossed', 'processing', 'slides_ready', 'diagnosed', 'signed_out']),
  label: z.string().min(1), at: nullableIso, state: z.enum(['complete', 'current', 'pending', 'not_asserted']),
}).strict();

export const anatomicPathologySchema = z.object({
  generatedAt: z.string().datetime({ offset: true }), lookbackDays: z.number().int().positive(),
  state: z.enum(['normal', 'degraded', 'no_data', 'stale', 'source_error']), stateMessage: z.string().min(1),
  freshness: sourceFreshnessSchema,
  filters: z.object({
    stage: z.enum(['all', 'specimen_out', 'received', 'grossed', 'processing', 'slides_ready', 'diagnosed', 'signed_out']),
    cohort: z.enum(['all', 'routine', 'complex', 'consult_send_out', 'frozen_section']), status: z.enum(['all', 'open', 'completed']),
    ageBand: z.enum(['all', 'under_4h', '4_to_8h', '8_to_24h', '24_to_48h', '48_plus', 'complete']),
    caseId: z.number().int().positive().nullable(), limit: z.number().int().positive().max(100),
  }).strict(),
  filterOptions: z.object({ stages: z.array(z.string()), cohorts: z.array(z.string()), statuses: z.array(z.string()), ageBands: z.array(z.string()) }).strict(),
  summary: z.object({
    visible: z.number().int().nonnegative(), matchingBeforeLimit: z.number().int().nonnegative(), open: z.number().int().nonnegative(),
    completed: z.number().int().nonnegative(), activeFrozen: z.number().int().nonnegative(),
    byStage: z.array(z.object({ stage: z.string(), label: z.string(), count: z.number().int().nonnegative() }).strict()),
    byCohort: z.array(z.object({ cohort: z.string(), label: z.string(), count: z.number().int().nonnegative() }).strict()),
  }).strict(),
  benchmarkLines: z.array(z.object({
    key: z.enum(['routine', 'complex', 'frozen_single_block']), label: z.string(), percentile: z.literal(90),
    thresholdValue: z.number().positive(), thresholdUnit: z.enum(['days', 'minutes']), evidenceLabel: z.string().min(1), applicability: z.string().min(1),
  }).strict()),
  coverage: z.object({
    apLis: z.object({ status: z.enum(['available', 'missing']), explanation: z.string().min(1) }).strict(),
    backfill: z.object({ status: z.enum(['available', 'missing', 'not_configured']), lastSuccessAt: nullableIso, explanation: z.string().min(1) }).strict(),
  }).strict(),
  data: z.array(z.object({
    apCaseUuid: z.string().uuid(), orderUuid: z.string().uuid(), caseId: z.number().int().positive().nullable(), caseLabel: z.string().nullable(),
    sourceCaseKey: z.string().min(1), sourceAccessionKey: z.string().nullable(), sourceKey: z.string().min(1),
    procedureLabel: z.string().min(1), caseType: z.string().min(1), cohort: z.enum(['routine', 'complex', 'consult_send_out', 'frozen_section']),
    cohortLabel: z.string().min(1), stage: z.string().min(1), stageLabel: z.string().min(1), currentStageAt: z.string().datetime({ offset: true }),
    stageAgeMinutes: z.number().int().nonnegative().nullable(), totalAgeMinutes: z.number().int().nonnegative(),
    ageBand: z.enum(['under_4h', '4_to_8h', '8_to_24h', '24_to_48h', '48_plus', 'complete']), terminal: z.boolean(),
    timeline: z.array(pathologyTimelineStageSchema),
    structuralStage: z.object({ kind: z.enum(['overnight_batch', 'send_out', 'none']), label: z.string().nullable(), enteredAt: nullableIso, explanation: z.string().nullable() }).strict(),
    benchmarkKey: z.enum(['routine', 'complex', 'frozen_single_block']).nullable(),
    frozen: z.object({
      applicable: z.boolean(), status: z.enum(['not_applicable', 'pending', 'in_progress', 'resulted', 'cancelled']),
      startedAt: nullableIso, resultedAt: nullableIso, elapsedMinutes: z.number().int().nonnegative().nullable(), timerActive: z.boolean(), timer: frozenSectionTimerSchema.nullable(),
    }).strict(),
    sourceCutoffAt: z.string().datetime({ offset: true }), drillHref: z.string().min(1),
  }).strict()),
  privacy: z.object({ directPatientIdentifiersIncluded: z.literal(false), diagnosisOrNarrativeIncluded: z.literal(false), writebackIncluded: z.literal(false), explanation: z.string().min(1) }).strict(),
}).strict();

export type FrozenSectionTimerContract = z.infer<typeof frozenSectionTimerSchema>;
export type AnatomicPathology = z.infer<typeof anatomicPathologySchema>;
export type AnatomicPathologyCase = AnatomicPathology['data'][number];

const labTatDistributionSchema = z.object({
  count: z.number().int().nonnegative(), medianMinutes: z.number().nonnegative().nullable(),
  p90Minutes: z.number().nonnegative().nullable(), meanMinutes: z.number().nonnegative().nullable(),
}).strict();

const labTatPointSchema = labTatDistributionSchema.extend({ key: z.string(), label: z.string() }).strict();

const labTatChartContextSchema = z.object({
  clockDefinition: slaDefinitionSchema.nullable(), cohortCount: z.number().int().nonnegative(),
  sourceCutoffAt: nullableIso, benchmarkSourceLabel: z.string().min(1),
}).strict();

const labTatCountPointSchema = z.object({ key: z.string(), label: z.string(), count: z.number().int().nonnegative() }).strict();

const labTatAssertionSchema = z.object({
  milestoneUuid: z.string().uuid(), code: z.string(), occurredAt: z.string().datetime({ offset: true }),
  receivedAt: z.string().datetime({ offset: true }), sourceKey: z.string(), sourceRank: z.number().int().nonnegative(),
  assertionCount: z.number().int().positive(),
}).strict();

export const labTatSchema = z.object({
  generatedAt: z.string().datetime({ offset: true }), sourceCutoffAt: nullableIso,
  freshnessStatus: z.enum(['fresh', 'stale', 'unknown', 'batch']), degradedMode: z.boolean(),
  state: z.enum(['normal', 'stale', 'degraded', 'no_data', 'source_error']), stateMessage: z.string().min(1),
  freshness: sourceFreshnessSchema,
  filters: z.object({
    dateFrom: z.string().regex(/^\d{4}-\d{2}-\d{2}$/), dateTo: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
    priority: z.string().nullable(), testFamily: z.string().nullable(), patientClass: z.string().nullable(),
    shift: z.enum(['day', 'evening', 'night', 'weekend']).nullable(), limit: z.number().int().positive().max(2000),
  }).strict(),
  filterOptions: z.object({
    priorities: z.array(z.string()), testFamilies: z.array(z.string()), patientClasses: z.array(z.string()),
    shifts: z.array(z.enum(['day', 'evening', 'night', 'weekend'])), maxRangeDays: z.number().int().positive(), maxLimit: z.number().int().positive(),
  }).strict(),
  appliedSlaDefinitions: z.array(slaDefinitionSchema),
  summary: labTatDistributionSchema.extend({
    candidateOrderCount: z.number().int().nonnegative(), includedOrderCount: z.number().int().nonnegative(),
    clockDefinition: slaDefinitionSchema.nullable(),
  }).strict(),
  waterfall: z.array(labTatDistributionSchema.extend({
    phase: z.enum(['collection', 'transport', 'analytic', 'post_analytic', 'end_to_end']), definition: slaDefinitionSchema,
    cohortCount: z.number().int().nonnegative(), missingIntervalCount: z.number().int().nonnegative(),
    excludedNegativeCount: z.number().int().nonnegative(), invalidTimestampCount: z.number().int().nonnegative(),
    sourceCutoffAt: nullableIso, benchmarkSourceLabel: z.string().min(1),
  }).strict()),
  dailyTrend: labTatChartContextSchema.extend({ label: z.string(), points: z.array(labTatPointSchema) }).strict(),
  breakdowns: z.object({
    test: labTatChartContextSchema.extend({ label: z.string(), dimension: z.literal('testFamily'), points: z.array(labTatPointSchema) }).strict(),
    priority: labTatChartContextSchema.extend({ label: z.string(), dimension: z.literal('priority'), points: z.array(labTatPointSchema) }).strict(),
    patientClass: labTatChartContextSchema.extend({ label: z.string(), dimension: z.literal('patientClass'), points: z.array(labTatPointSchema) }).strict(),
    shift: labTatChartContextSchema.extend({ label: z.string(), dimension: z.literal('shift'), points: z.array(labTatPointSchema) }).strict(),
  }).strict(),
  amReadiness: z.object({
    clockDefinition: slaDefinitionSchema.nullable(), populationDefinition: z.string().min(1), cohortCount: z.number().int().nonnegative(), sourceCutoffAt: nullableIso,
    points: z.array(z.object({
      hour: z.number().int().min(0).max(23), label: z.string(), eligibleCount: z.number().int().nonnegative(),
      readyCount: z.number().int().nonnegative(), readyPercent: z.number().min(0).max(100).nullable(),
    }).strict()),
  }).strict(),
  autoVerification: z.object({
    clockDefinition: z.string().min(1), populationDefinition: z.string().min(1), cohortCount: z.number().int().nonnegative(), sourceCutoffAt: nullableIso,
    points: z.array(z.object({
      date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/), label: z.string(), verifiedCount: z.number().int().positive(),
      autoVerifiedCount: z.number().int().nonnegative(), ratePercent: z.number().min(0).max(100),
    }).strict()),
  }).strict(),
  specimenQuality: z.object({
    clockDefinition: z.string().min(1), populationDefinition: z.string().min(1), denominator: z.number().int().nonnegative(),
    rejectedCount: z.number().int().nonnegative(), rejectionRatePercent: z.number().min(0).max(100).nullable(),
    recollectCount: z.number().int().nonnegative(), recollectRatePercent: z.number().min(0).max(100).nullable(), reasonCounts: z.array(labTatCountPointSchema),
  }).strict(),
  criticalCallbacks: labTatDistributionSchema.extend({
    clockDefinition: slaDefinitionSchema.nullable(), populationDefinition: z.string().min(1), cohortCount: z.number().int().nonnegative(),
    openCount: z.number().int().nonnegative(), invalidIntervalCount: z.number().int().nonnegative(), sourceCutoffAt: nullableIso,
    stateCounts: z.array(labTatCountPointSchema),
  }).strict(),
  barrierPareto: z.object({
    cohortCount: z.number().int().nonnegative(), sourceCutoffAt: nullableIso, clockDefinition: z.string().min(1),
    points: z.array(z.object({ key: z.string(), label: z.string(), count: z.number().int().nonnegative(), percent: z.number().min(0).max(100), cumulativePercent: z.number().min(0).max(100) }).strict()),
  }).strict(),
  cohorts: z.object({
    clinicalLab: z.object({
      label: z.literal('Clinical Laboratory'), windowClass: z.literal('current_operational'), populationDefinition: z.string().min(1),
      candidateCount: z.number().int().nonnegative(), includedCount: z.number().int().nonnegative(), primaryClockMetricKey: z.literal('lab.study.order_verify'),
    }).strict(),
    microbiology: z.object({
      label: z.literal('Microbiology progression'), windowClass: z.enum(['historical_study_only', 'mixed_current_and_historical']),
      windowLabel: z.string().min(1), populationDefinition: z.string().min(1), candidateCount: z.number().int().nonnegative(),
      historicalCount: z.number().int().nonnegative(), currentCount: z.number().int().nonnegative(), stageCounts: z.array(labTatCountPointSchema),
    }).strict(),
    anatomicPathology: z.object({
      label: z.literal('Anatomic Pathology'), windowClass: z.enum(['current_operational', 'mixed_current_and_historical']),
      windowLabel: z.string().min(1), populationDefinition: z.string().min(1), candidateCount: z.number().int().nonnegative(),
      historicalCount: z.number().int().nonnegative(), currentCount: z.number().int().nonnegative(), stageCounts: z.array(labTatCountPointSchema),
      signOut: labTatDistributionSchema.extend({ clockDefinition: slaDefinitionSchema.nullable(), invalidIntervalCount: z.number().int().nonnegative() }).strict(),
      frozen: labTatDistributionSchema.extend({ clockDefinition: slaDefinitionSchema.nullable(), invalidIntervalCount: z.number().int().nonnegative() }).strict(),
    }).strict(),
    bloodBank: z.object({
      label: z.literal('Blood Bank readiness'), windowClass: z.literal('current_operational'), populationDefinition: z.string().min(1),
      candidateCount: z.number().int().nonnegative(), stateCounts: z.array(labTatCountPointSchema),
      typeScreen: labTatDistributionSchema.extend({ clockDefinition: z.string(), invalidIntervalCount: z.number().int().nonnegative() }).strict(),
      crossmatch: labTatDistributionSchema.extend({ clockDefinition: z.string(), invalidIntervalCount: z.number().int().nonnegative() }).strict(),
      issue: labTatDistributionSchema.extend({ clockDefinition: z.string(), invalidIntervalCount: z.number().int().nonnegative() }).strict(),
    }).strict(),
  }).strict(),
  benchmarkReferences: z.array(z.object({
    definitionUuid: z.string().uuid(), metricKey: z.string(), label: z.string(), sourceReferenceId: z.string().nullable(), sourceLabel: z.string(),
    classification: z.enum(['local_policy', 'established_reference', 'site_policy_required', 'no_numeric_benchmark', 'governed_reference']),
    numericLines: z.array(z.object({ kind: z.enum(['warning', 'breach', 'target']), value: z.number().nonnegative(), unit: z.string() }).strict()),
  }).strict()),
  coverage: z.object({
    candidateOrderCount: z.number().int().nonnegative(), analyzedOrderCount: z.number().int().nonnegative(), includedOrderCount: z.number().int().nonnegative(),
    possibleIntervalCount: z.number().int().nonnegative(), includedIntervalCount: z.number().int().nonnegative(), percent: z.number().min(0).max(100),
    missingAssertionIntervalCount: z.number().int().nonnegative(), excludedNegativeIntervalCount: z.number().int().nonnegative(),
    invalidTimestampIntervalCount: z.number().int().nonnegative(), selectedAssertionConflictCount: z.number().int().nonnegative(),
    truncated: z.boolean(), unanalyzedCandidateCount: z.number().int().nonnegative(), definition: z.string().min(1), auxiliaryInvalidIntervalCount: z.number().int().nonnegative(),
  }).strict(),
  lineage: z.object({
    count: z.number().int().nonnegative(), truncated: z.boolean(), definition: z.string().min(1),
    items: z.array(z.object({
      orderUuid: z.string().uuid(), definitionUuid: z.string().uuid(), metricKey: z.string(), minutes: z.number().nonnegative(),
      date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/), priority: z.string(), testFamily: z.string(), testLabel: z.string(),
      patientClass: z.string(), shift: z.enum(['day', 'evening', 'night', 'weekend']), sourceCutoffAt: z.string().datetime({ offset: true }),
      startAssertion: labTatAssertionSchema, stopAssertion: labTatAssertionSchema,
    }).strict()),
  }).strict(),
  privacy: z.object({
    patientIdentifiersIncluded: z.literal(false), clinicalResultContentIncluded: z.literal(false), sourceResultKeysIncluded: z.literal(false), identifierPolicy: z.string().min(1),
  }).strict(),
}).strict();

export type LabTat = z.infer<typeof labTatSchema>;
