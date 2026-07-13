import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  createIntegrationCredential,
  createNetworkRoute,
  createIntegrationEndpoint,
  createIntegrationSource,
  createSourceEvidence,
  createSourceOnboardingVersion,
  cancelSourceActivationWindow,
  deleteIntegrationCredential,
  deleteIntegrationEndpoint,
  fetchIntegrationControlPlane,
  fetchCredentialVersions,
  fetchNetworkRoutes,
  fetchSourceConfigurationVersions,
  fetchSourceLifecycleEvents,
  fetchSourceOnboarding,
  fetchSourceObservability,
  collectSourceObservation,
  acknowledgeSloBreach,
  escalateSloBreach,
  linkSloBreachIncident,
  reviewSloBreach,
  type BreachReviewInput,
  previewIntegrationReplay,
  proposeSourceConfiguration,
  requestIntegrationReplay,
  requestCredentialRotation,
  requestSourceConfigurationApplication,
  requestSourceActivation,
  requestScheduledSourceActivation,
  assessSourceReadiness,
  transitionSourceLifecycle,
  queueEpicFhirPoll,
  queueIntegrationHealthCheck,
  queueIntegrationReplay,
  retireIntegrationSource,
  retireNetworkRoute,
  updateIntegrationEndpoint,
  updateIntegrationCredential,
  updateNetworkRoute,
  updateIntegrationSource,
  type IntegrationCredentialInput,
  type IntegrationEndpointInput,
  type IntegrationSourceInput,
  type SourceEvidenceInput,
  type SourceOnboardingInput,
  type IntegrationReplayInput,
  type NetworkRouteInput,
  type CredentialRotationInput,
  validateCredential,
  validateNetworkRoute,
} from './api';

const controlPlaneKey = ['admin', 'integrations', 'control-plane'] as const;

export function useIntegrationControlPlane() {
  return useQuery({
    queryKey: controlPlaneKey,
    queryFn: fetchIntegrationControlPlane,
    refetchInterval: 60_000,
  });
}

export function useSourceConfigurationVersions(sourceId: number | null) {
  return useQuery({
    queryKey: [...controlPlaneKey, 'source', sourceId, 'configuration-versions'],
    queryFn: () => fetchSourceConfigurationVersions(sourceId as number),
    enabled: sourceId !== null,
  });
}

export function useSourceLifecycleEvents(sourceId: number | null) {
  return useQuery({
    queryKey: [...controlPlaneKey, 'source', sourceId, 'lifecycle-events'],
    queryFn: () => fetchSourceLifecycleEvents(sourceId as number),
    enabled: sourceId !== null,
  });
}

export function useSourceOnboarding(sourceId: number | null) {
  return useQuery({
    queryKey: [...controlPlaneKey, 'source', sourceId, 'onboarding'],
    queryFn: () => fetchSourceOnboarding(sourceId as number),
    enabled: sourceId !== null,
  });
}

export function useSourceObservability(sourceId: number | null) {
  return useQuery({
    queryKey: [...controlPlaneKey, 'source', sourceId, 'observability'],
    queryFn: () => fetchSourceObservability(sourceId as number),
    enabled: sourceId !== null,
    refetchInterval: 60_000,
  });
}

export function useCollectSourceObservation() {
  return useRefreshingMutation((sourceId: number) => collectSourceObservation(sourceId));
}

export function useAcknowledgeSloBreach() {
  return useRefreshingMutation(({ sourceId, breachUuid, reasonCode }: { sourceId: number; breachUuid: string; reasonCode: string }) => acknowledgeSloBreach(sourceId, breachUuid, reasonCode));
}

export function useEscalateSloBreach() {
  return useRefreshingMutation(({ sourceId, breachUuid, reasonCode }: { sourceId: number; breachUuid: string; reasonCode: string }) => escalateSloBreach(sourceId, breachUuid, reasonCode));
}

export function useLinkSloBreachIncident() {
  return useRefreshingMutation(({ sourceId, breachUuid, incidentReference }: { sourceId: number; breachUuid: string; incidentReference: string }) => linkSloBreachIncident(sourceId, breachUuid, incidentReference));
}

export function useReviewSloBreach() {
  return useRefreshingMutation(({ sourceId, breachUuid, input }: { sourceId: number; breachUuid: string; input: BreachReviewInput }) => reviewSloBreach(sourceId, breachUuid, input));
}

export function useCredentialVersions(sourceId: number | null, credentialId: number | null) {
  return useQuery({
    queryKey: [...controlPlaneKey, 'source', sourceId, 'credential', credentialId, 'versions'],
    queryFn: () => fetchCredentialVersions(sourceId as number, credentialId as number),
    enabled: sourceId !== null && credentialId !== null,
  });
}

export function useNetworkRoutes(sourceId: number | null) {
  return useQuery({
    queryKey: [...controlPlaneKey, 'source', sourceId, 'network-routes'],
    queryFn: () => fetchNetworkRoutes(sourceId as number),
    enabled: sourceId !== null,
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

export function useProposeSourceConfiguration() {
  return useRefreshingMutation(({ sourceId, input }: { sourceId: number; input: Partial<Omit<IntegrationSourceInput, 'source_key'>> }) => proposeSourceConfiguration(sourceId, input));
}

export function useRequestSourceConfigurationApplication() {
  return useRefreshingMutation(({ sourceId, configurationVersionId, reason }: { sourceId: number; configurationVersionId: number; reason: string }) => requestSourceConfigurationApplication(sourceId, configurationVersionId, reason));
}

export function useTransitionSourceLifecycle() {
  return useRefreshingMutation(({ sourceId, toState, reason }: { sourceId: number; toState: string; reason: string }) => transitionSourceLifecycle(sourceId, toState, reason));
}

export function useRequestSourceActivation() {
  return useRefreshingMutation(({ sourceId, reason }: { sourceId: number; reason: string }) => requestSourceActivation(sourceId, reason));
}

export function useCreateSourceOnboardingVersion() {
  return useRefreshingMutation(({ sourceId, input }: { sourceId: number; input: SourceOnboardingInput }) => createSourceOnboardingVersion(sourceId, input));
}

export function useCreateSourceEvidence() {
  return useRefreshingMutation(({ sourceId, input }: { sourceId: number; input: SourceEvidenceInput }) => createSourceEvidence(sourceId, input));
}

export function useAssessSourceReadiness() {
  return useRefreshingMutation(({ sourceId, evaluatedForAt }: { sourceId: number; evaluatedForAt?: string }) => assessSourceReadiness(sourceId, evaluatedForAt));
}

export function useRequestScheduledSourceActivation() {
  return useRefreshingMutation(({ sourceId, input }: {
    sourceId: number;
    input: { activate_at: string; window_ends_at: string; requested_timezone: string; reason: string };
  }) => requestScheduledSourceActivation(sourceId, input));
}

export function useCancelSourceActivationWindow() {
  return useRefreshingMutation(({ sourceId, windowUuid, reason }: {
    sourceId: number;
    windowUuid: string;
    reason: string;
  }) => cancelSourceActivationWindow(sourceId, windowUuid, reason));
}

export function useRetireIntegrationSource() {
  return useRefreshingMutation(({ sourceId, reason }: { sourceId: number; reason: string }) => retireIntegrationSource(sourceId, reason));
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
  return useRefreshingMutation(({ sourceId, credentialId, reason }: { sourceId: number; credentialId: number; reason: string }) => deleteIntegrationCredential(sourceId, credentialId, reason));
}

export function useValidateCredential() {
  return useRefreshingMutation(({ sourceId, credentialId, evaluatedForAt }: { sourceId: number; credentialId: number; evaluatedForAt?: string }) => validateCredential(sourceId, credentialId, evaluatedForAt));
}

export function useRequestCredentialRotation() {
  return useRefreshingMutation(({ sourceId, credentialId, input, reason }: {
    sourceId: number; credentialId: number; input: CredentialRotationInput; reason: string;
  }) => requestCredentialRotation(sourceId, credentialId, input, reason));
}

export function useCreateNetworkRoute() {
  return useRefreshingMutation(({ sourceId, input }: { sourceId: number; input: NetworkRouteInput }) => createNetworkRoute(sourceId, input));
}

export function useUpdateNetworkRoute() {
  return useRefreshingMutation(({ sourceId, routeId, input }: { sourceId: number; routeId: number; input: Partial<NetworkRouteInput> }) => updateNetworkRoute(sourceId, routeId, input));
}

export function useValidateNetworkRoute() {
  return useRefreshingMutation(({ sourceId, routeId }: { sourceId: number; routeId: number }) => validateNetworkRoute(sourceId, routeId));
}

export function useRetireNetworkRoute() {
  return useRefreshingMutation(({ sourceId, routeId, reason }: { sourceId: number; routeId: number; reason: string }) => retireNetworkRoute(sourceId, routeId, reason));
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

export function useRequestIntegrationReplay() {
  return useMutation({ mutationFn: ({ input, reason }: { input: IntegrationReplayInput; reason: string }) => requestIntegrationReplay(input, reason) });
}

export function useQueueIntegrationReplay() {
  return useRefreshingMutation(({ input, changeRequestUuid, idempotencyKey }: { input: IntegrationReplayInput; changeRequestUuid: string; idempotencyKey: string }) => queueIntegrationReplay(input, changeRequestUuid, idempotencyKey));
}
