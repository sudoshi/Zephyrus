import { z } from 'zod';

// The assistant turn (present whenever an answer was produced, even a degraded one).
export const eddyAssistantMessageSchema = z.object({
  id: z.number().optional(),
  role: z.literal('assistant'),
  content: z.string(),
  provider: z.string().nullable().optional(),
  model: z.string().nullable().optional(),
  route_reason: z.string().nullable().optional(),
  fallback_reason: z.string().nullable().optional(),
});

export const eddyChatResponseSchema = z.object({
  conversation_id: z.string(),
  status: z.enum(['success', 'error']),
  // object when an assistant turn exists; string when Eddy was unreachable.
  message: z.union([eddyAssistantMessageSchema, z.string()]),
});

export type EddyAssistantMessage = z.infer<typeof eddyAssistantMessageSchema>;
export type EddyChatResponse = z.infer<typeof eddyChatResponseSchema>;

export const eddyConversationSummarySchema = z.object({
  id: z.string(),
  title: z.string().nullable(),
  surface: z.string(),
});

export type EddyConversationSummary = z.infer<typeof eddyConversationSummarySchema>;
