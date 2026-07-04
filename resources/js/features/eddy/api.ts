import axios from 'axios';
import {
  eddyChatResponseSchema,
  eddyConversationSummarySchema,
  eddyProposeResultSchema,
  type EddyChatResponse,
  type EddyConversationSummary,
  type EddyProposeResult,
} from './schemas';
import { z } from 'zod';

export interface SendChatInput {
  message: string;
  surface: string;
  page_context?: string | null;
  page_component?: string | null;
  page_data?: Record<string, unknown>;
  conversation_id?: string | null;
}

// Controller wraps every payload in { data: ... } (the house envelope).
const envelope = <T extends z.ZodTypeAny>(schema: T) => z.object({ data: schema });

export async function sendEddyChat(input: SendChatInput): Promise<EddyChatResponse> {
  const { data } = await axios.post('/api/eddy/chat', input);
  return envelope(eddyChatResponseSchema).parse(data).data;
}

export async function fetchEddyConversations(): Promise<EddyConversationSummary[]> {
  const { data } = await axios.get('/api/eddy/conversations');
  return envelope(z.array(eddyConversationSummarySchema)).parse(data).data;
}

export interface ProposeActionInput {
  action_type: string;
  title?: string;
  surface?: string;
  params?: Record<string, unknown>;
  rationale?: string | null;
  runner_up?: string | null;
  /** P6: provenance — the cockpit alert key that spawned this proposal. */
  alert_key?: string;
  approve?: boolean;
}

/** Create (and, for a human, approve) a governance action proposed by Eddy. */
export async function proposeEddyAction(input: ProposeActionInput): Promise<EddyProposeResult> {
  const { data } = await axios.post('/api/eddy/actions/propose', input);
  return envelope(eddyProposeResultSchema).parse(data).data;
}
