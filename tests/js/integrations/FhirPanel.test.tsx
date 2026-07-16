import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { FhirPanel } from '@/Pages/Integrations/Index';
import {
  useConfigureFhirResourceProfile,
  useFhirConformance,
  useQueueFhirPoll,
  useQueueIntegrationHealthCheck,
  useRetireFhirResourceProfile,
} from '@/features/integrations/hooks';
import type { IntegrationControlPlane } from '@/features/integrations/api';

vi.mock('@/features/integrations/hooks', () => ({
  useConfigureFhirResourceProfile: vi.fn(),
  useFhirConformance: vi.fn(),
  useQueueFhirPoll: vi.fn(),
  useQueueIntegrationHealthCheck: vi.fn(),
  useRetireFhirResourceProfile: vi.fn(),
}));

const mutation = (mutate = vi.fn()) => ({
  mutate,
  isPending: false,
  isSuccess: false,
  isError: false,
});

const data = {
  credentials: [],
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
      cadenceMinutes: 5,
      pageSize: 100,
      pageLimit: 10,
      resourceLimit: 1000,
      versionNumber: 2,
      changeReason: 'Enable the approved Observation polling profile.',
    }],
  }],
} as IntegrationControlPlane;

describe('FHIR resource profile governance panel', () => {
  const queue = vi.fn();
  const configure = vi.fn();
  const retire = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(useQueueIntegrationHealthCheck).mockReturnValue(mutation() as never);
    vi.mocked(useFhirConformance).mockReturnValue({
      data: { status: 'unobserved', sourceId: 3, resources: [] },
      isLoading: false,
      isError: false,
    } as never);
    vi.mocked(useQueueFhirPoll).mockReturnValue(mutation(queue) as never);
    vi.mocked(useConfigureFhirResourceProfile).mockReturnValue(mutation(configure) as never);
    vi.mocked(useRetireFhirResourceProfile).mockReturnValue(mutation(retire) as never);
  });

  it('renders, polls, edits, saves, and retires a dynamic configured resource', () => {
    render(<FhirPanel data={data} selectedSourceId={3} canManageIntegrations />);

    fireEvent.click(screen.getByRole('button', { name: 'Poll Observation' }));
    expect(queue).toHaveBeenCalledWith({ sourceId: 3, resourceType: 'Observation' });

    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
    expect(screen.getByLabelText('Resource type')).toHaveValue('Observation');
    expect(screen.getByLabelText('Cadence minutes')).toHaveValue(5);
    fireEvent.change(screen.getByLabelText('Change reason'), {
      target: { value: 'Approve the revised Observation polling cadence.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save profile' }));
    expect(configure).toHaveBeenCalledWith(expect.objectContaining({
      sourceId: 3,
      resourceType: 'Observation',
      input: expect.objectContaining({ cadence_minutes: 5, poll_enabled: true }),
    }), expect.any(Object));

    fireEvent.click(screen.getByRole('button', { name: 'Retire' }));
    expect(retire).toHaveBeenCalledWith({
      sourceId: 3,
      profileId: 14,
      reason: 'Approve the revised Observation polling cadence.',
    });
  });

  it('renders immutable CapabilityStatement and SMART discovery evidence without endpoint paths', () => {
    vi.mocked(useFhirConformance).mockReturnValue({
      data: {
        status: 'passed',
        sourceId: 3,
        connectionId: 4,
        observationId: 22,
        observedAtIso: '2026-07-15T12:00:00Z',
        fhirVersion: '4.0.1',
        capabilityKind: 'instance',
        capabilityStatus: 'active',
        capabilityDateIso: '2026-07-15T12:00:00Z',
        softwareName: 'Enterprise FHIR',
        softwareVersion: '2026.1',
        implementationOrigin: 'https://fhir.example.test',
        formats: ['json'],
        patchFormats: [],
        implementationGuides: ['http://hl7.org/fhir/us/core/ImplementationGuide/hl7.fhir.us.core'],
        systemInteractions: ['batch', 'transaction'],
        systemOperations: [],
        compartments: [],
        securityServices: [{ system: 'http://terminology.hl7.org/CodeSystem/restful-security-service', code: 'SMART-on-FHIR' }],
        supportsBatch: true,
        supportsTransaction: true,
        supportsSystemHistory: false,
        supportsSystemSearch: false,
        supportsBulkData: false,
        supportsSubscriptions: false,
        resourceCount: 1,
        searchableResourceCount: 1,
        searchParameterCount: 1,
        operationCount: 1,
        warnings: [],
        documentHashes: { capabilityStatement: 'a'.repeat(64), smartConfiguration: 'b'.repeat(64) },
        smart: {
          issuerOrigin: null,
          jwksOrigin: null,
          authorizationOrigin: null,
          tokenOrigin: 'https://auth.example.test',
          registrationOrigin: null,
          managementOrigin: null,
          introspectionOrigin: null,
          grantTypes: ['client_credentials'],
          tokenAuthMethods: ['private_key_jwt'],
          tokenSigningAlgorithms: ['RS384'],
          scopes: ['system/Observation.rs'],
          capabilities: ['client-confidential-asymmetric'],
          pkceMethods: [],
          associatedEndpoints: [],
        },
        resources: [{
          resourceType: 'Observation',
          baseProfileUrl: 'http://hl7.org/fhir/StructureDefinition/Observation',
          supportedProfiles: [],
          interactions: ['read', 'search-type'],
          versioning: 'versioned',
          readHistory: true,
          updateCreate: false,
          conditionalCreate: null,
          conditionalRead: null,
          conditionalUpdate: null,
          conditionalDelete: null,
          searchIncludes: ['Observation:subject'],
          searchRevIncludes: [],
          searchParameters: [{ name: '_id', definition: 'http://hl7.org/fhir/SearchParameter/Resource-id', type: 'token' }],
          operations: [{ name: 'meta', definition: 'http://hl7.org/fhir/OperationDefinition/Resource-meta' }],
        }],
      },
      isLoading: false,
      isError: false,
    } as never);

    render(<FhirPanel data={data} selectedSourceId={3} canManageIntegrations />);

    expect(screen.getByText('Discovered FHIR + SMART Conformance')).toBeInTheDocument();
    expect(screen.getByText('1 / 1')).toBeInTheDocument();
    expect(screen.getByText('https://auth.example.test')).toBeInTheDocument();
    expect(screen.getAllByText('Observation', { selector: 'td' })).toHaveLength(2);
    expect(screen.queryByText('/oauth2/token')).not.toBeInTheDocument();
  });

  it('loads unobserved evidence for a selected FHIR source before a client connection exists', () => {
    const sourceOnlyData = {
      credentials: [],
      fhirConnections: [],
      sources: [{ sourceId: 3, interfaceType: 'fhir_r4' }],
    } as IntegrationControlPlane;

    render(<FhirPanel data={sourceOnlyData} selectedSourceId={3} canManageIntegrations={false} />);

    expect(useFhirConformance).toHaveBeenCalledWith(3);
    expect(screen.getByText(/No successful CapabilityStatement and SMART discovery has been observed/i)).toBeInTheDocument();
  });
});
