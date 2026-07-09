import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  createIntegrationCredential,
  createIntegrationEndpoint,
  createIntegrationSource,
  deleteIntegrationCredential,
  deleteIntegrationEndpoint,
  fetchIntegrationControlPlane,
  retireIntegrationSource,
  updateIntegrationEndpoint,
  updateIntegrationCredential,
  updateIntegrationSource,
  type IntegrationCredentialInput,
  type IntegrationEndpointInput,
  type IntegrationSourceInput,
} from './api';

const controlPlaneKey = ['admin', 'integrations', 'control-plane'] as const;

export function useIntegrationControlPlane() {
  return useQuery({
    queryKey: controlPlaneKey,
    queryFn: fetchIntegrationControlPlane,
    refetchInterval: 60_000,
  });
}

function useRefreshingMutation<TInput>(mutationFn: (input: TInput) => Promise<unknown>) {
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
