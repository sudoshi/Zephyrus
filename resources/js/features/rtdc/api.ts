import axios from 'axios';
import { z } from 'zod';
import {
  unitCensusSchema, predictionSchema, bedMeetingSchema, barrierSchema,
  type UnitCensus, type Prediction, type BedMeeting, type Barrier,
} from '@/schemas/rtdc';

const envelope = <T>(schema: z.ZodType<T>) => z.object({ data: schema });

export async function fetchUnits(): Promise<UnitCensus[]> {
  const res = await axios.get('/api/rtdc/units');
  return envelope(z.array(unitCensusSchema)).parse(res.data).data;
}

export async function fetchPrediction(unitId: number, serviceDate: string, horizon: string): Promise<Prediction | null> {
  const res = await axios.get(`/api/rtdc/units/${unitId}/prediction`, { params: { service_date: serviceDate, horizon } });
  const parsed = z.object({ data: predictionSchema.nullable() }).parse(res.data);
  return parsed.data;
}

export interface CapacityInput { service_date: string; horizon: string; definite: number; probable: number; possible: number }
export async function upsertCapacity(unitId: number, input: CapacityInput): Promise<Prediction> {
  const res = await axios.post(`/api/rtdc/units/${unitId}/capacity`, input);
  return envelope(predictionSchema).parse(res.data).data;
}

export interface DemandInput { service_date: string; horizon: string; ed: number; or: number; transfer: number; direct: number }
export async function upsertDemand(unitId: number, input: DemandInput): Promise<Prediction> {
  const res = await axios.post(`/api/rtdc/units/${unitId}/demand`, input);
  return envelope(predictionSchema).parse(res.data).data;
}

export async function developPlan(unitId: number, serviceDate: string, horizon: string): Promise<Prediction> {
  const res = await axios.post(`/api/rtdc/units/${unitId}/plan`, { service_date: serviceDate, horizon });
  return envelope(predictionSchema).parse(res.data).data;
}

export async function fetchBedMeeting(serviceDate: string, horizon: string): Promise<BedMeeting> {
  const res = await axios.get('/api/rtdc/bed-meeting', { params: { service_date: serviceDate, horizon } });
  return envelope(bedMeetingSchema).parse(res.data).data;
}

export async function fetchBarriers(unitId?: number): Promise<Barrier[]> {
  const res = await axios.get('/api/rtdc/barriers', { params: unitId ? { unit_id: unitId } : {} });
  return envelope(z.array(barrierSchema)).parse(res.data).data;
}
