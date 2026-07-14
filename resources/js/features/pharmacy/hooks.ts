import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { pharmacyFlowBoardSchema, type PharmacyFlowBoard } from './schemas';
import { pharmacyDischargeSchema, type PharmacyDischarge } from './discharge-schemas';
import { pharmacyIvRoomSchema, type PharmacyIvRoom } from './iv-room-schemas';
import { pharmacyDispenseSchema, type PharmacyDispense } from './dispense-schemas';
import { pharmacyControlledSchema, type PharmacyControlled } from './controlled-schemas';
import { pharmacyTatSchema, type PharmacyTat } from './tat-schemas';

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

export function usePharmacyIvRoom(initialData: PharmacyIvRoom) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['pharmacy-iv-room', search], initialData,
    queryFn: async () => pharmacyIvRoomSchema.parse((await axios.get(`/api/pharmacy/iv-room${search}`)).data),
    refetchInterval: 45_000,
  });
}

export function usePharmacyDispense(initialData: PharmacyDispense) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['pharmacy-dispense', search], initialData,
    queryFn: async () => pharmacyDispenseSchema.parse((await axios.get(`/api/pharmacy/dispense${search}`)).data),
    refetchInterval: 45_000,
  });
}

export function usePharmacyControlled(initialData: PharmacyControlled) {
  return useQuery({
    queryKey: ['pharmacy-controlled'], initialData,
    queryFn: async () => pharmacyControlledSchema.parse((await axios.get('/api/pharmacy/controlled')).data),
    refetchInterval: 45_000,
  });
}

export function usePharmacyTat(initialData: PharmacyTat) {
  const search = typeof window === 'undefined' ? '' : window.location.search;

  return useQuery({
    queryKey: ['pharmacy-tat', search], initialData,
    queryFn: async () => pharmacyTatSchema.parse((await axios.get(`/api/pharmacy/tat${search}`)).data),
    refetchInterval: 300_000,
  });
}
