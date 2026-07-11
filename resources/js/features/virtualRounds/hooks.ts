// TanStack Query hooks for Virtual Rounds. Query data stays `unknown` —
// components parse with the Zod schemas. Every mutation invalidates the
// ['rounds'] tree; realtime reload pings can later target the same keys.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  composeContribution,
  createQuestion,
  createRoundRun,
  createTask,
  fetchRoundBoard,
  fetchRoundPatient,
  fetchRoundRuns,
  fetchRoundScopes,
  fetchRoundTemplates,
  pinPatient,
  reorderQueue,
  runLifecycle,
  transitionPatient,
  transitionTask,
  type PatientTransitionAction,
  type RunLifecycleAction,
} from './api';

export function useRoundTemplates() {
  return useQuery<unknown>({
    queryKey: ['rounds', 'templates'],
    queryFn: fetchRoundTemplates,
    staleTime: 5 * 60_000,
    retry: false,
  });
}

export function useRoundScopes() {
  return useQuery<unknown>({
    queryKey: ['rounds', 'scopes'],
    queryFn: fetchRoundScopes,
    staleTime: 5 * 60_000,
    retry: false,
  });
}

export function useRoundRuns(scope?: string) {
  return useQuery<unknown>({
    queryKey: ['rounds', 'runs', scope ?? 'all'],
    queryFn: () => fetchRoundRuns(scope ? { scope } : undefined),
    staleTime: 30_000,
    retry: false,
  });
}

export function useRoundBoard(runUuid: string | null) {
  return useQuery<unknown>({
    queryKey: ['rounds', 'board', runUuid],
    queryFn: () => fetchRoundBoard(runUuid as string),
    enabled: runUuid !== null,
    staleTime: 15_000,
    refetchInterval: 30_000,
    retry: false,
  });
}

export function useRoundPatient(roundPatientUuid: string | null) {
  return useQuery<unknown>({
    queryKey: ['rounds', 'patient', roundPatientUuid],
    queryFn: () => fetchRoundPatient(roundPatientUuid as string),
    enabled: roundPatientUuid !== null,
    staleTime: 10_000,
    retry: false,
  });
}

function useRoundsInvalidation() {
  const queryClient = useQueryClient();
  return () => queryClient.invalidateQueries({ queryKey: ['rounds'] });
}

export function useCreateRun() {
  const invalidate = useRoundsInvalidation();
  return useMutation({
    mutationFn: (input: { template_uuid: string; scope_type: string; scope_key: string }) =>
      createRoundRun(input),
    onSettled: invalidate,
  });
}

export function useRunLifecycle(runUuid: string | null) {
  const invalidate = useRoundsInvalidation();
  return useMutation({
    mutationFn: ({
      action,
      body,
    }: {
      action: RunLifecycleAction;
      body?: { exception_reason?: string; reason?: string };
    }) => runLifecycle(runUuid as string, action, body ?? {}),
    onSettled: invalidate,
  });
}

export function usePatientTransition() {
  const invalidate = useRoundsInvalidation();
  return useMutation({
    mutationFn: ({
      roundPatientUuid,
      action,
      body,
    }: {
      roundPatientUuid: string;
      action: PatientTransitionAction;
      body?: { expected_version?: number; reason?: string; exception_reason?: string };
    }) => transitionPatient(roundPatientUuid, action, body ?? {}),
    onSettled: invalidate,
  });
}

export function usePinPatient() {
  const invalidate = useRoundsInvalidation();
  return useMutation({
    mutationFn: ({
      roundPatientUuid,
      pinned,
      reason,
      expectedQueueVersion,
    }: {
      roundPatientUuid: string;
      pinned: boolean;
      reason: string;
      expectedQueueVersion: number;
    }) => pinPatient(roundPatientUuid, pinned, reason, expectedQueueVersion),
    onSettled: invalidate,
  });
}

export function useReorderQueue(runUuid: string | null) {
  const invalidate = useRoundsInvalidation();
  return useMutation({
    mutationFn: ({ order, expectedQueueVersion }: { order: string[]; expectedQueueVersion: number }) =>
      reorderQueue(runUuid as string, order, expectedQueueVersion),
    onSettled: invalidate,
  });
}

export function useComposeContribution() {
  const invalidate = useRoundsInvalidation();
  return useMutation({
    mutationFn: ({
      roundPatientUuid,
      body,
    }: {
      roundPatientUuid: string;
      body: {
        section_code: string;
        author_role?: string;
        structured_data?: Record<string, unknown>;
        summary?: string;
        submit?: boolean;
      };
    }) => composeContribution(roundPatientUuid, body),
    onSettled: invalidate,
  });
}

export function useCreateQuestion() {
  const invalidate = useRoundsInvalidation();
  return useMutation({
    mutationFn: ({
      roundPatientUuid,
      body,
    }: {
      roundPatientUuid: string;
      body: { question_text: string; target_role?: string };
    }) => createQuestion(roundPatientUuid, body),
    onSettled: invalidate,
  });
}

export function useCreateTask() {
  const invalidate = useRoundsInvalidation();
  return useMutation({
    mutationFn: ({
      roundPatientUuid,
      body,
    }: {
      roundPatientUuid: string;
      body: { title: string; detail?: string; category?: string; owner_role?: string };
    }) => createTask(roundPatientUuid, body),
    onSettled: invalidate,
  });
}

export function useTransitionTask() {
  const invalidate = useRoundsInvalidation();
  return useMutation({
    mutationFn: ({ taskUuid, status }: { taskUuid: string; status: string }) =>
      transitionTask(taskUuid, status),
    onSettled: invalidate,
  });
}
