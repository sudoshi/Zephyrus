import axios from 'axios';
import { eddyChatResponseSchema, eddyConversationSummarySchema, type EddyChatResponse, type EddyConversationSummary } from './schemas';
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
