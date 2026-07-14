import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { pharmacyFlowBoardSchema, type PharmacyFlowBoard } from './schemas';
import { pharmacyDischargeSchema, type PharmacyDischarge } from './discharge-schemas';

export function usePharmacyFlowBoard(initialData: PharmacyFlowBoard) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['pharmacy-flow-board', search], initialData,
    queryFn: async () => pharmacyFlowBoardSchema.parse((await axios.get(`/api/pharmacy/flow-board${search}`)).data),
    refetchInterval: 30_000,
  });
}

export function usePharmacyDischargeReadiness(initialData: PharmacyDischarge) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['pharmacy-discharge-readiness', search], initialData,
    queryFn: async () => pharmacyDischargeSchema.parse((await axios.get(`/api/pharmacy/discharge-readiness${search}`)).data),
    refetchInterval: 60_000,
  });
}
