import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { modalityUtilizationSchema, radiologyFlowBoardSchema, radiologyReadsSchema, radiologyWorklistSchema, type ModalityUtilization, type RadiologyFlowBoard, type RadiologyReads, type RadiologyWorklist } from './schemas';

export function useRadiologyFlowBoard(initialData: RadiologyFlowBoard) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['radiology-flow-board', search],
    initialData,
    queryFn: async () => radiologyFlowBoardSchema.parse((await axios.get(`/api/radiology/flow-board${search}`)).data),
    refetchInterval: 30_000,
  });
}

export function useRadiologyWorklist(initialData: RadiologyWorklist) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['radiology-worklist', search],
    initialData,
    queryFn: async () => radiologyWorklistSchema.parse((await axios.get(`/api/radiology/worklist${search}`)).data),
    refetchInterval: 30_000,
  });
}

export function useModalityUtilization(initialData: ModalityUtilization) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['radiology-modality-utilization', search],
    initialData,
    queryFn: async () => modalityUtilizationSchema.parse((await axios.get(`/api/radiology/modality${search}`)).data),
    refetchInterval: 60_000,
  });
}

export function useRadiologyReads(initialData: RadiologyReads) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['radiology-reads', search],
    initialData,
    queryFn: async () => radiologyReadsSchema.parse((await axios.get(`/api/radiology/reads${search}`)).data),
    refetchInterval: 30_000,
  });
}
