import { useQuery } from '@tanstack/react-query';
import {
  fetchCapabilityMatrix,
  fetchFacility,
  fetchFacilitySpaces,
  fetchOrganization,
  fetchOrganizations,
  fetchReadiness,
  fetchServiceLineCatalog,
  fetchTransfers,
} from './api';

// The registry rarely changes — hold it longer than the react-query default.
export function useServiceLineCatalog() {
  return useQuery({ queryKey: ['deployment', 'catalog'], queryFn: fetchServiceLineCatalog, staleTime: 30 * 60 * 1000 });
}

export function useOrganizations() {
  return useQuery({ queryKey: ['deployment', 'organizations'], queryFn: fetchOrganizations });
}

export function useOrganization(key: string | null) {
  return useQuery({
    queryKey: ['deployment', 'organization', key],
    queryFn: () => fetchOrganization(key as string),
    enabled: !!key,
  });
}

export function useFacility(facilityKey: string | null) {
  return useQuery({
    queryKey: ['deployment', 'facility', facilityKey],
    queryFn: () => fetchFacility(facilityKey as string),
    enabled: !!facilityKey,
  });
}

export function useFacilitySpaces(facilityKey: string | null) {
  return useQuery({
    queryKey: ['deployment', 'facility', facilityKey, 'spaces'],
    queryFn: () => fetchFacilitySpaces(facilityKey as string),
    enabled: !!facilityKey,
  });
}

export function useCapabilityMatrix(facilityKey: string | null) {
  return useQuery({
    queryKey: ['deployment', 'facility', facilityKey, 'matrix'],
    queryFn: () => fetchCapabilityMatrix(facilityKey as string),
    enabled: !!facilityKey,
  });
}

export function useReadiness(facilityKey: string | null) {
  return useQuery({
    queryKey: ['deployment', 'facility', facilityKey, 'readiness'],
    queryFn: () => fetchReadiness(facilityKey as string),
    enabled: !!facilityKey,
  });
}

export function useTransfers(params: { facility?: string; service_line?: string; direction?: string } = {}) {
  return useQuery({
    queryKey: ['deployment', 'transfers', params],
    queryFn: () => fetchTransfers(params),
    enabled: !!params.facility,
  });
}
