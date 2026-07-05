// resources/js/features/cockpit/kpiDefinitionsApi.ts
//
// P8 WS-6b — thin transport for the admin THRESHOLD EDITOR. Both endpoints are
// admin-gated server-side (the PUT is audited); this layer stays dumb and returns
// the raw payload as `unknown`. Parsing/validation lives in the page via
// safeParseKpiDefinitions ('@/types/kpiDefinitions'). Only the edited edges (and
// optionally refresh/template/active) are sent — a partial patch, never the whole
// definition — so an untouched field is left as-is by the server.
import axios from 'axios';

export interface KpiDefinitionUpdate {
  ok_edge?: number | null;
  warn_edge?: number | null;
  crit_edge?: number | null;
  refresh_secs?: number;
  alert_template?: string | null;
  is_active?: boolean;
}

export async function fetchKpiDefinitions(): Promise<unknown> {
  const res = await axios.get('/api/cockpit/kpi-definitions');
  return res.data;
}

export async function updateKpiDefinition(
  metricKey: string,
  body: KpiDefinitionUpdate,
): Promise<unknown> {
  const res = await axios.put('/api/cockpit/kpi-definitions/' + encodeURIComponent(metricKey), body);
  return res.data;
}
