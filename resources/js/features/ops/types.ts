export type AgentKey = 'capacity_commander' | 'data_quality_agent' | 'executive_briefing_agent';
export type AgentRunStatus = 'pending' | 'running' | 'completed' | 'blocked' | 'failed';
export type Tone = 'critical' | 'warning' | 'success' | 'info';

export interface AgentDefinition {
  agentDefinitionId: number;
  key: string;
  label: string;
  description: string;
  mode: string;
  status: string;
  readOnly: boolean;
  minimumRole: string;
  toolAllowlist: string[];
  safetyPolicy: Record<string, unknown>;
}

export interface AgentToolCall {
  agentToolCallId: number;
  toolKey: string;
  status: string;
  readOnly: boolean;
  errorMessage: string | null;
  startedAtIso: string | null;
  completedAtIso: string | null;
}

export interface AgentEvaluation {
  evaluationKey: string;
  status: string;
  score: number;
  detail: string;
}

export interface AgentSafetyEvent {
  eventType: string;
  severity: string;
  status: string;
  detail: string;
}

export interface BriefSituationItem {
  domain: string;
  status: string;
  detail: string;
}

export interface BriefRecommendation {
  type: string;
  title: string;
  riskLevel: string;
  status: string;
}

export interface ExecutiveBriefMeasuredImpact {
  totalInterventions: number;
  estimatedNetBedGain: number;
  primaryOutcomesImproved: number;
  primaryOutcomeCount: number;
  confidenceLevel: string;
  confidenceLanguage: string;
}

export interface BriefLineageItem {
  domain: string;
  status: string;
  detail: string;
}

export interface AgentRunOutput {
  key?: string;
  label?: string;
  status?: string;
  readOnly?: boolean;
  headline?: string;
  situation?: BriefSituationItem[];
  recommendedPlan?: {
    pendingApprovals: number;
    draftActions: number;
    openRecommendations: number;
    topRecommendations: BriefRecommendation[];
  };
  measuredImpact?: ExecutiveBriefMeasuredImpact;
  sourceLineage?: BriefLineageItem[];
  confidenceStatement?: string;
  findings?: Array<Record<string, unknown>>;
  nextActions?: unknown[];
  sourceTables?: string[];
  blockedReason?: string;
}

export interface AgentRun {
  agentRunId: number;
  agentKey: string | null;
  label: string | null;
  status: AgentRunStatus;
  mode: string;
  objective: string | null;
  blockedReason: string | null;
  output: AgentRunOutput;
  startedAtIso: string | null;
  completedAtIso: string | null;
  toolCalls: AgentToolCall[];
  evaluations: AgentEvaluation[];
  safetyEvents: AgentSafetyEvent[];
}

export interface AgentInboxSummary {
  pendingApprovals: number;
  activeActions: number;
  approvedActions: number;
  assignedActions: number;
  executingActions: number;
  overdueActions: number;
}

export interface AgentInboxApprovalItem {
  approvalId: number;
  status: string;
  reason: string | null;
  action: {
    actionId: number;
    type: string;
    status: string;
    ownerName: string | null;
    recommendation: { title: string | null; riskLevel: string | null } | null;
  } | null;
}

export interface AgentInboxActionItem {
  actionId: number;
  type: string;
  status: string;
  ownerName: string | null;
  isOverdue: boolean;
  recommendation: { title: string | null; riskLevel: string | null } | null;
}

export interface AgentInbox {
  summary: AgentInboxSummary;
  approvals: AgentInboxApprovalItem[];
  actions: AgentInboxActionItem[];
}
