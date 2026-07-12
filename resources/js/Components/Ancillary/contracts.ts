import type { ReadinessAxisContract, SourceFreshnessContract } from './schemas';

export type FreshnessStatus = 'fresh' | 'stale' | 'batch' | 'unknown';
export type MilestoneState = 'done' | 'current' | 'pending_required' | 'missing_optional' | 'terminal' | 'exception';
export type ClockState = 'not_started' | 'running' | 'warning' | 'breached' | 'complete' | 'unknown';

export type SourceFreshness = SourceFreshnessContract;

export interface TimelineMilestone {
  code: string;
  label: string;
  state: MilestoneState;
  required: boolean;
  occurredAt: string | null;
  selectedSource: string | null;
  assertionCount: number;
  conflict: boolean;
  explanation?: string | null;
}

export interface SelectedClockContract {
  metricKey: string;
  label: string;
  state: ClockState;
  startMilestoneCode: string;
  stopMilestoneCode: string;
  startedAt: string | null;
  stoppedAt: string | null;
  elapsedMinutes: number | null;
  warningMinutes: number | null;
  breachMinutes: number | null;
  definitionUuid: string;
}

export interface AncillaryTimelineContract {
  orderUuid: string;
  label: string;
  milestones: TimelineMilestone[];
  clock: SelectedClockContract | null;
  freshness: SourceFreshness;
  degradedMode: boolean;
  degradedExplanation: string | null;
}

export type ReadinessState = ReadinessAxisContract['status'];
export type { ReadinessAxisContract } from './schemas';
