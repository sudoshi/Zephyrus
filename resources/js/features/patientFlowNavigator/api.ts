import axios from 'axios';
import type {
  PatientFlowEvent,
  PatientFlowLocations,
  PatientFlowState,
  PatientFlowSummary,
  PatientFlowTracks,
} from './types';

export interface PatientFlowEventQuery {
  from?: string;
  to?: string;
  patient?: string;
  category?: string;
  service_line?: string;
  floor?: string;
  limit?: number;
}

export async function fetchPatientFlowSummary(): Promise<PatientFlowSummary> {
  const response = await axios.get<PatientFlowSummary>('/api/patient-flow/summary');
  return response.data;
}

export async function fetchPatientFlowLocations(): Promise<PatientFlowLocations> {
  const response = await axios.get<PatientFlowLocations>('/api/patient-flow/locations');
  return response.data;
}

export async function fetchPatientFlowEvents(query: PatientFlowEventQuery = {}): Promise<PatientFlowEvent[]> {
  const response = await axios.get<PatientFlowEvent[]>('/api/patient-flow/events', { params: query });
  return response.data;
}

export async function fetchPatientFlowTracks(query: PatientFlowEventQuery = {}): Promise<PatientFlowTracks> {
  const response = await axios.get<PatientFlowTracks>('/api/patient-flow/tracks', { params: query });
  return response.data;
}

export async function fetchPatientFlowState(asOf?: string): Promise<PatientFlowState> {
  const response = await axios.get<PatientFlowState>('/api/patient-flow/state', {
    params: asOf ? { asOf } : {},
  });
  return response.data;
}

export async function fetchPatientFlowFhirBundle(eventId: string): Promise<Record<string, unknown>> {
  const response = await axios.get<Record<string, unknown>>('/api/patient-flow/fhir/bundle', {
    params: { event_id: eventId },
  });
  return response.data;
}

export function createPatientFlowEventSource(options: { replay?: number; interval?: number } = {}): EventSource {
  const params = new URLSearchParams();
  if (options.replay) params.set('replay', String(options.replay));
  if (options.interval) params.set('interval', String(options.interval));
  const suffix = params.toString() ? `?${params.toString()}` : '';

  return new EventSource(`/api/patient-flow/stream/adt${suffix}`);
}
