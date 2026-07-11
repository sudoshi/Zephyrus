import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  createIntegrationCredential,
  createIntegrationEndpoint,
  createIntegrationSource,
  deleteIntegrationCredential,
  deleteIntegrationEndpoint,
  fetchIntegrationControlPlane,
  previewIntegrationReplay,
  queueEpicFhirPoll,
  queueIntegrationHealthCheck,
  queueIntegrationReplay,
  retireIntegrationSource,
  updateIntegrationEndpoint,
  updateIntegrationCredential,
  updateIntegrationSource,
  type IntegrationCredentialInput,
  type IntegrationEndpointInput,
  type IntegrationSourceInput,
  type IntegrationReplayInput,
} from './api';

const controlPlaneKey = ['admin', 'integrations', 'control-plane'] as const;

export function useIntegrationControlPlane() {
  return useQuery({
    queryKey: controlPlaneKey,
    queryFn: fetchIntegrationControlPlane,
    refetchInterval: 60_000,
  });
}

function useRefreshingMutation<TInput, TOutput>(mutationFn: (input: TInput) => Promise<TOutput>) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: controlPlaneKey }),
  });
}

export function useCreateIntegrationSource() {
  return useRefreshingMutation((input: IntegrationSourceInput) => createIntegrationSource(input));
}

export function useUpdateIntegrationSource() {
  return useRefreshingMutation(({ sourceId, input }: { sourceId: number; input: Partial<Omit<IntegrationSourceInput, 'source_key'>> }) => updateIntegrationSource(sourceId, input));
}

export function useRetireIntegrationSource() {
  return useRefreshingMutation((sourceId: number) => retireIntegrationSource(sourceId));
}

export function useCreateIntegrationEndpoint() {
  return useRefreshingMutation(({ sourceId, input }: { sourceId: number; input: IntegrationEndpointInput }) => createIntegrationEndpoint(sourceId, input));
}

export function useUpdateIntegrationEndpoint() {
  return useRefreshingMutation(({ sourceId, endpointId, input }: { sourceId: number; endpointId: number; input: IntegrationEndpointInput }) => updateIntegrationEndpoint(sourceId, endpointId, input));
}

export function useDeleteIntegrationEndpoint() {
  return useRefreshingMutation(({ sourceId, endpointId }: { sourceId: number; endpointId: number }) => deleteIntegrationEndpoint(sourceId, endpointId));
}

export function useCreateIntegrationCredential() {
  return useRefreshingMutation(({ sourceId, input }: { sourceId: number; input: IntegrationCredentialInput }) => createIntegrationCredential(sourceId, input));
}

export function useUpdateIntegrationCredential() {
  return useRefreshingMutation(({ sourceId, credentialId, input }: { sourceId: number; credentialId: number; input: Partial<IntegrationCredentialInput> }) => updateIntegrationCredential(sourceId, credentialId, input));
}

export function useDeleteIntegrationCredential() {
  return useRefreshingMutation(({ sourceId, credentialId }: { sourceId: number; credentialId: number }) => deleteIntegrationCredential(sourceId, credentialId));
}

export function useQueueIntegrationHealthCheck() {
  return useRefreshingMutation((sourceId: number) => queueIntegrationHealthCheck(sourceId));
}

export function useQueueEpicFhirPoll() {
  return useRefreshingMutation(({ sourceId, resourceType }: { sourceId: number; resourceType: 'Encounter' | 'Location' }) => queueEpicFhirPoll(sourceId, resourceType));
}

export function usePreviewIntegrationReplay() {
  return useMutation({ mutationFn: (input: IntegrationReplayInput) => previewIntegrationReplay(input) });
}

export function useQueueIntegrationReplay() {
  return useRefreshingMutation(({ input, idempotencyKey }: { input: IntegrationReplayInput; idempotencyKey: string }) => queueIntegrationReplay(input, idempotencyKey));
}
