import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  commitImport,
  createRule,
  discoverSource,
  fetchCoverage,
  fetchImport,
  fetchReference,
  fetchRules,
  fetchSources,
  recordReview,
  reresolveImport,
  startImport,
  testSource,
  upsertSource,
  type CreateRuleInput,
  type DecisionInput,
  type ProbeInput,
  type StartImportInput,
  type UpsertSourceInput,
} from './api';

const KEY = ['deployment', 'staffing'] as const;

// ── Queries ───────────────────────────────────────────────────────────────
export function useStaffingSources() {
  return useQuery({ queryKey: [...KEY, 'sources'], queryFn: fetchSources });
}

export function useMappingRules(sourceId?: number) {
  return useQuery({ queryKey: [...KEY, 'rules', sourceId ?? 'all'], queryFn: () => fetchRules(sourceId) });
}

export function useStaffingReference() {
  // Registry + role taxonomy rarely change — hold it longer than the default.
  return useQuery({ queryKey: [...KEY, 'reference'], queryFn: fetchReference, staleTime: 30 * 60 * 1000 });
}

export function useStaffingCoverage(facilityKey: string | null) {
  return useQuery({
    queryKey: [...KEY, 'coverage', facilityKey],
    queryFn: () => fetchCoverage(facilityKey as string),
    enabled: !!facilityKey,
  });
}

export function useImportRun(runId: number | null) {
  return useQuery({
    queryKey: [...KEY, 'import', runId],
    queryFn: () => fetchImport(runId as number),
    enabled: !!runId,
  });
}

// ── Mutations ─────────────────────────────────────────────────────────────
export function useUpsertSource() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: UpsertSourceInput) => upsertSource(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...KEY, 'sources'] }),
  });
}

export function useTestSource() {
  return useMutation({ mutationFn: ({ id, input }: { id: number; input: ProbeInput }) => testSource(id, input) });
}

export function useDiscoverSource() {
  return useMutation({ mutationFn: ({ id, input }: { id: number; input: ProbeInput }) => discoverSource(id, input) });
}

export function useStartImport() {
  return useMutation({ mutationFn: (input: StartImportInput) => startImport(input) });
}

export function useReresolveImport() {
  return useMutation({ mutationFn: (runId: number) => reresolveImport(runId) });
}

export function useRecordReview() {
  return useMutation({
    mutationFn: ({ runId, staffMemberId, decision }: { runId: number; staffMemberId: number; decision: DecisionInput }) =>
      recordReview(runId, staffMemberId, decision),
  });
}

export function useCommitImport() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (runId: number) => commitImport(runId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: [...KEY, 'coverage'] });
      // A commit changes readiness (criteria 12/13) — refresh the console's data too.
      qc.invalidateQueries({ queryKey: ['deployment', 'facility'] });
    },
  });
}

export function useCreateRule() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CreateRuleInput) => createRule(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...KEY, 'rules'] }),
  });
}
