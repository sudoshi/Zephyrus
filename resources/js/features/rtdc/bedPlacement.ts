import axios from 'axios';
import { z } from 'zod';
import {
  bedRequestSchema, rankedRecommendationsSchema,
  type BedRequest, type RankedRecommendations,
} from '@/schemas/rtdc';

const envelope = <T>(schema: z.ZodType<T>) => z.object({ data: schema });

export async function fetchPendingRequests(): Promise<BedRequest[]> {
  const res = await axios.get('/api/rtdc/bed-requests');
  return envelope(z.array(bedRequestSchema)).parse(res.data).data;
}

export interface CreateBedRequestInput {
  patient_ref: string; source: string; acuity_tier: number;
  isolation_required: string; required_unit_type: string; sex?: string; service?: string;
}
export async function createBedRequest(input: CreateBedRequestInput): Promise<BedRequest> {
  const res = await axios.post('/api/rtdc/bed-requests', input);
  return envelope(bedRequestSchema).parse(res.data).data;
}

export async function fetchRecommendations(bedRequestId: number): Promise<RankedRecommendations> {
  const res = await axios.get(`/api/rtdc/bed-requests/${bedRequestId}/recommendations`);
  return envelope(rankedRecommendationsSchema).parse(res.data).data;
}

export interface DecisionInput { action: 'accepted' | 'edited' | 'rejected'; chosen_bed_id?: number; reason?: string }
export async function postDecision(bedRequestId: number, input: DecisionInput): Promise<void> {
  await axios.post(`/api/rtdc/bed-requests/${bedRequestId}/decision`, input);
}
