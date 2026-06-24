import axios from 'axios';
import { z } from 'zod';
import type {
  CreateTransportRequestInput,
  TransportOverview,
  TransportRequest,
  TransportStatus,
} from './types';

const requestSchema = z.object({
  transport_request_id: z.number(),
  request_uuid: z.string(),
  request_type: z.enum(['inpatient', 'transfer', 'discharge', 'ems', 'care_transition']),
  priority: z.enum(['routine', 'urgent', 'stat']),
  status: z.enum([
    'requested',
    'accepted',
    'queued',
    'assigned',
    'dispatched',
    'arrived_pickup',
    'patient_ready',
    'patient_not_ready',
    'picked_up',
    'en_route',
    'arrived_destination',
    'handoff_started',
    'handoff_complete',
    'completed',
    'canceled',
    'escalated',
    'failed',
  ]),
  patient_ref: z.string(),
  encounter_ref: z.string().nullable(),
  origin: z.string(),
  destination: z.string(),
  transport_mode: z.string(),
  clinical_service: z.string().nullable(),
  requested_by: z.string().nullable(),
  requested_at: z.string().nullable(),
  needed_at: z.string().nullable(),
  assigned_at: z.string().nullable(),
  dispatched_at: z.string().nullable(),
  completed_at: z.string().nullable(),
  assigned_team: z.string().nullable(),
  assigned_vendor: z.string().nullable(),
  external_system: z.string().nullable(),
  external_id: z.string().nullable(),
  segments: z.array(z.record(z.string(), z.unknown())),
  risk_flags: z.union([z.array(z.string()), z.record(z.string(), z.unknown())]),
  handoff: z.record(z.string(), z.unknown()),
  metadata: z.record(z.string(), z.unknown()),
  sla: z.object({
    minutes_until_due: z.number().nullable(),
    at_risk: z.boolean(),
    label: z.string(),
  }),
});

const optionSchema = z.object({
  key: z.string(),
  name: z.string(),
  type: z.string().optional(),
  available: z.number().optional(),
  capabilities: z.array(z.string()).optional(),
});

const overviewSchema = z.object({
  metrics: z.object({
    active: z.number(),
    at_risk: z.number(),
    completed_today: z.number(),
    stat: z.number(),
    transfer_backlog: z.number(),
    discharge_rides: z.number(),
    ems_inbound: z.number(),
  }),
  by_type: z.record(z.string(), z.number()),
  by_status: z.record(z.string(), z.number()),
  queue: z.array(requestSchema),
  vendor_options: z.array(optionSchema),
  resource_options: z.array(optionSchema),
});

const envelope = <T>(schema: z.ZodType<T>) => z.object({ data: schema });

export async function fetchTransportOverview(): Promise<TransportOverview> {
  const res = await axios.get('/api/transport/overview');
  return envelope(overviewSchema).parse(res.data).data;
}

export async function fetchTransportRequests(params: Record<string, string | undefined> = {}): Promise<TransportRequest[]> {
  const res = await axios.get('/api/transport/requests', { params });
  return envelope(z.array(requestSchema)).parse(res.data).data;
}

export async function createTransportRequest(input: CreateTransportRequestInput): Promise<TransportRequest> {
  const res = await axios.post('/api/transport/requests', input);
  return envelope(requestSchema).parse(res.data).data;
}

export async function assignTransportRequest(id: number, input: { assigned_team?: string; assigned_vendor?: string; note?: string }): Promise<TransportRequest> {
  const res = await axios.post(`/api/transport/requests/${id}/assign`, input);
  return envelope(requestSchema).parse(res.data).data;
}

export async function updateTransportStatus(id: number, status: TransportStatus, note?: string): Promise<TransportRequest> {
  const res = await axios.post(`/api/transport/requests/${id}/status`, { status, note });
  return envelope(requestSchema).parse(res.data).data;
}

export async function fetchTransportResources() {
  const res = await axios.get('/api/transport/resources');
  return envelope(z.array(optionSchema)).parse(res.data).data;
}

export async function fetchTransportVendors() {
  const res = await axios.get('/api/transport/vendors');
  return envelope(z.array(optionSchema)).parse(res.data).data;
}
