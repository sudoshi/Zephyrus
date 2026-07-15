import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { anatomicPathologySchema, bloodBankReadinessSchema, labDecisionPendingSchema, labFlowBoardSchema, labSpecimensSchema, labTatSchema, type AnatomicPathology, type BloodBankReadiness, type LabDecisionPending, type LabFlowBoard, type LabSpecimens, type LabTat } from './schemas';

export function useLabFlowBoard(initialData: LabFlowBoard) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['lab-flow-board', search], initialData,
    queryFn: async () => labFlowBoardSchema.parse((await axios.get(`/api/lab/flow-board${search}`)).data),
    refetchInterval: 30_000,
  });
}

export function useLabSpecimens(initialData: LabSpecimens) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['lab-specimens', search], initialData,
    queryFn: async () => labSpecimensSchema.parse((await axios.get(`/api/lab/specimens${search}`)).data),
    refetchInterval: 30_000,
  });
}

export function useLabDecisionPending(initialData: LabDecisionPending) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['lab-decision-pending', search], initialData,
    queryFn: async () => labDecisionPendingSchema.parse((await axios.get(`/api/lab/pending-decisions${search}`)).data),
    refetchInterval: 30_000,
  });
}

export function useBloodBankReadiness(initialData: BloodBankReadiness) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['blood-bank-readiness', search], initialData,
    queryFn: async () => bloodBankReadinessSchema.parse((await axios.get(`/api/lab/blood-bank${search}`)).data),
    refetchInterval: 30_000,
  });
}

export function useAnatomicPathology(initialData: AnatomicPathology) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['anatomic-pathology', search], initialData,
    queryFn: async () => anatomicPathologySchema.parse((await axios.get(`/api/lab/anatomic-path${search}`)).data),
    refetchInterval: 30_000,
  });
}

export function useLabTat(initialData: LabTat) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['lab-tat', search], initialData,
    queryFn: async () => labTatSchema.parse((await axios.get(`/api/lab/tat${search}`)).data),
    refetchInterval: 300_000,
  });
}
