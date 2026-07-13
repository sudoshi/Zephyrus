import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { pharmacyFlowBoardSchema, type PharmacyFlowBoard } from './schemas';

export function usePharmacyFlowBoard(initialData: PharmacyFlowBoard) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['pharmacy-flow-board', search], initialData,
    queryFn: async () => pharmacyFlowBoardSchema.parse((await axios.get(`/api/pharmacy/flow-board${search}`)).data),
    refetchInterval: 30_000,
  });
}
