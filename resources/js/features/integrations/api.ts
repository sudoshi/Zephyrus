import axios from 'axios';
import { z } from 'zod';

const nullableString = z.string().nullable();

const sourceSchema = z.object({
  sourceId: z.number(),
  sourceKey: z.string(),
  sourceName: z.string(),
  vendor: nullableString,
  systemClass: z.string(),
  environment: z.string(),
  interfaceType: z.string(),
  configuredStatus: z.string(),
  healthStatus: z.string(),
  goLiveStatus: z.string(),
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
  facilityKey: nullableString, vendor: nullableString, systemClass: z.string(), environment: z.string(),
  interfaceType: z.string(), activeStatus: z.string(), fhirVersion: nullableString, usCoreVersion: nullableString,
  smartSupported: z.boolean(), bulkSupported: z.boolean(), subscriptionsSupported: z.boolean(),
  contractStatus: z.string(), baaStatus: z.string(), phiAllowed: z.boolean(), goLiveStatus: z.string(),
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
  status: z.string(), secretReferenceConfigured: z.boolean(), certificateReferenceConfigured: z.boolean(),
  jwksConfigured: z.boolean(), rotatesAtIso: nullableString, owner: nullableString,
});

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
    endpoints: z.number(),
    capabilities: z.number(),
    credentials: z.number(),
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
    sourceName: z.string(),
    connectionKey: z.string(),
    status: z.string(),
    fhirVersion: nullableString,
    capabilityCheckedAtIso: nullableString,
    baseUrlConfigured: z.boolean(),
    supportedResourceCount: z.number(),
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
    replayType: z.string(),
    status: z.string(),
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
    secretReferenceConfigured: z.boolean(),
    certificateReferenceConfigured: z.boolean(),
    jwksConfigured: z.boolean(),
    clientIdConfigured: z.boolean(),
    tokenEndpointConfigured: z.boolean(),
    rotatesAtIso: nullableString,
    owner: nullableString,
  })),
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
  audit: z.object({
    provenanceRecords: z.number(),
    identityLinks: z.number(),
    patientMergeEvents: z.number(),
    changeAuditAvailable: z.boolean(),
  }),
});

export type IntegrationControlPlane = z.infer<typeof integrationControlPlaneSchema>;
export type IntegrationSource = z.infer<typeof sourceSchema>;

export interface IntegrationSourceInput {
  source_key?: string;
  source_name: string;
  tenant_key: string;
  facility_key?: string | null;
  vendor?: string | null;
  system_class: string;
  environment: string;
  base_url?: string | null;
  interface_type: string;
  active_status: string;
  fhir_version?: string | null;
  smart_supported?: boolean;
  bulk_supported?: boolean;
  subscriptions_supported?: boolean;
  contract_status: string;
  baa_status: string;
  phi_allowed?: boolean;
  go_live_status: string;
  owner?: string | null;
  expected_cadence_minutes?: number | null;
}

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
  is_active?: boolean;
  owner?: string | null;
}

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

export async function retireIntegrationSource(sourceId: number): Promise<z.infer<typeof configuredSourceSchema>> {
  const response = await axios.delete(`/api/admin/integrations/sources/${sourceId}`);
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

export async function deleteIntegrationCredential(sourceId: number, credentialId: number): Promise<void> {
  await axios.delete(`/api/admin/integrations/sources/${sourceId}/credentials/${credentialId}`);
}
