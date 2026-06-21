import { useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { echo } from '@/lib/echo';
import {
  fetchUnits, fetchPrediction, upsertCapacity, upsertDemand, developPlan,
  fetchBedMeeting, fetchBarriers, type CapacityInput, type DemandInput,
} from './api';
import { censusUpdatedEventSchema } from '@/schemas/rtdc';

export function useUnits() {
  return useQuery({ queryKey: ['rtdc', 'units'], queryFn: fetchUnits });
}

export function usePrediction(unitId: number, serviceDate: string, horizon: string) {
  return useQuery({
    queryKey: ['rtdc', 'prediction', unitId, serviceDate, horizon],
    queryFn: () => fetchPrediction(unitId, serviceDate, horizon),
  });
}

export function useBedMeeting(serviceDate: string, horizon: string) {
  return useQuery({
    queryKey: ['rtdc', 'bed-meeting', serviceDate, horizon],
    queryFn: () => fetchBedMeeting(serviceDate, horizon),
  });
}

export function useBarriers(unitId?: number) {
  return useQuery({ queryKey: ['rtdc', 'barriers', unitId], queryFn: () => fetchBarriers(unitId) });
}

export function useUpsertCapacity(unitId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CapacityInput) => upsertCapacity(unitId, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['rtdc', 'prediction', unitId] }),
  });
}

export function useUpsertDemand(unitId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: DemandInput) => upsertDemand(unitId, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['rtdc', 'prediction', unitId] }),
  });
}

export function useDevelopPlan(unitId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ serviceDate, horizon }: { serviceDate: string; horizon: string }) =>
      developPlan(unitId, serviceDate, horizon),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['rtdc', 'prediction', unitId] }),
  });
}

/**
 * Live census subscription. Research §7: snapshot-on-reconnect — we invalidate
 * the units query whenever the socket (re)connects so we never rely on replaying
 * missed messages (Reverb/Pusher do not replay).
 */
export function useLiveCensus() {
  const qc = useQueryClient();

  useEffect(() => {
    const refetch = () => qc.invalidateQueries({ queryKey: ['rtdc', 'units'] });

    const units = qc.getQueryData<{ unit_id: number }[]>(['rtdc', 'units']) ?? [];
    const channels = units.map((u) => `unit.${u.unit_id}`);

    channels.forEach((name) => {
      echo.channel(name).listen('.census.updated', (raw: unknown) => {
        censusUpdatedEventSchema.parse(raw); // validate the wire payload
        refetch();
      });
    });

    // Snapshot-on-(re)connect.
    echo.connector.pusher.connection.bind('connected', refetch);

    return () => {
      channels.forEach((name) => echo.leaveChannel(name));
      echo.connector.pusher.connection.unbind('connected', refetch);
    };
  }, [qc]);
}
