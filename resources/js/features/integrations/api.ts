import axios from 'axios';
import { z } from 'zod';

const nullableString = z.string().nullable();

const sourceSchema = z.object({
  sourceId: z.number(),
  sourceKey: z.string(),
  sourceName: z.string(),
  organizationId: z.number().nullable(),
  organizationName: nullableString,
  facilityId: z.number().nullable(),
  facilityName: nullableString,
  vendor: nullableString,
  systemClass: z.string(),
  environment: z.string(),
  interfaceType: z.string(),
  configuredStatus: z.string(),
  healthStatus: z.string(),
  derivedHealthStatus: z.string(),
  observabilityStatus: z.string(),
  healthObservationId: z.number().nullable(),
  healthObservedAtIso: nullableString,
  healthFreshUntilIso: nullableString,
  healthObservationStale: z.boolean(),
  maintenanceActive: z.boolean(),
  sloDefinitionId: z.number().nullable(),
  sloSummary: z.object({
    met: z.number(),
    breached: z.number(),
    unknown: z.number(),
    notApplicable: z.number(),
  }),
  queueState: z.record(z.string(), z.unknown()),
  runtimeState: z.record(z.string(), z.unknown()),
  openSloBreaches: z.number(),
  protocolHealthStatus: z.string(),
  protocolHealthCheckedAtIso: nullableString,
  protocolHealthErrorCode: nullableString,
  goLiveStatus: z.string(),
  lifecycleState: z.string(),
  lifecycleChangedAtIso: nullableString,
  currentConfigurationVersionId: z.number().nullable(),
  currentConfigurationVersionNumber: z.number().nullable(),
  currentConfigurationSha256: nullableString,
  currentConfigurationCreatedAtIso: nullableString,
  contractStatus: z.string(),
  baaStatus: z.string(),
  phiAllowed: z.boolean(),
  baseUrlConfigured: z.boolean(),
  baseUrlOrigin: nullableString,
  owner: nullableString,
  expectedCadenceMinutes: z.number().nullable(),
  lastObservedAtIso: nullableString,
  ageMinutes: z.number().nullable(),
  lastRunStatus: nullableString,
  counts: z.object({
    endpoints: z.number(),
    capabilities: z.number(),
    runs: z.number(),
    inboundMessages: z.number(),
    canonicalEvents: z.number(),
    openDeadLetters: z.number(),
  }),
});

const configuredSourceSchema = z.object({
  sourceId: z.number(), sourceKey: z.string(), sourceName: z.string(), tenantKey: z.string(),
  organizationId: z.number().nullable(), organizationName: nullableString,
  facilityId: z.number().nullable(), facilityName: nullableString,
  facilityKey: nullableString, vendor: nullableString, systemClass: z.string(), environment: z.string(),
  interfaceType: z.string(), activeStatus: z.string(), fhirVersion: nullableString, usCoreVersion: nullableString,
  smartSupported: z.boolean(), bulkSupported: z.boolean(), subscriptionsSupported: z.boolean(),
  contractStatus: z.string(), baaStatus: z.string(), phiAllowed: z.boolean(), goLiveStatus: z.string(),
  lifecycleState: z.string(), lifecycleChangedAtIso: nullableString,
  currentConfigurationVersionId: z.number().nullable(), currentConfigurationVersionNumber: z.number().nullable(),
  currentConfigurationSha256: nullableString, currentConfigurationCreatedAtIso: nullableString,
  baseUrlConfigured: z.boolean(), baseUrlOrigin: nullableString, owner: nullableString,
  expectedCadenceMinutes: z.number().nullable(),
});

const configuredEndpointSchema = z.object({
  endpointId: z.number(), sourceId: z.number(), endpointType: z.string(), urlConfigured: z.boolean(),
  urlOrigin: nullableString, authType: nullableString, tlsMode: nullableString, isActive: z.boolean(),
  owner: nullableString, expectedCadenceMinutes: z.number().nullable(),
});

const configuredCredentialSchema = z.object({
  credentialId: z.number(), sourceId: z.number(), credentialKey: z.string(), credentialType: z.string(),
  status: z.string(), credentialState: z.string(), currentCredentialVersionId: z.number().nullable(),
  secretReferenceConfigured: z.boolean(), certificateReferenceConfigured: z.boolean(),
  jwksConfigured: z.boolean(), rotatesAtIso: nullableString, validFromIso: nullableString,
  expiresAtIso: nullableString, rotationOverlapEndsAtIso: nullableString, revokedAtIso: nullableString,
  lastUsedAtIso: nullableString, owner: nullableString,
});

const secretProviderSchema = z.object({
  scheme: z.string(),
  enabled: z.boolean(),
});

const credentialVersionSchema = z.object({
  credentialVersionId: z.number(), credentialVersionUuid: z.string(), credentialId: z.number(),
  sourceId: z.number(), versionNumber: z.number(), previousVersionId: z.number().nullable(),
  credentialType: z.string(), credentialState: z.string(), secretReferenceConfigured: z.boolean(),
  secretProviderScheme: nullableString, certificateReferenceConfigured: z.boolean(),
  certificateProviderScheme: nullableString, jwksConfigured: z.boolean(), validFromIso: nullableString,
  expiresAtIso: nullableString, rotatesAtIso: nullableString, rotationOverlapEndsAtIso: nullableString,
  authoritySha256: z.string(), changeReason: z.string(), governedChangeRequestUuid: nullableString,
  createdAtIso: nullableString,
});

const credentialValidationSchema = z.object({
  credentialValidationObservationId: z.number().nullable(), credentialId: z.number(),
  credentialVersionId: z.number(), credentialVersionNumber: z.number(),
  status: z.enum(['ready', 'not_ready']), rotationState: z.string(), providerScheme: nullableString,
  providerVersion: nullableString, providerLeaseExpiresAtIso: nullableString,
  certificateMetadata: z.record(z.string(), z.unknown()),
  requirements: z.array(z.object({ code: z.string(), status: z.enum(['passed', 'failed']), message: z.string() })),
  inputSha256: z.string(), errorCode: nullableString, evaluatedForAtIso: z.string(),
});

const networkRouteSchema = z.object({
  networkRouteId: z.number(), sourceId: z.number(), sourceName: z.string().optional(),
  endpointId: z.number().nullable(), routeKey: z.string(), environment: z.string(), transport: z.string(),
  hostname: z.string(), port: z.number(), proxyConfigured: z.boolean(), proxyOrigin: nullableString,
  dnsPolicy: z.string(), allowedIpCidrs: z.array(z.string()), egressPolicyKey: z.string(),
  mtlsRequired: z.boolean(), clientCredentialId: z.number().nullable(), serverName: nullableString,
  status: z.string(), changeReason: z.string().optional(), lastAddressCount: z.number(),
  lastErrorCode: nullableString, lastObservedAtIso: nullableString, policySha256: nullableString,
});

const fhirResourceProfileSchema = z.object({
  profileId: z.number(),
  sourceId: z.number().optional(),
  resourceType: z.string(),
  canonicalProfileUrl: nullableString,
  canonicalProfileVersion: nullableString,
  status: z.string(),
  pollEnabled: z.boolean(),
  cadenceMinutes: z.number(),
  pageSize: z.number(),
  pageLimit: z.number(),
  resourceLimit: z.number(),
  versionNumber: z.number(),
  changeReason: z.string(),
});

const fhirOperationSchema = z.object({
  name: z.string(),
  definition: z.string(),
}).strict();

const fhirConformanceResourceSchema = z.object({
  resourceType: z.string(),
  baseProfileUrl: nullableString,
  supportedProfiles: z.array(z.string()),
  interactions: z.array(z.string()),
  versioning: nullableString,
  readHistory: z.boolean(),
  updateCreate: z.boolean(),
  conditionalCreate: z.boolean().nullable(),
  conditionalRead: nullableString,
  conditionalUpdate: z.boolean().nullable(),
  conditionalDelete: nullableString,
  searchIncludes: z.array(z.string()),
  searchRevIncludes: z.array(z.string()),
  searchParameters: z.array(z.object({
    name: z.string(), definition: nullableString, type: z.string(),
  }).strict()),
  operations: z.array(fhirOperationSchema),
}).strict();

const unobservedFhirConformanceSchema = z.object({
  status: z.literal('unobserved'),
  sourceId: z.number(),
  resources: z.array(z.never()),
}).strict();

const observedFhirConformanceSchema = z.object({
  status: z.enum(['passed', 'passed_with_warnings', 'legacy_reduced']),
  sourceId: z.number(),
  connectionId: z.number(),
  observationId: z.number(),
  observedAtIso: z.string(),
  fhirVersion: z.string(),
  capabilityKind: nullableString,
  capabilityStatus: nullableString,
  capabilityDateIso: nullableString,
  softwareName: nullableString,
  softwareVersion: nullableString,
  implementationOrigin: nullableString,
  formats: z.array(z.string()),
  patchFormats: z.array(z.string()),
  implementationGuides: z.array(z.string()),
  systemInteractions: z.array(z.string()),
  systemOperations: z.array(fhirOperationSchema),
  compartments: z.array(z.string()),
  securityServices: z.array(z.object({ system: nullableString, code: z.string() }).strict()),
  supportsBatch: z.boolean(),
  supportsTransaction: z.boolean(),
  supportsSystemHistory: z.boolean(),
  supportsSystemSearch: z.boolean(),
  supportsBulkData: z.boolean(),
  supportsSubscriptions: z.boolean(),
  resourceCount: z.number(),
  searchableResourceCount: z.number(),
  searchParameterCount: z.number(),
  operationCount: z.number(),
  warnings: z.array(z.string()),
  documentHashes: z.object({
    capabilityStatement: nullableString,
    smartConfiguration: nullableString,
  }).strict(),
  smart: z.object({
    issuerOrigin: nullableString,
    jwksOrigin: nullableString,
    authorizationOrigin: nullableString,
    tokenOrigin: nullableString,
    registrationOrigin: nullableString,
    managementOrigin: nullableString,
    introspectionOrigin: nullableString,
    grantTypes: z.array(z.string()),
    tokenAuthMethods: z.array(z.string()),
    tokenSigningAlgorithms: z.array(z.string()),
    scopes: z.array(z.string()),
    capabilities: z.array(z.string()),
    pkceMethods: z.array(z.string()),
    associatedEndpoints: z.array(z.object({
      origin: nullableString,
      capabilities: z.array(z.string()),
    }).strict()),
  }).strict(),
  resources: z.array(fhirConformanceResourceSchema),
}).strict();

const fhirConformanceSchema = z.union([
  unobservedFhirConformanceSchema,
  observedFhirConformanceSchema,
]);

const integrationControlPlaneSchema = z.object({
  generatedAtIso: z.string(),
  status: z.string(),
  freshnessPolicy: z.object({
    freshAfterMinutes: z.number(),
    staleAfterMinutes: z.number(),
  }),
  counts: z.object({
    sources: z.number(),
    activeSources: z.number(),
    healthySources: z.number(),
    degradedSources: z.number(),
    staleSources: z.number(),
    failedSources: z.number(),
    protocolHealthySources: z.number(),
    protocolDegradedSources: z.number(),
    protocolFailedSources: z.number(),
    endpoints: z.number(),
    capabilities: z.number(),
    credentials: z.number(),
    credentialVersions: z.number(),
    credentialValidations: z.number(),
    networkRoutes: z.number(),
    blockedNetworkRoutes: z.number(),
    interfaceEngines: z.number(),
    fhirConnections: z.number(),
    smartCredentials: z.number(),
    connectorTemplates: z.number(),
    coexistenceAdapters: z.number(),
    ingestRuns: z.number(),
    inboundMessages: z.number(),
    canonicalEvents: z.number(),
    pendingProjectionEvents: z.number(),
    openDeadLetters: z.number(),
    openProjectionErrors: z.number(),
    replayJobs: z.number(),
    terminologyMaps: z.number(),
    writebackDrafts: z.number(),
    configurationAudits: z.number(),
    configurationVersions: z.number(),
    lifecycleEvents: z.number(),
    healthObservations: z.number(),
    openSloBreaches: z.number(),
    queuedJobs: z.number(),
    failedQueueJobs: z.number(),
    pendingGovernedChanges: z.number(),
  }),
  sources: z.array(sourceSchema),
  endpoints: z.array(z.object({
    endpointId: z.number(),
    sourceId: z.number(),
    sourceName: z.string(),
    endpointType: z.string(),
    authType: nullableString,
    tlsMode: nullableString,
    isActive: z.boolean(),
    urlConfigured: z.boolean(),
    urlOrigin: nullableString,
    owner: nullableString,
    expectedCadenceMinutes: z.number().nullable(),
  })),
  fhirConnections: z.array(z.object({
    connectionId: z.number(),
    sourceId: z.number(),
    sourceName: z.string(),
    connectionKey: z.string(),
    status: z.string(),
    fhirVersion: nullableString,
    capabilityCheckedAtIso: nullableString,
    healthStatus: z.string(),
    healthCheckedAtIso: nullableString,
    healthErrorCode: nullableString,
    baseUrlConfigured: z.boolean(),
    supportedResourceCount: z.number(),
    resourceProfiles: z.array(fhirResourceProfileSchema),
  })),
  hl7Interfaces: z.array(z.object({
    interfaceEngineId: z.number(),
    engineKey: z.string(),
    label: z.string(),
    engineType: z.string(),
    environment: z.string(),
    status: z.string(),
  })),
  connectorFamilies: z.array(z.object({
    priority: z.number(),
    family: z.string(),
    protocols: z.array(z.string()),
    operationalValue: z.string(),
  })),
  mappings: z.object({
    approved: z.number(),
    pendingReview: z.number(),
    byType: z.array(z.object({ mapType: z.string(), count: z.number() })),
  }),
  runs: z.array(z.object({
    runId: z.number(),
    sourceName: z.string(),
    connectorKey: z.string(),
    runType: z.string(),
    status: z.string(),
    startedAtIso: nullableString,
    completedAtIso: nullableString,
    messagesReceived: z.number(),
    messagesSucceeded: z.number(),
    messagesFailed: z.number(),
    messagesSkipped: z.number(),
    hasError: z.boolean(),
  })),
  watermarks: z.array(z.object({
    watermarkId: z.number(),
    sourceName: z.string(),
    connectorKey: z.string(),
    scopeType: z.string(),
    scopeKeyConfigured: z.boolean(),
    watermarkKind: z.string(),
    cursorStored: z.boolean(),
    lastSuccessAtIso: nullableString,
  })),
  deadLetters: z.array(z.object({
    deadLetterId: z.number(),
    sourceName: nullableString,
    failureStage: z.string(),
    reasonCode: z.string(),
    status: z.string(),
    createdAtIso: nullableString,
    resolvedAtIso: nullableString,
    replayedAtIso: nullableString,
  })),
  projectionErrors: z.array(z.object({
    projectionErrorId: z.number(),
    projectorKey: z.string(),
    errorCode: z.string(),
    status: z.string(),
    createdAtIso: nullableString,
  })),
  replayJobs: z.array(z.object({
    replayJobId: z.number(),
    sourceId: z.number().nullable(),
    replayType: z.string(),
    status: z.string(),
    dryRun: z.boolean(),
    eventsReplayed: z.number(),
    eventsFailed: z.number(),
    startedAtIso: nullableString,
    completedAtIso: nullableString,
    createdAtIso: nullableString,
    hasError: z.boolean(),
  })),
  writebackDrafts: z.array(z.object({
    writebackDraftId: z.number(),
    targetSystem: z.string(),
    resourceType: z.string(),
    draftType: z.string(),
    status: z.string(),
    approvalId: z.number().nullable(),
    createdAtIso: nullableString,
    approvedAtIso: nullableString,
    sentAtIso: nullableString,
  })),
  credentials: z.array(z.object({
    credentialId: z.string(),
    sourceCredentialId: z.number().nullable(),
    sourceId: z.number(),
    sourceName: z.string(),
    credentialKey: z.string(),
    credentialType: z.string(),
    status: z.string(),
    credentialState: z.string(),
    credentialVersionId: z.number().nullable(),
    credentialVersionNumber: z.number().nullable(),
    secretReferenceConfigured: z.boolean(),
    secretProviderScheme: nullableString,
    certificateReferenceConfigured: z.boolean(),
    certificateProviderScheme: nullableString,
    jwksConfigured: z.boolean(),
    clientIdConfigured: z.boolean(),
    tokenEndpointConfigured: z.boolean(),
    rotatesAtIso: nullableString,
    validFromIso: nullableString,
    expiresAtIso: nullableString,
    rotationOverlapEndsAtIso: nullableString,
    revokedAtIso: nullableString,
    lastUsedAtIso: nullableString,
    validationStatus: z.string(),
    rotationState: z.string(),
    validationErrorCode: nullableString,
    validatedAtIso: nullableString,
    providerVersion: nullableString,
    providerLeaseExpiresAtIso: nullableString,
    certificateChainLength: z.number().nullable(),
    certificateExpiresAtIso: nullableString,
    certificateFingerprintSha256: nullableString,
    owner: nullableString,
  })),
  secretProviders: z.array(secretProviderSchema),
  networkRoutes: z.array(networkRouteSchema.extend({ sourceName: z.string() })),
  templates: z.object({
    playbooks: z.array(z.object({
      vendorKey: z.string(),
      label: z.string(),
      systemClass: z.string(),
      status: z.string(),
      capabilities: z.record(z.string(), z.unknown()),
      implementationSteps: z.array(z.string()),
    })),
    coexistenceAdapters: z.array(z.object({
      adapterKey: z.string(),
      label: z.string(),
      vendorKey: z.string(),
      status: z.string(),
    })),
  }),
  configurationAudits: z.array(z.object({
    auditId: z.number(),
    actorUserId: z.number().nullable(),
    action: z.string(),
    entityType: z.string(),
    entityId: z.number().nullable(),
    entityKey: nullableString,
    correlationId: z.string(),
    createdAtIso: nullableString,
  })),
  governedChanges: z.array(z.object({
    changeRequestUuid: z.string(),
    actionType: z.string(),
    subjectType: z.string(),
    subjectId: z.string(),
    authorUserId: z.number(),
    organizationId: z.number().nullable(),
    facilityId: z.number().nullable(),
    sourceId: z.number().nullable(),
    status: z.string(),
    requestedAtIso: nullableString,
    expiresAtIso: nullableString,
    decidedByUserId: z.number().nullable(),
    decidedAtIso: nullableString,
    executedAtIso: nullableString,
  })),
  audit: z.object({
    provenanceRecords: z.number(),
    identityLinks: z.number(),
    patientMergeEvents: z.number(),
    changeAuditAvailable: z.boolean(),
  }),
});

export type IntegrationControlPlane = z.infer<typeof integrationControlPlaneSchema>;
export type FhirResourceProfile = z.infer<typeof fhirResourceProfileSchema>;
export type FhirConformance = z.infer<typeof fhirConformanceSchema>;
export type FhirResourceProfileInput = {
  canonical_profile_url?: string | null;
  canonical_profile_version?: string | null;
  poll_enabled: boolean;
  cadence_minutes: number;
  page_size: number;
  page_limit: number;
  resource_limit: number;
  reason: string;
};
export type IntegrationSource = z.infer<typeof sourceSchema>;
export type SecretProviderCapability = z.infer<typeof secretProviderSchema>;
export type CredentialVersion = z.infer<typeof credentialVersionSchema>;
export type CredentialValidation = z.infer<typeof credentialValidationSchema>;
export type NetworkRoute = z.infer<typeof networkRouteSchema>;

export interface IntegrationSourceInput {
  source_key?: string;
  source_name: string;
  vendor?: string | null;
  system_class: string;
  environment: string;
  base_url?: string | null;
  interface_type: string;
  active_status?: string;
  fhir_version?: string | null;
  smart_supported?: boolean;
  bulk_supported?: boolean;
  subscriptions_supported?: boolean;
  contract_status: string;
  baa_status: string;
  phi_allowed?: boolean;
  go_live_status?: string;
  owner?: string | null;
  expected_cadence_minutes?: number | null;
  expected_configuration_version_id?: number;
  change_reason?: string;
}

const sourceConfigurationVersionSchema = z.object({
  configurationVersionId: z.number(),
  sourceId: z.number(),
  versionNumber: z.number(),
  previousVersionId: z.number().nullable(),
  configurationSha256: z.string(),
  changeKind: z.string(),
  changeReason: z.string(),
  createdByUserId: z.number().nullable(),
  createdAtIso: nullableString,
  isEffective: z.boolean(),
  configuration: z.object({
    organizationId: z.number().nullable(), facilityId: z.number().nullable(), sourceName: nullableString,
    vendor: nullableString, systemClass: nullableString, environment: nullableString,
    baseUrlConfigured: z.boolean(), baseUrlOrigin: nullableString, interfaceType: nullableString,
    fhirVersion: nullableString, usCoreVersion: nullableString, smartSupported: z.boolean(),
    bulkSupported: z.boolean(), subscriptionsSupported: z.boolean(), contractStatus: nullableString,
    baaStatus: nullableString, phiAllowed: z.boolean(), owner: nullableString,
    expectedCadenceMinutes: z.number().nullable(),
  }),
  changedFields: z.array(z.string()),
});

const sourceLifecycleEventSchema = z.object({
  lifecycleEventId: z.number(), eventUuid: z.string(), sourceId: z.number(),
  configurationVersionId: z.number(), configurationVersionNumber: z.number(), configurationSha256: z.string(),
  fromState: nullableString, toState: z.string(), reason: z.string(), changedByUserId: z.number().nullable(),
  governedChangeRequestUuid: nullableString, occurredAtIso: nullableString,
});

const sourceStatusFacetsSchema = z.object({
  sourceId: z.number(),
  lifecycle: z.object({ state: z.string(), changedAtIso: nullableString, governed: z.boolean() }),
  protocolHealth: z.object({ status: z.string(), observedAtIso: nullableString, readOnly: z.boolean() }),
  dataFreshness: z.object({ stale: z.boolean(), freshUntilIso: nullableString, digestStatus: z.string(), readOnly: z.boolean() }),
  conformance: z.object({ status: z.string(), profileKey: nullableString, profileVersion: nullableString, governed: z.boolean() }),
  contract: z.object({ status: z.string(), expiresAtIso: nullableString, expired: z.boolean(), governed: z.boolean() }),
  incident: z.object({ status: z.string(), operatorUpdatable: z.boolean() }),
  history: z.object({
    conformance: z.array(z.record(z.string(), z.unknown())),
    contract: z.array(z.record(z.string(), z.unknown())),
    incident: z.array(z.record(z.string(), z.unknown())),
  }),
});

export type SourceConfigurationVersion = z.infer<typeof sourceConfigurationVersionSchema>;
export type SourceLifecycleEvent = z.infer<typeof sourceLifecycleEventSchema>;
export type SourceStatusFacets = z.infer<typeof sourceStatusFacetsSchema>;

export interface ConformanceFacetInput {
  status: 'not_started' | 'in_progress' | 'passed' | 'failed' | 'waived';
  profile_key?: string | null;
  profile_version?: string | null;
  reason: string;
}

export interface ContractFacetInput {
  status: 'none' | 'pending' | 'active' | 'expired';
  evidence_record_id?: number | null;
  reason: string;
}

export interface IncidentFacetInput {
  status: 'none' | 'open' | 'monitoring' | 'resolved';
  breach_uuid?: string | null;
  reason: string;
}

const onboardingContactSchema = z.object({
  role: nullableString,
  name: nullableString,
  email: nullableString,
  phone: nullableString,
});

const maintenanceWindowSchema = z.object({
  weekday: z.number().nullable(),
  start_local: nullableString,
  duration_minutes: z.number().nullable(),
  purpose: nullableString,
});

const onboardingProfileSchema = z.object({
  onboardingVersionId: z.number(), onboardingVersionUuid: z.string(), sourceId: z.number(),
  versionNumber: z.number(), previousVersionId: z.number().nullable(), systemVersion: nullableString,
  protocolProfile: nullableString, ownerName: nullableString, stewardName: nullableString,
  networkRouteKey: nullableString, dataClassification: z.string(), permittedPurpose: nullableString,
  phiPermissionBasis: nullableString, retentionPolicyKey: nullableString, retentionDays: z.number().nullable(),
  credentialStrategy: nullableString, conformanceStatus: z.string(), supportEntitlement: z.string(),
  vendorSupportIdentifier: nullableString, maintenanceTimezone: nullableString,
  contacts: z.array(onboardingContactSchema), maintenanceWindows: z.array(maintenanceWindowSchema),
  sloDefinition: z.record(z.string(), z.unknown()), profileSha256: z.string(), changeReason: z.string(),
  createdByUserId: z.number().nullable(), createdAtIso: nullableString,
});

const sourceEvidenceSchema = z.object({
  evidenceRecordId: z.number(), evidenceUuid: z.string(), sourceId: z.number(), evidenceType: z.string(),
  evidenceStatus: z.string(), displayLabel: z.string(), referenceConfigured: z.boolean(),
  referenceFingerprint: z.string(), artifactSha256: nullableString, issuedAtIso: nullableString,
  expiresAtIso: nullableString, supersedesEvidenceId: z.number().nullable(), recordedByUserId: z.number().nullable(),
  reason: z.string(), createdAtIso: nullableString,
}).strict();

const readinessRequirementSchema = z.object({
  code: z.string(), category: z.string(), status: z.enum(['passed', 'failed']), message: z.string(),
});

const sourceReadinessSchema = z.object({
  readinessAssessmentId: z.number().nullable(), assessmentUuid: nullableString, sourceId: z.number(),
  configurationVersionId: z.number(), configurationSha256: z.string(), onboardingVersionId: z.number(),
  onboardingProfileSha256: z.string(), status: z.enum(['ready', 'not_ready']), score: z.number(),
  passedCount: z.number(), requirementCount: z.number(), requirements: z.array(readinessRequirementSchema),
  supportBadges: z.array(z.string()), inputSha256: z.string(), evaluatedForAtIso: z.string(), evaluatedAtIso: z.string(),
});

const activationWindowSchema = z.object({
  activationWindowId: z.number(), activationWindowUuid: z.string(), sourceId: z.number(),
  configurationVersionId: z.number(), onboardingVersionId: z.number(), readinessAssessmentId: z.number(),
  governedChangeRequestUuid: z.string(), status: z.string(), activateAtIso: z.string(), windowEndsAtIso: z.string(),
  requestedTimezone: z.string(), attemptCount: z.number(), maxAttempts: z.number(), lastErrorCode: nullableString,
  reason: z.string(), requestedByUserId: z.number(), scheduledByUserId: z.number().nullable(),
  scheduledAtIso: nullableString, activatedAtIso: nullableString, failedAtIso: nullableString,
  cancelledAtIso: nullableString, cancelledByUserId: z.number().nullable(), cancellationReason: nullableString,
  createdAtIso: nullableString,
});

const sourceOnboardingSnapshotSchema = z.object({
  currentProfile: onboardingProfileSchema,
  profileVersions: z.array(onboardingProfileSchema),
  evidence: z.array(sourceEvidenceSchema),
  readiness: sourceReadinessSchema,
  activationWindows: z.array(activationWindowSchema),
});

const sourceHealthObservationSchema = z.object({
  observationId: z.number(),
  observationUuid: z.string(),
  batchUuid: z.string(),
  correlationUuid: z.string(),
  sloDefinitionId: z.number(),
  status: z.string(),
  protocolStatus: z.string(),
  protocolErrorCode: nullableString,
  maintenanceActive: z.boolean(),
  observedAtIso: z.string(),
  freshUntilIso: z.string(),
  origin: z.string(),
  recordedByUserId: z.number().nullable(),
  summary: z.record(z.string(), z.number()),
  queueState: z.record(z.string(), z.unknown()),
  runtimeState: z.record(z.string(), z.unknown()),
  evidenceSha256: z.string(),
});

const sourceObservabilitySnapshotSchema = z.object({
  sourceId: z.number(),
  current: sourceHealthObservationSchema.extend({ stale: z.boolean() }).nullable(),
  history: z.array(sourceHealthObservationSchema),
  openBreaches: z.array(z.object({
    breachId: z.number(), breachUuid: z.string(), metricKey: z.string(), status: z.string(),
    notificationSuppressed: z.boolean(), openedAtIso: z.string(), lastObservedAtIso: z.string(),
    acknowledged: z.boolean().optional(),
    escalated: z.boolean().optional(),
    incidentLinked: z.boolean().optional(),
    reviewed: z.boolean().optional(),
    events: z.array(z.object({
      eventType: z.string(),
      statusAfter: z.string(),
      reasonCode: z.string(),
      notificationSuppressed: z.boolean(),
      actorUserId: z.number().nullable(),
      incidentLinked: z.boolean(),
      occurredAtIso: z.string(),
      metadata: z.record(z.string(), z.unknown()),
    })).optional(),
  })),
  contract: z.object({
    appendOnly: z.literal(true), externalCallsAllowed: z.literal(false), missingEvidenceStatus: z.literal('unknown'),
  }),
});

export type SourceOnboardingSnapshot = z.infer<typeof sourceOnboardingSnapshotSchema>;
export type SourceOnboardingProfile = z.infer<typeof onboardingProfileSchema>;
export type SourceEvidence = z.infer<typeof sourceEvidenceSchema>;
export type SourceReadiness = z.infer<typeof sourceReadinessSchema>;
export type SourceActivationWindow = z.infer<typeof activationWindowSchema>;
export type SourceObservabilitySnapshot = z.infer<typeof sourceObservabilitySnapshotSchema>;

export interface SourceOnboardingInput {
  expected_onboarding_version_id: number;
  change_reason: string;
  system_version?: string | null;
  protocol_profile?: string | null;
  owner_name?: string | null;
  steward_name?: string | null;
  network_route_key?: string | null;
  data_classification: string;
  permitted_purpose?: string | null;
  phi_permission_basis?: string | null;
  retention_policy_key?: string | null;
  retention_days?: number | null;
  credential_strategy?: string | null;
  conformance_status: string;
  support_entitlement: string;
  vendor_support_identifier?: string | null;
  maintenance_timezone?: string | null;
  contacts: Array<{ role: string; name: string; email?: string | null; phone?: string | null }>;
  maintenance_windows: Array<{ weekday: number; start_local: string; duration_minutes: number; purpose: string }>;
  slo_definition: Record<string, number>;
}

export interface SourceEvidenceInput {
  evidence_type: string;
  evidence_status: string;
  display_label: string;
  reference_uri: string;
  artifact_sha256?: string | null;
  issued_at?: string | null;
  expires_at?: string | null;
  supersedes_evidence_id?: number | null;
  reason: string;
}

const governedChangeRequestSchema = z.object({
  changeRequestUuid: z.string(), action: z.string(), subjectType: z.string(), subjectId: z.string(),
  requestedAt: nullableString, expiresAt: nullableString, status: z.literal('pending_approval'),
});

export interface IntegrationEndpointInput {
  endpoint_type?: string;
  url?: string;
  auth_type?: string | null;
  tls_mode?: string | null;
  is_active?: boolean;
  owner?: string | null;
  expected_cadence_minutes?: number | null;
}

export interface IntegrationCredentialInput {
  credential_key: string;
  credential_type: string;
  secret_ref?: string | null;
  certificate_ref?: string | null;
  jwks_uri?: string | null;
  rotates_at?: string | null;
  valid_from?: string | null;
  expires_at?: string | null;
  rotation_overlap_ends_at?: string | null;
  is_active?: boolean;
  owner?: string | null;
  change_reason?: string;
}

export type CredentialRotationInput = Partial<Pick<IntegrationCredentialInput,
  'credential_type' | 'secret_ref' | 'certificate_ref' | 'jwks_uri' | 'rotates_at'
  | 'valid_from' | 'expires_at' | 'rotation_overlap_ends_at' | 'is_active'>>;

export interface NetworkRouteInput {
  route_key: string;
  source_endpoint_id?: number | null;
  transport: 'public_internet' | 'vpn' | 'private_link' | 'direct_connect' | 'interface_engine';
  hostname: string;
  port: number;
  proxy_url?: string | null;
  dns_policy: 'public_only' | 'allowlist' | 'private_only';
  allowed_ip_cidrs?: string[];
  egress_policy_key: string;
  mtls_required?: boolean;
  client_credential_id?: number | null;
  server_name?: string | null;
  change_reason: string;
}

export interface IntegrationReplayInput {
  source_id: number;
  from: string;
  to: string;
  event_types?: string[];
  limit?: number;
}

const queuedRunSchema = z.object({
  runId: z.number(), runUuid: z.string(), status: z.string(), created: z.boolean(),
  resourceType: z.string().optional(),
});

const replayPreviewSchema = z.object({
  eligibleEvents: z.number(),
  totalMatchingEvents: z.number(),
  truncated: z.boolean(),
  oldestAtIso: nullableString,
  newestAtIso: nullableString,
  byEventType: z.array(z.object({ eventType: z.string(), count: z.number() })),
  scope: z.object({
    sourceId: z.number().nullable(), from: z.string(), to: z.string(),
    eventTypes: z.array(z.string()), projectionStatuses: z.array(z.string()), limit: z.number(),
  }),
  mutation: z.literal(false),
});

const queuedReplaySchema = z.object({
  replayJobId: z.number(), replayUuid: z.string(), status: z.string(), created: z.boolean(),
});

const requestedReplaySchema = z.object({
  changeRequestUuid: z.string(),
  action: z.string(),
  status: z.literal('pending_approval'),
  expiresAt: nullableString,
  preview: replayPreviewSchema,
});

export type IntegrationReplayPreview = z.infer<typeof replayPreviewSchema>;

function parseEnvelope<T>(schema: z.ZodType<T>, payload: unknown): T {
  const envelope = z.object({ data: z.unknown() }).parse(payload);

  return schema.parse(envelope.data);
}

export async function fetchIntegrationControlPlane(): Promise<IntegrationControlPlane> {
  const response = await axios.get('/api/admin/integrations/control-plane');
  return z.object({ data: integrationControlPlaneSchema }).parse(response.data).data;
}

export async function createIntegrationSource(input: IntegrationSourceInput): Promise<z.infer<typeof configuredSourceSchema>> {
  const response = await axios.post('/api/admin/integrations/sources', input);
  return parseEnvelope(configuredSourceSchema, response.data);
}

export async function updateIntegrationSource(sourceId: number, input: Partial<Omit<IntegrationSourceInput, 'source_key'>>): Promise<z.infer<typeof configuredSourceSchema>> {
  const response = await axios.patch(`/api/admin/integrations/sources/${sourceId}`, input);
  return parseEnvelope(configuredSourceSchema, response.data);
}

export async function fetchSourceConfigurationVersions(sourceId: number): Promise<SourceConfigurationVersion[]> {
  const response = await axios.get(`/api/admin/integrations/sources/${sourceId}/configuration-versions`);
  return parseEnvelope(z.array(sourceConfigurationVersionSchema), response.data);
}

export async function fetchSourceLifecycleEvents(sourceId: number): Promise<SourceLifecycleEvent[]> {
  const response = await axios.get(`/api/admin/integrations/sources/${sourceId}/lifecycle-events`);
  return parseEnvelope(z.array(sourceLifecycleEventSchema), response.data);
}

export async function fetchSourceOnboarding(sourceId: number): Promise<SourceOnboardingSnapshot> {
  const response = await axios.get(`/api/admin/integrations/sources/${sourceId}/onboarding`);
  return parseEnvelope(sourceOnboardingSnapshotSchema, response.data);
}

export async function fetchSourceStatusFacets(sourceId: number): Promise<SourceStatusFacets> {
  const response = await axios.get(`/api/admin/integrations/sources/${sourceId}/status-facets`);
  return parseEnvelope(sourceStatusFacetsSchema, response.data);
}

export async function fetchFhirConformance(sourceId: number): Promise<FhirConformance> {
  const response = await axios.get(`/api/admin/integrations/sources/${sourceId}/fhir/conformance`);
  return parseEnvelope(fhirConformanceSchema, response.data);
}

export async function recordConformanceFacet(sourceId: number, input: ConformanceFacetInput): Promise<unknown> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/status-facets/conformance`, input);
  return parseEnvelope(z.object({ facet: z.string(), status: z.string() }).passthrough(), response.data);
}

export async function recordContractFacet(sourceId: number, input: ContractFacetInput): Promise<unknown> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/status-facets/contract`, input);
  return parseEnvelope(z.object({ facet: z.string(), status: z.string() }).passthrough(), response.data);
}

export async function recordIncidentFacet(sourceId: number, input: IncidentFacetInput): Promise<unknown> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/status-facets/incident`, input);
  return parseEnvelope(z.object({ facet: z.string(), status: z.string() }).passthrough(), response.data);
}

export async function fetchSourceObservability(sourceId: number): Promise<SourceObservabilitySnapshot> {
  const response = await axios.get(`/api/admin/integrations/sources/${sourceId}/observability`);
  return parseEnvelope(sourceObservabilitySnapshotSchema, response.data);
}

export async function collectSourceObservation(sourceId: number): Promise<unknown> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/observations`);
  return parseEnvelope(z.object({
    observationId: z.number(), observationUuid: z.string(), sourceId: z.number(), status: z.string(),
    maintenanceActive: z.boolean(), observedAtIso: z.string(), evidenceSha256: z.string(),
  }).passthrough(), response.data);
}

const breachActionResultSchema = z.object({
  breachUuid: z.string(), sourceId: z.number(), metricKey: z.string(),
  eventType: z.string(), statusAfter: z.string(), reasonCode: z.string(), occurredAtIso: z.string(),
}).passthrough();

export interface BreachReviewInput {
  root_cause_code: string;
  corrective_action_code: string;
  recurrence_risk: 'low' | 'medium' | 'high';
}

export async function acknowledgeSloBreach(sourceId: number, breachUuid: string, reasonCode: string): Promise<unknown> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/slo-breaches/${breachUuid}/acknowledge`, { reason_code: reasonCode });
  return parseEnvelope(breachActionResultSchema, response.data);
}

export async function escalateSloBreach(sourceId: number, breachUuid: string, reasonCode: string): Promise<unknown> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/slo-breaches/${breachUuid}/escalate`, { reason_code: reasonCode });
  return parseEnvelope(breachActionResultSchema, response.data);
}

export async function linkSloBreachIncident(sourceId: number, breachUuid: string, incidentReference: string): Promise<unknown> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/slo-breaches/${breachUuid}/incident-link`, { incident_reference: incidentReference });
  return parseEnvelope(breachActionResultSchema, response.data);
}

export async function reviewSloBreach(sourceId: number, breachUuid: string, input: BreachReviewInput): Promise<unknown> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/slo-breaches/${breachUuid}/review`, input);
  return parseEnvelope(breachActionResultSchema, response.data);
}

export async function createSourceOnboardingVersion(sourceId: number, input: SourceOnboardingInput): Promise<SourceOnboardingProfile> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/onboarding-versions`, input);
  return parseEnvelope(onboardingProfileSchema, response.data);
}

export async function createSourceEvidence(sourceId: number, input: SourceEvidenceInput): Promise<SourceEvidence> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/evidence`, input);
  return parseEnvelope(sourceEvidenceSchema, response.data);
}

export async function assessSourceReadiness(sourceId: number, evaluatedForAt?: string): Promise<SourceReadiness> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/readiness-assessments`, {
    evaluated_for_at: evaluatedForAt,
  });
  return parseEnvelope(sourceReadinessSchema, response.data);
}

export async function proposeSourceConfiguration(
  sourceId: number,
  input: Partial<Omit<IntegrationSourceInput, 'source_key'>>,
): Promise<SourceConfigurationVersion> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/configuration-versions`, input);
  return parseEnvelope(sourceConfigurationVersionSchema, response.data);
}

export async function requestSourceConfigurationApplication(
  sourceId: number,
  configurationVersionId: number,
  reason: string,
) {
  const response = await axios.post(
    `/api/admin/integrations/sources/${sourceId}/configuration-versions/${configurationVersionId}/application-requests`,
    { reason },
  );
  return parseEnvelope(governedChangeRequestSchema, response.data);
}

export async function transitionSourceLifecycle(sourceId: number, toState: string, reason: string) {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/lifecycle-transitions`, {
    to_state: toState,
    reason,
  });
  return parseEnvelope(configuredSourceSchema, response.data);
}

export async function requestSourceActivation(sourceId: number, reason: string) {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/activation-requests`, { reason });
  return parseEnvelope(governedChangeRequestSchema, response.data);
}

export async function requestScheduledSourceActivation(
  sourceId: number,
  input: { activate_at: string; window_ends_at: string; requested_timezone: string; reason: string },
) {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/activation-window-requests`, input);
  return parseEnvelope(governedChangeRequestSchema.extend({ activationWindow: activationWindowSchema }), response.data);
}

export async function cancelSourceActivationWindow(sourceId: number, windowUuid: string, reason: string) {
  const response = await axios.post(
    `/api/admin/integrations/sources/${sourceId}/activation-windows/${windowUuid}/cancel`,
    { reason },
  );
  return parseEnvelope(activationWindowSchema, response.data);
}

export async function retireIntegrationSource(sourceId: number, reason: string): Promise<z.infer<typeof configuredSourceSchema>> {
  const response = await axios.delete(`/api/admin/integrations/sources/${sourceId}`, { data: { reason } });
  return parseEnvelope(configuredSourceSchema, response.data);
}

export async function createIntegrationEndpoint(sourceId: number, input: IntegrationEndpointInput): Promise<z.infer<typeof configuredEndpointSchema>> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/endpoints`, input);
  return parseEnvelope(configuredEndpointSchema, response.data);
}

export async function updateIntegrationEndpoint(sourceId: number, endpointId: number, input: IntegrationEndpointInput): Promise<z.infer<typeof configuredEndpointSchema>> {
  const response = await axios.patch(`/api/admin/integrations/sources/${sourceId}/endpoints/${endpointId}`, input);
  return parseEnvelope(configuredEndpointSchema, response.data);
}

export async function deleteIntegrationEndpoint(sourceId: number, endpointId: number): Promise<void> {
  await axios.delete(`/api/admin/integrations/sources/${sourceId}/endpoints/${endpointId}`);
}

export async function createIntegrationCredential(sourceId: number, input: IntegrationCredentialInput): Promise<z.infer<typeof configuredCredentialSchema>> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/credentials`, input);
  return parseEnvelope(configuredCredentialSchema, response.data);
}

export async function updateIntegrationCredential(sourceId: number, credentialId: number, input: Partial<IntegrationCredentialInput>): Promise<z.infer<typeof configuredCredentialSchema>> {
  const response = await axios.patch(`/api/admin/integrations/sources/${sourceId}/credentials/${credentialId}`, input);
  return parseEnvelope(configuredCredentialSchema, response.data);
}

export async function deleteIntegrationCredential(sourceId: number, credentialId: number, reason: string): Promise<void> {
  await axios.delete(`/api/admin/integrations/sources/${sourceId}/credentials/${credentialId}`, { data: { reason } });
}

export async function fetchCredentialVersions(sourceId: number, credentialId: number): Promise<CredentialVersion[]> {
  const response = await axios.get(`/api/admin/integrations/sources/${sourceId}/credentials/${credentialId}/versions`);
  return parseEnvelope(z.array(credentialVersionSchema), response.data);
}

export async function validateCredential(sourceId: number, credentialId: number, evaluatedForAt?: string): Promise<CredentialValidation> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/credentials/${credentialId}/validations`, {
    evaluated_for_at: evaluatedForAt,
  });
  return parseEnvelope(credentialValidationSchema, response.data);
}

export async function requestCredentialRotation(
  sourceId: number,
  credentialId: number,
  input: CredentialRotationInput,
  reason: string,
) {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/credentials/${credentialId}/rotation-requests`, {
    ...input,
    reason,
  });
  return parseEnvelope(governedChangeRequestSchema, response.data);
}

export async function executeCredentialRotation(
  changeRequestUuid: string,
  sourceId: number,
  credentialId: number,
  input: CredentialRotationInput,
) {
  const response = await axios.post(
    `/api/admin/integrations/governed-changes/${changeRequestUuid}/sources/${sourceId}/credentials/${credentialId}/execute-rotation`,
    input,
  );
  return parseEnvelope(configuredCredentialSchema, response.data);
}

export async function fetchNetworkRoutes(sourceId: number): Promise<NetworkRoute[]> {
  const response = await axios.get(`/api/admin/integrations/sources/${sourceId}/network-routes`);
  return parseEnvelope(z.array(networkRouteSchema), response.data);
}

export async function createNetworkRoute(sourceId: number, input: NetworkRouteInput): Promise<NetworkRoute> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/network-routes`, input);
  return parseEnvelope(networkRouteSchema, response.data);
}

export async function updateNetworkRoute(sourceId: number, routeId: number, input: Partial<NetworkRouteInput>): Promise<NetworkRoute> {
  const response = await axios.patch(`/api/admin/integrations/sources/${sourceId}/network-routes/${routeId}`, input);
  return parseEnvelope(networkRouteSchema, response.data);
}

export async function validateNetworkRoute(sourceId: number, routeId: number): Promise<NetworkRoute> {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/network-routes/${routeId}/validations`);
  return parseEnvelope(networkRouteSchema, response.data);
}

export async function retireNetworkRoute(sourceId: number, routeId: number, reason: string): Promise<void> {
  await axios.delete(`/api/admin/integrations/sources/${sourceId}/network-routes/${routeId}`, { data: { reason } });
}

export async function queueIntegrationHealthCheck(sourceId: number) {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/health-check`);
  return parseEnvelope(queuedRunSchema, response.data);
}

export async function queueFhirPoll(sourceId: number, resourceType: string) {
  const response = await axios.post(`/api/admin/integrations/sources/${sourceId}/fhir/poll`, { resource_type: resourceType });
  return parseEnvelope(queuedRunSchema, response.data);
}

export async function configureFhirResourceProfile(sourceId: number, resourceType: string, input: FhirResourceProfileInput): Promise<FhirResourceProfile> {
  const response = await axios.put(`/api/admin/integrations/sources/${sourceId}/fhir/resource-profiles/${encodeURIComponent(resourceType)}`, input);
  return parseEnvelope(fhirResourceProfileSchema, response.data);
}

export async function retireFhirResourceProfile(sourceId: number, profileId: number, reason: string): Promise<FhirResourceProfile> {
  const response = await axios.delete(`/api/admin/integrations/sources/${sourceId}/fhir/resource-profiles/${profileId}`, { data: { reason } });
  return parseEnvelope(fhirResourceProfileSchema, response.data);
}

export async function previewIntegrationReplay(input: IntegrationReplayInput) {
  const response = await axios.post('/api/admin/integrations/enterprise/replays/preview', input);
  return parseEnvelope(replayPreviewSchema, response.data);
}

export async function requestIntegrationReplay(input: IntegrationReplayInput, reason: string) {
  const response = await axios.post('/api/admin/integrations/enterprise/replays/requests', { ...input, reason });
  return parseEnvelope(requestedReplaySchema, response.data);
}

export async function queueIntegrationReplay(input: IntegrationReplayInput, changeRequestUuid: string, idempotencyKey: string) {
  const response = await axios.post('/api/admin/integrations/enterprise/replays', {
    ...input,
    change_request_uuid: changeRequestUuid,
  }, {
    headers: { 'Idempotency-Key': idempotencyKey },
  });
  return parseEnvelope(queuedReplaySchema, response.data);
}
