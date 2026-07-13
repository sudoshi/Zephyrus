import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { labDecisionPendingSchema, labFlowBoardSchema, labSpecimensSchema, type LabDecisionPending, type LabFlowBoard, type LabSpecimens } from './schemas';

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
