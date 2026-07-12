import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { labFlowBoardSchema, type LabFlowBoard } from './schemas';

export function useLabFlowBoard(initialData: LabFlowBoard) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['lab-flow-board', search], initialData,
    queryFn: async () => labFlowBoardSchema.parse((await axios.get(`/api/lab/flow-board${search}`)).data),
    refetchInterval: 30_000,
  });
}
