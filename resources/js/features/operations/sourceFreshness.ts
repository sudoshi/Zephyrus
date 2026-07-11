import axios from 'axios';
import { z } from 'zod';

export const sourceFreshnessSchema = z.object({
  key: z.string(),
  label: z.string(),
  status: z.enum(['fresh', 'aging', 'stale', 'missing', 'degraded']),
  generated_at: z.string(),
  last_observed_at: z.string().nullable(),
  age_minutes: z.number().nullable(),
  expected_cadence_minutes: z.number(),
  stale_after_minutes: z.number(),
  synthetic: z.boolean(),
  message: z.string(),
});

export type SourceFreshness = z.infer<typeof sourceFreshnessSchema>;

export function errorReference(error: unknown): string | null {
  if (!axios.isAxiosError(error)) return null;

  const responseData = error.response?.data as { correlation_id?: unknown; request_id?: unknown } | undefined;
  const headerValue = error.response?.headers?.['x-request-id'] ?? error.response?.headers?.['x-correlation-id'];
  const value = responseData?.correlation_id ?? responseData?.request_id ?? headerValue;

  return typeof value === 'string' && value.trim() !== '' ? value : null;
}
