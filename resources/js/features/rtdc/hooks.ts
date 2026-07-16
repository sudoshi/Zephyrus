import { useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { echo } from '@/lib/echo';
import {
  fetchUnits, fetchPrediction, upsertCapacity, upsertDemand, developPlan,
  fetchBedMeeting, fetchBarriers, fetchReliability, type CapacityInput, type DemandInput,
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

export function useReliability(unitId: number) {
  return useQuery({ queryKey: ['rtdc', 'reliability', unitId], queryFn: () => fetchReliability(unitId) });
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
 * the relevant queries whenever the socket (re)connects so we never rely on
 * replaying missed messages (Reverb/Pusher do not replay).
 *
 * Per subscribed `unit.{id}` channel we listen for both `.census.updated`
 * (refreshes the unit census) and `.huddle.updated` (refreshes that unit's
 * prediction). We also subscribe to the `hospital.beds` channel and refresh the
 * bed-meeting rollup on `.bedmeeting.updated` so multi-user huddle edits sync.
 */
export function useLiveCensus() {
  const qc = useQueryClient();

  useEffect(() => {
    const client = echo;
    if (client === null) return;

    const refetchCensus = () => qc.invalidateQueries({ queryKey: ['rtdc', 'units'] });
    const refetchBedMeeting = () => qc.invalidateQueries({ queryKey: ['rtdc', 'bed-meeting'] });

    const units = qc.getQueryData<{ unit_id: number }[]>(['rtdc', 'units']) ?? [];
    const unitChannels = units.map((u) => `unit.${u.unit_id}`);

    units.forEach((u) => {
      client.channel(`unit.${u.unit_id}`)
        .listen('.census.updated', (raw: unknown) => {
          censusUpdatedEventSchema.parse(raw); // validate the wire payload
          refetchCensus();
        })
        .listen('.huddle.updated', () => {
          qc.invalidateQueries({ queryKey: ['rtdc', 'prediction', u.unit_id] });
        });
    });

    client.channel('hospital.beds').listen('.bedmeeting.updated', refetchBedMeeting);

    // Snapshot-on-(re)connect: refresh everything we track.
    const onConnect = () => {
      refetchCensus();
      refetchBedMeeting();
    };
    client.connector.pusher.connection.bind('connected', onConnect);

    return () => {
      unitChannels.forEach((name) => client.leaveChannel(name));
      client.leaveChannel('hospital.beds');
      client.connector.pusher.connection.unbind('connected', onConnect);
    };
  }, [qc]);
}
