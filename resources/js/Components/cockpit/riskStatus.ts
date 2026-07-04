// resources/js/Components/cockpit/riskStatus.ts
//
// P6 workstream 6 — the ONE tier↔state mapping. Eddy catalog risk levels
// resolve onto the cockpit's ISA-101 states so EddyApprovalCard and the
// AlertTicker encode identical severity with identical shape+color:
// critical→crit ◆ coral, high→warn ▲ amber, medium→watch ● sky, low→ok.
// Mirrors EddyApprovalNotifier::statusForRisk (server) — keep in lockstep.
import type { CockpitState } from '@/types/cockpit';

export const RISK_TO_STATUS = {
  critical: 'crit',
  high: 'warn',
  medium: 'watch',
  low: 'ok',
} as const satisfies Record<string, CockpitState>;

/** Unknown risk → watch (never escalate on uncertainty). */
export function statusForRisk(risk: string | null | undefined): CockpitState {
  return RISK_TO_STATUS[(risk ?? '') as keyof typeof RISK_TO_STATUS] ?? 'watch';
}
