import { z } from 'zod';

// A draft action Eddy proposes for human approval (it never executes).
export const eddyProposedActionSchema = z.object({
  action_type: z.string(),
  title: z.string(),
  params: z.record(z.string(), z.unknown()).default({}),
  rationale: z.string().nullable().optional(),
  runner_up: z.string().nullable().optional(),
  tier: z.string(),
  risk: z.string(),
  label: z.string(),
});

// The assistant turn (present whenever an answer was produced, even a degraded one).
export const eddyAssistantMessageSchema = z.object({
  id: z.number().optional(),
  role: z.literal('assistant'),
  content: z.string(),
  provider: z.string().nullable().optional(),
  model: z.string().nullable().optional(),
  route_reason: z.string().nullable().optional(),
  fallback_reason: z.string().nullable().optional(),
  proposed_action: eddyProposedActionSchema.nullable().optional(),
});

export const eddyChatResponseSchema = z.object({
  conversation_id: z.string(),
  status: z.enum(['success', 'error']),
  // object when an assistant turn exists; string when Eddy was unreachable.
  message: z.union([eddyAssistantMessageSchema, z.string()]),
});

// The result of proposing/approving an action through the governance backbone.
export const eddyProposeResultSchema = z.object({
  action_uuid: z.string(),
  approval_id: z.number(),
  approval_uuid: z.string(),
  action_type: z.string(),
  tier: z.string(),
  risk: z.string(),
  title: z.string(),
  status: z.string(),
  approved: z.boolean(),
});

export type EddyProposedAction = z.infer<typeof eddyProposedActionSchema>;
export type EddyAssistantMessage = z.infer<typeof eddyAssistantMessageSchema>;
export type EddyChatResponse = z.infer<typeof eddyChatResponseSchema>;
export type EddyProposeResult = z.infer<typeof eddyProposeResultSchema>;

export const eddyConversationSummarySchema = z.object({
  id: z.string(),
  title: z.string().nullable(),
  surface: z.string(),
});

export type EddyConversationSummary = z.infer<typeof eddyConversationSummarySchema>;
