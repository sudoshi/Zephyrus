import { describe, it, expect } from 'vitest';
import {
  arenaQueryResponseSchema,
  arenaNarrativeResponseSchema,
  arenaAuthorMapResponseSchema,
  arenaDraftResponseSchema,
} from '@/features/arena/schema';

// Part X (X4) — guards the Arena copilot Zod contract against the exact payloads
// the Laravel controller returns. The regression that motivated this: a param-less
// query serialized `params` as PHP `[]`, which z.record REJECTS — silently blanking
// half the "Ask the log" catalog. The API now returns `{}`; this pins it.
describe('arena copilot schema contract', () => {
  it('parses a matched query with an OBJECT params map (the {} fix)', () => {
    const parsed = arenaQueryResponseSchema.safeParse({
      available: true,
      matched: true,
      question: 'object type volumes',
      routed_by: 'keyword',
      query_id: 'object_type_volumes',
      label: 'Object-type volumes',
      columns: ['object_type', 'objects'],
      rows: [{ object_type: 'Encounter', objects: 240 }],
      params: {}, // param-less query → {} object, NOT []
      provenance: 'Allow-listed query …',
    });
    expect(parsed.success).toBe(true);
  });

  it('parses a matched query carrying real params', () => {
    const parsed = arenaQueryResponseSchema.safeParse({
      available: true,
      matched: true,
      query_id: 'busiest_activities',
      columns: ['activity', 'events'],
      rows: [{ activity: 'Safety_Check', events: 705 }],
      params: { limit: 5 },
    });
    expect(parsed.success).toBe(true);
  });

  it('parses a no-match query with suggestions', () => {
    const parsed = arenaQueryResponseSchema.safeParse({
      available: true,
      matched: false,
      question: 'zzz',
      message: 'No allow-listed query matches that question.',
      suggestions: [{ id: 'busiest_activities', label: 'Busiest activities', description: '…' }],
    });
    expect(parsed.success).toBe(true);
  });

  it('parses a provenance-pinned narrative', () => {
    const parsed = arenaNarrativeResponseSchema.safeParse({
      available: true,
      narrative: 'Log scale: 4193 events. Sepsis conformance: 48.3%.',
      provenance: [{ claim: 'log scale', source: 'ocel.events', value: '4193 events' }],
      ai_polished: false,
      generated_label: 'AI-generated · pinned to live metrics',
    });
    expect(parsed.success).toBe(true);
  });

  it('parses a withheld map with its fitness evidence', () => {
    const parsed = arenaAuthorMapResponseSchema.safeParse({
      available: true,
      published: false,
      fitness: 0.4,
      precision: 0.5,
      fitness_floor: 0.8,
      reason: 'below_fitness_floor',
      generated_label: 'Withheld — below the fitness floor',
      invented_edges: [{ object_type: 'Encounter', source: 'a', target: 'b' }],
      missing_edges: [{ object_type: 'Encounter', source: 'c', target: 'd', frequency: 12 }],
    });
    expect(parsed.success).toBe(true);
  });

  it('parses a pending-draft acknowledgement', () => {
    const parsed = arenaDraftResponseSchema.safeParse({
      available: true,
      drafted: true,
      action: { action_uuid: 'u', action_type: 'propose_pdsa_cycle', status: 'draft', approved: false, approval_uuid: 'a' },
    });
    expect(parsed.success).toBe(true);
    // The action must reflect the ungated draft state.
    if (parsed.success && parsed.data.action) {
      expect(parsed.data.action.approved).toBe(false);
      expect(parsed.data.action.status).toBe('draft');
    }
  });
});
