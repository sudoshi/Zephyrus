import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { decideApproval, fetchAgentDefinitions, fetchAgentInbox, runAgent } from './api';

export function useAgentDefinitions() {
  return useQuery({ queryKey: ['ops', 'agent-definitions'], queryFn: fetchAgentDefinitions });
}

export function useAgentInbox(enabled = true) {
  return useQuery({ queryKey: ['ops', 'agent-inbox'], queryFn: fetchAgentInbox, enabled });
}

export function useRunAgent() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (agentKey: string) => runAgent(agentKey),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['ops'] }),
  });
}

/** P6 WS-5: approve/reject from the cockpit inbox modal (same FSM). */
export function useDecideApproval() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ approvalId, decision, reason }: { approvalId: number; decision: 'approved' | 'rejected'; reason?: string }) =>
      decideApproval(approvalId, decision, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['ops'] }),
  });
}
