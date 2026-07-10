import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  assignStaffingRequest,
  createStaffingRequest,
  fetchStaffingOverview,
  fetchStaffingRequests,
  fetchStaffingWorkforce,
  updateStaffingStatus,
} from './api';
import type { AssignStaffingRequestInput, CreateStaffingRequestInput, StaffingRequestStatus, StaffingWorkforceFilters } from './types';

export function useStaffingOverview() {
  return useQuery({ queryKey: ['staffing', 'overview'], queryFn: fetchStaffingOverview });
}

export function useStaffingRequests(status?: StaffingRequestStatus) {
  return useQuery({
    queryKey: ['staffing', 'requests', status ?? 'all'],
    queryFn: () => fetchStaffingRequests(status ? { status } : {}),
  });
}

export function useStaffingWorkforce(filters: StaffingWorkforceFilters = {}) {
  return useQuery({
    queryKey: ['staffing', 'workforce', filters],
    queryFn: () => fetchStaffingWorkforce(filters),
  });
}

export function useCreateStaffingRequest() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (input: CreateStaffingRequestInput) => createStaffingRequest(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['staffing'] }),
  });
}

export function useAssignStaffingRequest() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: AssignStaffingRequestInput }) => assignStaffingRequest(id, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['staffing'] }),
  });
}

export function useUpdateStaffingStatus() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ id, status }: { id: number; status: StaffingRequestStatus }) => updateStaffingStatus(id, status),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['staffing'] }),
  });
}
