import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { radiologyFlowBoardSchema, radiologyWorklistSchema, type RadiologyFlowBoard, type RadiologyWorklist } from './schemas';

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
