import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  assignTransportRequest,
  createTransportRequest,
  fetchTransportOverview,
  fetchTransportRequests,
  fetchTransportResources,
  fetchTransportVendors,
  updateTransportStatus,
} from './api';
import type { CreateTransportRequestInput, TransportRequestType, TransportStatus } from './types';

export function useTransportOverview() {
  return useQuery({ queryKey: ['transport', 'overview'], queryFn: fetchTransportOverview });
}

export function useTransportRequests(requestType?: TransportRequestType) {
  return useQuery({
    queryKey: ['transport', 'requests', requestType ?? 'all'],
    queryFn: () => fetchTransportRequests(requestType ? { request_type: requestType } : {}),
  });
}

export function useTransportResources() {
  return useQuery({ queryKey: ['transport', 'resources'], queryFn: fetchTransportResources });
}

export function useTransportVendors() {
  return useQuery({ queryKey: ['transport', 'vendors'], queryFn: fetchTransportVendors });
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
    mutationFn: ({ id, assignedTeam, assignedVendor }: { id: number; assignedTeam?: string; assignedVendor?: string }) =>
      assignTransportRequest(id, { assigned_team: assignedTeam, assigned_vendor: assignedVendor }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['transport'] });
    },
  });
}

export function useUpdateTransportStatus() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ id, status }: { id: number; status: TransportStatus }) => updateTransportStatus(id, status),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['transport'] });
    },
  });
}
