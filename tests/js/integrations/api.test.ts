import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  createIntegrationCredential,
  configureFhirResourceProfile,
  createNetworkRoute,
  executeCredentialRotation,
  fetchCredentialVersions,
  fetchFhirConformance,
  fetchNetworkRoutes,
  createSourceEvidence,
  fetchSourceOnboarding,
  fetchSourceObservability,
  collectSourceObservation,
  fetchIntegrationControlPlane,
  previewIntegrationReplay,
  queueFhirPoll,
  queueIntegrationHealthCheck,
  queueIntegrationReplay,
  requestScheduledSourceActivation,
  requestCredentialRotation,
  retireFhirResourceProfile,
  updateIntegrationCredential,
  validateCredential,
  validateNetworkRoute,
} from '@/features/integrations/api';

vi.mock('axios');
const mocked = vi.mocked(axios, true);

const emptySnapshot = {
  generatedAtIso: '2026-07-09T12:00:00+00:00',
  status: 'not_configured',
  freshnessPolicy: { freshAfterMinutes: 15, staleAfterMinutes: 60 },
  counts: {
    sources: 0,
    activeSources: 0,
    healthySources: 0,
    degradedSources: 0,
    staleSources: 0,
    failedSources: 0,
    protocolHealthySources: 0,
    protocolDegradedSources: 0,
    protocolFailedSources: 0,
    endpoints: 0,
    capabilities: 0,
    credentials: 0,
    credentialVersions: 0,
    credentialValidations: 0,
    networkRoutes: 0,
    blockedNetworkRoutes: 0,
    interfaceEngines: 0,
    fhirConnections: 0,
    smartCredentials: 0,
    connectorTemplates: 0,
    coexistenceAdapters: 0,
    ingestRuns: 0,
    inboundMessages: 0,
    canonicalEvents: 0,
    pendingProjectionEvents: 0,
    openDeadLetters: 0,
    openProjectionErrors: 0,
    replayJobs: 0,
    terminologyMaps: 0,
    writebackDrafts: 0,
    configurationAudits: 0,
    configurationVersions: 0,
    lifecycleEvents: 0,
    healthObservations: 0,
    openSloBreaches: 0,
    queuedJobs: 0,
    failedQueueJobs: 0,
    pendingGovernedChanges: 0,
  },
  sources: [],
  endpoints: [],
  fhirConnections: [],
  hl7Interfaces: [],
  connectorFamilies: [],
  mappings: { approved: 0, pendingReview: 0, byType: [] },
  runs: [],
  watermarks: [],
  deadLetters: [],
  projectionErrors: [],
  replayJobs: [],
  writebackDrafts: [],
  credentials: [],
  secretProviders: [],
  networkRoutes: [],
  templates: { playbooks: [], coexistenceAdapters: [] },
  configurationAudits: [],
  governedChanges: [],
  audit: {
    provenanceRecords: 0,
    identityLinks: 0,
    patientMergeEvents: 0,
    changeAuditAvailable: false,
  },
};

describe('integration control-plane API', () => {
  beforeEach(() => vi.clearAllMocks());

  it('validates the monitoring response', async () => {
    mocked.get.mockResolvedValue({ data: { data: emptySnapshot } });

    const snapshot = await fetchIntegrationControlPlane();

    expect(snapshot.status).toBe('not_configured');
    expect(snapshot.counts.sources).toBe(0);
    expect(mocked.get).toHaveBeenCalledWith('/api/admin/integrations/control-plane');
  });

  it('accepts governed dynamic FHIR resource profiles in the control-plane contract', async () => {
    mocked.get.mockResolvedValue({ data: { data: {
      ...emptySnapshot,
      fhirConnections: [{
        connectionId: 4,
        sourceId: 3,
        sourceName: 'Enterprise FHIR R4',
        connectionKey: 'fhir-r4-primary',
        status: 'ready',
        fhirVersion: '4.0.1',
        capabilityCheckedAtIso: '2026-07-15T12:00:00Z',
        healthStatus: 'healthy',
        healthCheckedAtIso: '2026-07-15T12:00:00Z',
        healthErrorCode: null,
        baseUrlConfigured: true,
        supportedResourceCount: 3,
        resourceProfiles: [{
          profileId: 14,
          resourceType: 'Observation',
          canonicalProfileUrl: 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-observation-clinical-result',
          canonicalProfileVersion: '7.0.0',
          status: 'enabled',
          pollEnabled: true,
          pollingInteraction: 'search',
          cadenceMinutes: 5,
          pageSize: 100,
          pageLimit: 10,
          resourceLimit: 1000,
          versionNumber: 2,
          changeReason: 'Enable the approved Observation polling profile.',
        }],
      }],
    } } });

    const snapshot = await fetchIntegrationControlPlane();

    expect(snapshot.fhirConnections[0].resourceProfiles).toEqual([{
      profileId: 14,
      resourceType: 'Observation',
      canonicalProfileUrl: 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-observation-clinical-result',
      canonicalProfileVersion: '7.0.0',
      status: 'enabled',
      pollEnabled: true,
      pollingInteraction: 'search',
      cadenceMinutes: 5,
      pageSize: 100,
      pageLimit: 10,
      resourceLimit: 1000,
      versionNumber: 2,
      changeReason: 'Enable the approved Observation polling profile.',
    }]);
  });

  it('validates the source-scoped, PHI-safe FHIR conformance projection', async () => {
    mocked.get.mockResolvedValue({ data: { data: {
      status: 'passed', sourceId: 3, connectionId: 4, observationId: 22,
      observedAtIso: '2026-07-15T12:00:00Z', fhirVersion: '4.0.1',
      capabilityKind: 'instance', capabilityStatus: 'active', capabilityDateIso: '2026-07-15T12:00:00Z',
      softwareName: 'Enterprise FHIR', softwareVersion: '2026.1', implementationOrigin: 'https://fhir.example.test',
      formats: ['json'], patchFormats: [], implementationGuides: [], systemInteractions: ['batch'],
      systemOperations: [], compartments: [], securityServices: [],
      supportsBatch: true, supportsTransaction: false, supportsSystemHistory: false, supportsSystemSearch: false,
      supportsBulkData: false, supportsSubscriptions: false,
      resourceCount: 1, searchableResourceCount: 1, searchParameterCount: 1, operationCount: 0, warnings: [],
      documentHashes: { capabilityStatement: 'a'.repeat(64), smartConfiguration: 'b'.repeat(64) },
      smart: {
        issuerOrigin: null, jwksOrigin: null, authorizationOrigin: null,
        tokenOrigin: 'https://auth.example.test', registrationOrigin: null, managementOrigin: null,
        introspectionOrigin: null, grantTypes: ['client_credentials'], tokenAuthMethods: ['private_key_jwt'],
        tokenSigningAlgorithms: ['RS384'], scopes: ['system/Observation.rs'],
        capabilities: ['client-confidential-asymmetric'], pkceMethods: [], associatedEndpoints: [],
      },
      resources: [{
        resourceType: 'Observation', baseProfileUrl: 'http://hl7.org/fhir/StructureDefinition/Observation',
        supportedProfiles: [], interactions: ['read', 'search-type'], versioning: 'versioned',
        readHistory: true, updateCreate: false, conditionalCreate: null, conditionalRead: null,
        conditionalUpdate: null, conditionalDelete: null, searchIncludes: [], searchRevIncludes: [],
        searchParameters: [{ name: '_id', definition: 'http://hl7.org/fhir/SearchParameter/Resource-id', type: 'token' }],
        operations: [],
      }],
    } } });

    const conformance = await fetchFhirConformance(3);

    expect(conformance.status).toBe('passed');
    if (conformance.status === 'unobserved') throw new Error('Expected observed conformance evidence.');
    expect(conformance.resources[0].interactions).toContain('search-type');
    expect(conformance.smart.tokenOrigin).toBe('https://auth.example.test');
    expect(mocked.get).toHaveBeenCalledWith('/api/admin/integrations/sources/3/fhir/conformance');
  });

  it('configures and retires a governed dynamic FHIR resource profile', async () => {
    const profile = {
      profileId: 14,
      sourceId: 3,
      resourceType: 'Observation',
      canonicalProfileUrl: null,
      canonicalProfileVersion: null,
      status: 'configured',
      pollEnabled: true,
      pollingInteraction: 'search',
      cadenceMinutes: 5,
      pageSize: 100,
      pageLimit: 10,
      resourceLimit: 1000,
      versionNumber: 1,
      changeReason: 'Configure approved Observation polling.',
    };
    mocked.put.mockResolvedValueOnce({ data: { data: profile } });
    mocked.delete.mockResolvedValueOnce({ data: { data: {
      ...profile,
      status: 'retired',
      pollEnabled: false,
      versionNumber: 2,
      changeReason: 'Retire the superseded Observation profile.',
    } } });

    await configureFhirResourceProfile(3, 'Observation', {
      canonical_profile_url: null,
      canonical_profile_version: null,
      poll_enabled: true,
      polling_interaction: 'search',
      cadence_minutes: 5,
      page_size: 100,
      page_limit: 10,
      resource_limit: 1000,
      reason: 'Configure approved Observation polling.',
    });
    const retired = await retireFhirResourceProfile(3, 14, 'Retire the superseded Observation profile.');

    expect(retired.status).toBe('retired');
    expect(mocked.put).toHaveBeenCalledWith('/api/admin/integrations/sources/3/fhir/resource-profiles/Observation', expect.objectContaining({ cadence_minutes: 5, polling_interaction: 'search' }));
    expect(mocked.delete).toHaveBeenCalledWith('/api/admin/integrations/sources/3/fhir/resource-profiles/14', { data: { reason: 'Retire the superseded Observation profile.' } });
  });

  it('rejects a drifted security-sensitive contract', async () => {
    mocked.get.mockResolvedValue({
      data: {
        data: {
          ...emptySnapshot,
          credentials: [{
            credentialId: 'source:1',
            sourceName: 'Epic',
            credentialKey: 'backend',
            credentialType: 'smart_backend_services',
            status: 'configured',
            secretReferenceConfigured: 'vault://raw-secret',
          }],
        },
      },
    });

    await expect(fetchIntegrationControlPlane()).rejects.toThrow();
  });

  it('validates append-only source observability history and manual collection', async () => {
    mocked.get.mockResolvedValueOnce({ data: { data: {
      sourceId: 7,
      current: {
        observationId: 12, observationUuid: '019f5bea-a90d-7078-8dc6-c8c3a54ea8f8',
        batchUuid: '019f5bea-a90d-7078-8dc6-c8c3a54ea8f9', correlationUuid: '019f5bea-a90d-7078-8dc6-c8c3a54ea8fa',
        sloDefinitionId: 3, status: 'unknown', protocolStatus: 'healthy', protocolErrorCode: null,
        maintenanceActive: false, observedAtIso: '2026-07-13T14:00:00+00:00',
        freshUntilIso: '2026-07-13T14:03:00+00:00', origin: 'scheduled', recordedByUserId: null,
        summary: { met: 5, breached: 0, unknown: 2, not_applicable: 0 },
        queueState: { backpressureStatus: 'normal', sourceActiveRunDepth: 0 },
        runtimeState: { circuitBreaker: { state: 'unknown' } }, evidenceSha256: 'a'.repeat(64), stale: false,
      },
      history: [], openBreaches: [],
      contract: { appendOnly: true, externalCallsAllowed: false, missingEvidenceStatus: 'unknown' },
    } } });
    const snapshot = await fetchSourceObservability(7);
    expect(snapshot.current?.summary.unknown).toBe(2);
    expect(snapshot.contract.missingEvidenceStatus).toBe('unknown');
    expect(mocked.get).toHaveBeenCalledWith('/api/admin/integrations/sources/7/observability');

    mocked.post.mockResolvedValueOnce({ data: { data: {
      observationId: 13, observationUuid: '019f5bea-a90d-7078-8dc6-c8c3a54ea8fb',
      sourceId: 7, status: 'unknown', maintenanceActive: false,
      observedAtIso: '2026-07-13T14:01:00+00:00', evidenceSha256: 'b'.repeat(64),
    } } });
    const collected = await collectSourceObservation(7) as { observationId: number };
    expect(collected.observationId).toBe(13);
    expect(mocked.post).toHaveBeenCalledWith('/api/admin/integrations/sources/7/observations');
  });

  it('validates canonical SMART credential mutation responses', async () => {
    const configured = {
      credentialId: 9,
      sourceId: 3,
      credentialKey: 'smart-backend',
      credentialType: 'smart_backend_services',
      status: 'configured',
      credentialState: 'active',
      currentCredentialVersionId: 14,
      secretReferenceConfigured: true,
      certificateReferenceConfigured: false,
      jwksConfigured: false,
      rotatesAtIso: null,
      validFromIso: '2026-07-13T12:00:00Z',
      expiresAtIso: null,
      rotationOverlapEndsAtIso: null,
      revokedAtIso: null,
      lastUsedAtIso: null,
      owner: 'Security',
    };
    mocked.post.mockResolvedValue({ data: { data: configured } });
    mocked.patch.mockResolvedValue({ data: { data: { ...configured, status: 'disabled' } } });

    const created = await createIntegrationCredential(3, {
      credential_key: 'smart-backend',
      credential_type: 'smart_backend_services',
      secret_ref: 'vault://zephyrus/epic/key',
    });
    const updated = await updateIntegrationCredential(3, 9, { is_active: false });

    expect(created.credentialType).toBe('smart_backend_services');
    expect(updated.status).toBe('disabled');
    expect(mocked.patch).toHaveBeenCalledWith('/api/admin/integrations/sources/3/credentials/9', { is_active: false });
  });

  it('validates secret-safe credential authority and network route contracts', async () => {
    const version = {
      credentialVersionId: 14, credentialVersionUuid: '019f0000-0000-7000-8000-000000000014',
      credentialId: 9, sourceId: 3, versionNumber: 1, previousVersionId: null,
      credentialType: 'smart_backend_services', credentialState: 'active',
      secretReferenceConfigured: true, secretProviderScheme: 'vault', certificateReferenceConfigured: false,
      certificateProviderScheme: null, jwksConfigured: false, validFromIso: '2026-07-13T12:00:00Z',
      expiresAtIso: null, rotatesAtIso: null, rotationOverlapEndsAtIso: null,
      authoritySha256: 'a'.repeat(64), changeReason: 'Initialize governed authority.',
      governedChangeRequestUuid: null, createdAtIso: '2026-07-13T12:00:00Z',
    };
    const validation = {
      credentialValidationObservationId: 4, credentialId: 9, credentialVersionId: 14,
      credentialVersionNumber: 1, status: 'ready', rotationState: 'current', providerScheme: 'vault',
      providerVersion: '7', providerLeaseExpiresAtIso: null, certificateMetadata: {}, requirements: [{
        code: 'provider.secret_access', status: 'passed', message: 'Provider returned the reference.',
      }], inputSha256: 'b'.repeat(64), errorCode: null, evaluatedForAtIso: '2026-07-13T12:00:00Z',
    };
    const route = {
      networkRouteId: 6, sourceId: 3, endpointId: 8, routeKey: 'epic-fhir-primary',
      environment: 'production', transport: 'public_internet', hostname: 'fhir.vendor.example', port: 443,
      proxyConfigured: false, proxyOrigin: null, dnsPolicy: 'public_only', allowedIpCidrs: [],
      egressPolicyKey: 'integration-https-egress', mtlsRequired: false, clientCredentialId: null,
      serverName: 'fhir.vendor.example', status: 'validated', changeReason: 'Authorize exact egress.',
      lastAddressCount: 2, lastErrorCode: null, lastObservedAtIso: '2026-07-13T12:00:00Z',
      policySha256: 'c'.repeat(64),
    };
    mocked.get
      .mockResolvedValueOnce({ data: { data: [version] } })
      .mockResolvedValueOnce({ data: { data: [route] } });
    mocked.post
      .mockResolvedValueOnce({ data: { data: validation } })
      .mockResolvedValueOnce({ data: { data: route } })
      .mockResolvedValueOnce({ data: { data: route } });

    expect((await fetchCredentialVersions(3, 9))[0].secretProviderScheme).toBe('vault');
    expect((await validateCredential(3, 9)).providerVersion).toBe('7');
    expect((await fetchNetworkRoutes(3))[0].lastAddressCount).toBe(2);
    await createNetworkRoute(3, {
      route_key: 'epic-fhir-primary', source_endpoint_id: 8, transport: 'public_internet',
      hostname: 'fhir.vendor.example', port: 443, dns_policy: 'public_only', allowed_ip_cidrs: [],
      egress_policy_key: 'integration-https-egress', change_reason: 'Authorize exact egress.',
    });
    await validateNetworkRoute(3, 6);

    expect(mocked.post).toHaveBeenNthCalledWith(1, '/api/admin/integrations/sources/3/credentials/9/validations', { evaluated_for_at: undefined });
    expect(mocked.post).toHaveBeenNthCalledWith(3, '/api/admin/integrations/sources/3/network-routes/6/validations');
  });

  it('binds credential rotation request and execution to the exact target payload', async () => {
    const changeRequestUuid = '019f0000-0000-7000-8000-000000000099';
    const input = {
      secret_ref: 'vault://clinical/epic/backend-v2',
      valid_from: '2026-07-20T02:00',
      rotation_overlap_ends_at: '2026-07-20T03:00',
    };
    mocked.post
      .mockResolvedValueOnce({ data: { data: {
        changeRequestUuid, action: 'rotate_integration_credential', subjectType: 'integration_credential',
        subjectId: '3:9', requestedAt: '2026-07-13T12:00:00Z', expiresAt: '2026-07-20T12:00:00Z',
        status: 'pending_approval',
      } } })
      .mockResolvedValueOnce({ data: { data: {
        credentialId: 9, sourceId: 3, credentialKey: 'smart-backend', credentialType: 'smart_backend_services',
        status: 'rotating', credentialState: 'rotating', currentCredentialVersionId: 15,
        secretReferenceConfigured: true, certificateReferenceConfigured: false, jwksConfigured: false,
        rotatesAtIso: null, validFromIso: '2026-07-20T02:00:00Z', expiresAtIso: null,
        rotationOverlapEndsAtIso: '2026-07-20T03:00:00Z', revokedAtIso: null, lastUsedAtIso: null, owner: 'Security',
      } } });

    await requestCredentialRotation(3, 9, input, 'Rotate after independent provider validation.');
    await executeCredentialRotation(changeRequestUuid, 3, 9, input);

    expect(mocked.post).toHaveBeenNthCalledWith(1, '/api/admin/integrations/sources/3/credentials/9/rotation-requests', {
      ...input, reason: 'Rotate after independent provider validation.',
    });
    expect(mocked.post).toHaveBeenNthCalledWith(2, `/api/admin/integrations/governed-changes/${changeRequestUuid}/sources/3/credentials/9/execute-rotation`, input);
  });

  it('queues governed protocol, poll, and replay operations with their required contracts', async () => {
    mocked.post
      .mockResolvedValueOnce({ data: { data: { runId: 10, runUuid: 'run-health', status: 'queued', created: true } } })
      .mockResolvedValueOnce({ data: { data: { runId: 11, runUuid: 'run-poll', status: 'queued', created: true, resourceType: 'Encounter' } } })
      .mockResolvedValueOnce({ data: { data: {
        eligibleEvents: 2, totalMatchingEvents: 2, truncated: false,
        oldestAtIso: '2026-07-10T10:00:00Z', newestAtIso: '2026-07-10T11:00:00Z',
        byEventType: [{ eventType: 'EncounterStarted', count: 2 }],
        scope: {
          sourceId: 3, from: '2026-07-10T09:00:00Z', to: '2026-07-10T12:00:00Z',
          eventTypes: ['EncounterStarted'], projectionStatuses: ['pending', 'failed'], limit: 50,
        },
        mutation: false,
      } } })
      .mockResolvedValueOnce({ data: { data: { replayJobId: 12, replayUuid: 'replay-uuid', status: 'queued', created: true } } });

    await queueIntegrationHealthCheck(3);
    await queueFhirPoll(3, 'Encounter');
    const input = { source_id: 3, from: '2026-07-10T09:00:00Z', to: '2026-07-10T12:00:00Z', limit: 50 };
    const preview = await previewIntegrationReplay(input);
    await queueIntegrationReplay(input, '019f0000-0000-7000-8000-000000000001', 'replay-key-1');

    expect(preview.mutation).toBe(false);
    expect(mocked.post).toHaveBeenNthCalledWith(1, '/api/admin/integrations/sources/3/health-check');
    expect(mocked.post).toHaveBeenNthCalledWith(2, '/api/admin/integrations/sources/3/fhir/poll', { resource_type: 'Encounter' });
    expect(mocked.post).toHaveBeenNthCalledWith(4, '/api/admin/integrations/enterprise/replays', {
      ...input,
      change_request_uuid: '019f0000-0000-7000-8000-000000000001',
    }, {
      headers: { 'Idempotency-Key': 'replay-key-1' },
    });
  });

  it('validates the onboarding, evidence, readiness, and scheduled activation contracts', async () => {
    const profile = {
      onboardingVersionId: 4, onboardingVersionUuid: '019f0000-0000-7000-8000-000000000004', sourceId: 3,
      versionNumber: 2, previousVersionId: 2, systemVersion: '2026.1', protocolProfile: 'FHIR R4',
      ownerName: 'Owner', stewardName: 'Steward', networkRouteKey: 'private-link',
      dataClassification: 'restricted_phi', permittedPurpose: 'Clinical operations', phiPermissionBasis: 'BAA',
      retentionPolicyKey: 'seven-year', retentionDays: 2555, credentialStrategy: 'oauth2',
      conformanceStatus: 'passed', supportEntitlement: 'critical', vendorSupportIdentifier: 'SUP-1',
      maintenanceTimezone: 'America/New_York', contacts: [], maintenanceWindows: [], sloDefinition: {},
      profileSha256: 'a'.repeat(64), changeReason: 'Complete onboarding evidence.', createdByUserId: 1,
      createdAtIso: '2026-07-13T12:00:00Z',
    };
    const readiness = {
      readinessAssessmentId: null, assessmentUuid: null, sourceId: 3, configurationVersionId: 8,
      configurationSha256: 'b'.repeat(64), onboardingVersionId: 4, onboardingProfileSha256: 'a'.repeat(64),
      status: 'ready', score: 100, passedCount: 1, requirementCount: 1,
      requirements: [{ code: 'profile.slo', category: 'operations', status: 'passed', message: 'SLO complete.' }],
      supportBadges: ['production-certified'], inputSha256: 'c'.repeat(64),
      evaluatedForAtIso: '2026-07-13T13:00:00Z', evaluatedAtIso: '2026-07-13T12:00:00Z',
    };
    const activationWindow = {
      activationWindowId: 9, activationWindowUuid: '019f0000-0000-7000-8000-000000000009', sourceId: 3,
      configurationVersionId: 8, onboardingVersionId: 4, readinessAssessmentId: 6,
      governedChangeRequestUuid: '019f0000-0000-7000-8000-000000000010', status: 'pending_approval',
      activateAtIso: '2026-07-13T13:00:00Z', windowEndsAtIso: '2026-07-13T14:00:00Z',
      requestedTimezone: 'America/New_York', attemptCount: 0, maxAttempts: 3, lastErrorCode: null,
      reason: 'Reviewed scheduled activation.', requestedByUserId: 1, scheduledByUserId: null,
      scheduledAtIso: null, activatedAtIso: null, failedAtIso: null, createdAtIso: '2026-07-13T12:00:00Z',
      cancelledAtIso: null, cancelledByUserId: null, cancellationReason: null,
    };
    mocked.get.mockResolvedValue({ data: { data: {
      currentProfile: profile, profileVersions: [profile], evidence: [], readiness, activationWindows: [],
    } } });
    mocked.post.mockResolvedValueOnce({ data: { data: {
      changeRequestUuid: activationWindow.governedChangeRequestUuid,
      action: 'schedule_production_source_activation', subjectType: 'source_activation_window',
      subjectId: activationWindow.activationWindowUuid, requestedAt: '2026-07-13T12:00:00Z',
      expiresAt: '2026-07-20T12:00:00Z', status: 'pending_approval', activationWindow,
    } } });

    const snapshot = await fetchSourceOnboarding(3);
    const request = await requestScheduledSourceActivation(3, {
      activate_at: activationWindow.activateAtIso,
      window_ends_at: activationWindow.windowEndsAtIso,
      requested_timezone: activationWindow.requestedTimezone,
      reason: activationWindow.reason,
    });

    expect(snapshot.readiness.status).toBe('ready');
    expect(request.activationWindow.configurationVersionId).toBe(8);
    expect(mocked.post).toHaveBeenCalledWith('/api/admin/integrations/sources/3/activation-window-requests', {
      activate_at: activationWindow.activateAtIso,
      window_ends_at: activationWindow.windowEndsAtIso,
      requested_timezone: activationWindow.requestedTimezone,
      reason: activationWindow.reason,
    });
  });

  it('rejects evidence responses that expose the raw repository reference', async () => {
    mocked.post.mockResolvedValue({ data: { data: {
      evidenceRecordId: 1, evidenceUuid: '019f0000-0000-7000-8000-000000000001', sourceId: 3,
      evidenceType: 'contract', evidenceStatus: 'verified', displayLabel: 'Contract',
      referenceConfigured: true, referenceFingerprint: 'abcdef0123456789',
      referenceUri: 'https://evidence.example/secret/path', artifactSha256: null,
      issuedAtIso: null, expiresAtIso: null, supersedesEvidenceId: null,
      recordedByUserId: 1, reason: 'Verified contract evidence.', createdAtIso: '2026-07-13T12:00:00Z',
    } } });

    await expect(createSourceEvidence(3, {
      evidence_type: 'contract', evidence_status: 'verified', display_label: 'Contract',
      reference_uri: 'https://evidence.example/secret/path', reason: 'Verified contract evidence.',
    })).rejects.toThrow();
  });
});
