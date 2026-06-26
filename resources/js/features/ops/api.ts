import axios from 'axios';
import { z } from 'zod';
import type { AgentDefinition, AgentInbox, AgentRun } from './types';

const envelope = <T>(schema: z.ZodType<T>) => z.object({ data: schema });

const definitionSchema = z.object({
  agentDefinitionId: z.number(),
  key: z.string(),
  label: z.string(),
  description: z.string(),
  mode: z.string(),
  status: z.string(),
  readOnly: z.boolean(),
  minimumRole: z.string(),
  toolAllowlist: z.array(z.string()),
  safetyPolicy: z.record(z.string(), z.unknown()),
});

const situationSchema = z.object({ domain: z.string(), status: z.string(), detail: z.string() });
const briefRecommendationSchema = z.object({ type: z.string(), title: z.string(), riskLevel: z.string(), status: z.string() });

const runOutputSchema = z.object({
  key: z.string().optional(),
  label: z.string().optional(),
  status: z.string().optional(),
  readOnly: z.boolean().optional(),
  headline: z.string().optional(),
  situation: z.array(situationSchema).optional(),
  recommendedPlan: z
    .object({
      pendingApprovals: z.number(),
      draftActions: z.number(),
      openRecommendations: z.number(),
      topRecommendations: z.array(briefRecommendationSchema),
    })
    .optional(),
  measuredImpact: z
    .object({
      totalInterventions: z.number(),
      estimatedNetBedGain: z.number(),
      primaryOutcomesImproved: z.number(),
      primaryOutcomeCount: z.number(),
      confidenceLevel: z.string(),
      confidenceLanguage: z.string(),
    })
    .optional(),
  sourceLineage: z.array(situationSchema).optional(),
  confidenceStatement: z.string().optional(),
  findings: z.array(z.record(z.string(), z.unknown())).optional(),
  nextActions: z.array(z.unknown()).optional(),
  sourceTables: z.array(z.string()).optional(),
  blockedReason: z.string().optional(),
});

const runSchema = z.object({
  agentRunId: z.number(),
  agentKey: z.string().nullable(),
  label: z.string().nullable(),
  status: z.enum(['pending', 'running', 'completed', 'blocked', 'failed']),
  mode: z.string(),
  objective: z.string().nullable(),
  blockedReason: z.string().nullable(),
  output: runOutputSchema,
  startedAtIso: z.string().nullable(),
  completedAtIso: z.string().nullable(),
  toolCalls: z.array(
    z.object({
      agentToolCallId: z.number(),
      toolKey: z.string(),
      status: z.string(),
      readOnly: z.boolean(),
      errorMessage: z.string().nullable(),
      startedAtIso: z.string().nullable(),
      completedAtIso: z.string().nullable(),
    }),
  ),
  evaluations: z.array(z.object({ evaluationKey: z.string(), status: z.string(), score: z.number(), detail: z.string() })),
  safetyEvents: z.array(z.object({ eventType: z.string(), severity: z.string(), status: z.string(), detail: z.string() })),
});

const recommendationStubSchema = z.object({ title: z.string().nullable(), riskLevel: z.string().nullable() }).nullable();

const inboxSchema = z.object({
  summary: z.object({
    pendingApprovals: z.number(),
    activeActions: z.number(),
    approvedActions: z.number(),
    assignedActions: z.number(),
    executingActions: z.number(),
    overdueActions: z.number(),
  }),
  approvals: z.array(
    z.object({
      approvalId: z.number(),
      status: z.string(),
      reason: z.string().nullable(),
      action: z
        .object({
          actionId: z.number(),
          type: z.string(),
          status: z.string(),
          ownerName: z.string().nullable(),
          recommendation: recommendationStubSchema,
        })
        .nullable(),
    }),
  ),
  actions: z.array(
    z.object({
      actionId: z.number(),
      type: z.string(),
      status: z.string(),
      ownerName: z.string().nullable(),
      isOverdue: z.boolean(),
      recommendation: recommendationStubSchema,
    }),
  ),
});

const RUN_ENDPOINTS: Record<string, string> = {
  capacity_commander: '/api/ops/agents/capacity-commander/run',
  data_quality_agent: '/api/ops/agents/data-quality/run',
  executive_briefing_agent: '/api/ops/agents/executive-briefing/run',
};

export async function fetchAgentDefinitions(): Promise<AgentDefinition[]> {
  const res = await axios.get('/api/ops/agents/definitions');
  return envelope(z.array(definitionSchema)).parse(res.data).data;
}

export async function fetchAgentInbox(): Promise<AgentInbox> {
  const res = await axios.get('/api/ops/agent-inbox');
  return envelope(inboxSchema).parse(res.data).data;
}

export async function runAgent(agentKey: string): Promise<AgentRun> {
  const endpoint = RUN_ENDPOINTS[agentKey];
  if (!endpoint) {
    throw new Error(`Unknown agent key: ${agentKey}`);
  }
  const res = await axios.post(endpoint);
  return envelope(runSchema).parse(res.data).data;
}
