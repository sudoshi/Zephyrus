import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { fetchAgentDefinitions, fetchAgentInbox, runAgent } from './api';

export function useAgentDefinitions() {
  return useQuery({ queryKey: ['ops', 'agent-definitions'], queryFn: fetchAgentDefinitions });
}

export function useAgentInbox() {
  return useQuery({ queryKey: ['ops', 'agent-inbox'], queryFn: fetchAgentInbox });
}

export function useRunAgent() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (agentKey: string) => runAgent(agentKey),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['ops'] }),
  });
}
