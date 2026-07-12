import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { irSuiteSchema, modalityUtilizationSchema, radiologyFlowBoardSchema, radiologyReadsSchema, radiologyTatSchema, radiologyWorklistSchema, type IrSuite, type ModalityUtilization, type RadiologyFlowBoard, type RadiologyReads, type RadiologyTat, type RadiologyWorklist } from './schemas';

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

export function useRadiologyTat(initialData: RadiologyTat) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['radiology-tat', search],
    initialData,
    queryFn: async () => radiologyTatSchema.parse((await axios.get(`/api/radiology/tat${search}`)).data),
    refetchInterval: 60_000,
  });
}

export function useIrSuite(initialData: IrSuite) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['ir-suite-utilization', search],
    initialData,
    queryFn: async () => irSuiteSchema.parse((await axios.get(`/api/radiology/ir-utilization${search}`)).data),
    refetchInterval: 60_000,
  });
}
