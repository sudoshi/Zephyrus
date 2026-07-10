import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  assignTransportRequest,
  cancelTransportRequest,
  completeTransportHandoff,
  createEnterpriseWritebackDraft,
  createRegionalTransferAgentDraft,
  createRegionalTransferDecision,
  createTransportRequest,
  discoverEnterpriseFhirCapabilities,
  fetchEnterpriseConnectorSummary,
  fetchRegionalTransferSummary,
  fetchTransportOverview,
  fetchTransportRequests,
  fetchTransportResources,
  fetchTransportVendors,
  runRegionalRouteSimulation,
  updateTransportStatus,
} from './api';
import type {
  CreateEnterpriseWritebackDraftInput,
  CompleteTransportHandoffInput,
  CreateRegionalTransferDecisionInput,
  CreateTransportRequestInput,
  DiscoverEnterpriseFhirInput,
  TransportRequestType,
  TransportStatus,
} from './types';

export function useTransportOverview() {
  return useQuery({ queryKey: ['transport', 'overview'], queryFn: fetchTransportOverview });
}

export function useTransportRequests(requestType?: TransportRequestType, scope: 'active' | 'dispatch' | 'history' = 'active') {
  return useInfiniteQuery({
    queryKey: ['transport', 'requests', requestType ?? 'all', scope],
    initialPageParam: undefined as string | undefined,
    queryFn: ({ pageParam }) => fetchTransportRequests({
      request_type: requestType,
      scope,
      cursor: pageParam,
    }),
    getNextPageParam: (lastPage) => lastPage.meta.next_cursor ?? undefined,
    getPreviousPageParam: (firstPage) => firstPage.meta.previous_cursor ?? undefined,
  });
}

export function useTransportResources() {
  return useQuery({ queryKey: ['transport', 'resources'], queryFn: fetchTransportResources });
}

export function useTransportVendors() {
  return useQuery({ queryKey: ['transport', 'vendors'], queryFn: fetchTransportVendors });
}

export function useEnterpriseConnectorSummary() {
  return useQuery({ queryKey: ['admin', 'integrations', 'enterprise'], queryFn: fetchEnterpriseConnectorSummary });
}

export function useRegionalTransferSummary() {
  return useQuery({ queryKey: ['transport', 'regional-summary'], queryFn: fetchRegionalTransferSummary });
}

export function useCreateTransportRequest() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (input: CreateTransportRequestInput) => createTransportRequest(input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['transport'] });
    },
  });
}

export function useAssignTransportRequest() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ id, resourceKey }: { id: number; resourceKey: string }) =>
      assignTransportRequest(id, { resource_key: resourceKey }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['transport'] });
    },
  });
}

export function useUpdateTransportStatus() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ id, status, reason }: { id: number; status: TransportStatus; reason?: string }) =>
      updateTransportStatus(id, status, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['transport'] });
    },
  });
}

export function useCompleteTransportHandoff() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: CompleteTransportHandoffInput }) =>
      completeTransportHandoff(id, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['transport'] });
    },
  });
}

export function useCancelTransportRequest() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => cancelTransportRequest(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['transport'] });
    },
  });
}

export function useDiscoverEnterpriseFhirCapabilities() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (input: DiscoverEnterpriseFhirInput) => discoverEnterpriseFhirCapabilities(input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'integrations', 'enterprise'] });
    },
  });
}

export function useCreateEnterpriseWritebackDraft() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (input: CreateEnterpriseWritebackDraftInput) => createEnterpriseWritebackDraft(input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'integrations', 'enterprise'] });
      qc.invalidateQueries({ queryKey: ['ops'] });
    },
  });
}

export function useCreateRegionalTransferDecision() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ transportRequestId, input }: { transportRequestId: number; input: CreateRegionalTransferDecisionInput }) =>
      createRegionalTransferDecision(transportRequestId, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['transport'] });
    },
  });
}

export function useRunRegionalRouteSimulation() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (input: { model_version_key?: string } = {}) => runRegionalRouteSimulation(input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['transport'] });
    },
  });
}

export function useCreateRegionalTransferAgentDraft() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (transportRequestId: number) => createRegionalTransferAgentDraft(transportRequestId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['transport'] });
    },
  });
}
