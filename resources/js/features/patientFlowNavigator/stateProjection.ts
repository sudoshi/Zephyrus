import type {
  PatientFlowEvent,
  PatientFlowFilters,
  PatientFlowLocations,
  PatientVisibleState,
} from './types';

export function parseTime(value: string): number {
  return new Date(value).getTime();
}

export function rebuildTracks(events: PatientFlowEvent[]): Map<string, PatientFlowEvent[]> {
  const tracks = new Map<string, PatientFlowEvent[]>();
  for (const event of events) {
    const track = tracks.get(event.patient_id) ?? [];
    track.push(event);
    tracks.set(event.patient_id, track);
  }

  for (const track of tracks.values()) {
    track.sort((a, b) => parseTime(a.occurred_at) - parseTime(b.occurred_at));
  }

  return tracks;
}

export function positionFor(locations: PatientFlowLocations, locationCode?: string | null): { x: number; y: number; z: number } | null {
  if (!locationCode) return null;
  const loc = locations[locationCode];
  if (!loc?.position_m) return null;

  return {
    x: loc.position_m.x,
    y: (loc.position_m.y ?? 0) + 1.7,
    z: loc.position_m.z,
  };
}

export function visibleByFilters(event: PatientFlowEvent, filters: PatientFlowFilters): boolean {
  if (filters.floor !== 'all' && String(event.location_floor) !== filters.floor) return false;
  if (filters.serviceLine !== 'all' && event.service_line !== filters.serviceLine && event.location_service_line !== filters.serviceLine) return false;
  if (filters.category !== 'all' && event.event_category !== filters.category) return false;

  const query = filters.search.trim().toLowerCase();
  if (!query) return true;

  return [
    event.patient_display_id,
    event.patient_id,
    event.to_location,
    event.service_line,
    event.location_name,
    event.unit_code,
  ]
    .filter(Boolean)
    .some((value) => String(value).toLowerCase().includes(query));
}

export function patientStatesAt(
  tracks: Map<string, PatientFlowEvent[]>,
  locations: PatientFlowLocations,
  timeMs: number,
  filters: PatientFlowFilters,
): PatientVisibleState[] {
  const states: PatientVisibleState[] = [];
  const transitionMs = 12 * 60 * 1000;

  for (const [patientId, track] of tracks.entries()) {
    let current: PatientFlowEvent | null = null;
    for (const event of track) {
      const when = parseTime(event.occurred_at);
      if (when > timeMs) break;
      if (event.event_type === 'discharge' || event.event_type === 'cancel_admit') {
        current = null;
        continue;
      }
      if (event.to_location) current = event;
    }

    if (!current || !visibleByFilters(current, filters)) continue;
    const target = positionFor(locations, current.to_location);
    if (!target) continue;

    const when = parseTime(current.occurred_at);
    const from = positionFor(locations, current.from_location);
    let position = { ...target };
    if (from && timeMs - when >= 0 && timeMs - when <= transitionMs && current.event_category === 'movement') {
      const pct = (timeMs - when) / transitionMs;
      position = {
        x: from.x + (target.x - from.x) * pct,
        y: from.y + (target.y - from.y) * pct,
        z: from.z + (target.z - from.z) * pct,
      };
    }

    const recent = track.filter((event) => {
      const occurred = parseTime(event.occurred_at);
      return occurred <= timeMs && occurred >= timeMs - 2 * 60 * 60 * 1000;
    });

    states.push({ patientId, event: current, position, recent });
  }

  return states;
}
