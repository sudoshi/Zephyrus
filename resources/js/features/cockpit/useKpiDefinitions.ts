// resources/js/features/cockpit/useKpiDefinitions.ts
//
// P8 WS-6b — TanStack Query bindings for the admin THRESHOLD EDITOR. The read is
// near-static per session (definitions change only when an admin saves), so a 60s
// staleTime avoids refetch churn; a successful save invalidates the query so the
// table re-serves the persisted edges. Data stays `unknown` end to end — the page
// narrows it with safeParseKpiDefinitions ('@/types/kpiDefinitions').
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { fetchKpiDefinitions, updateKpiDefinition, type KpiDefinitionUpdate } from './kpiDefinitionsApi';

export function useKpiDefinitions() {
  return useQuery<unknown>({
    queryKey: ['cockpit', 'kpi-definitions'],
    queryFn: fetchKpiDefinitions,
    staleTime: 60_000,
  });
}

export function useUpdateKpiDefinition() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ metricKey, body }: { metricKey: string; body: KpiDefinitionUpdate }) =>
      updateKpiDefinition(metricKey, body),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['cockpit', 'kpi-definitions'] });
    },
  });
}
