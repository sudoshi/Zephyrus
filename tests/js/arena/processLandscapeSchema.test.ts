import { describe, expect, it } from 'vitest';

import {
  arenaProcessLandscapeIndexSchema,
  arenaProcessModelDetailSchema,
} from '@/features/arena/schema';
import { buildReferenceProcessGraph } from '@/Components/arena/referenceProcessLayout';

const summary = {
  process_id: 'A8',
  process_number: 8,
  domain_code: 'A',
  domain_name: 'Access and demand',
  name: 'ED admission and boarding',
  core_interaction: 'Encounter + Admit Decision + Placement Request + Bed Stay + Unit',
  improvement_question: 'What is waiting?',
  evidence_grade: 'H+S+A',
  priority: 'P1',
  interaction_pattern: 'movement-and-occupancy',
  implementation_wave: 'wave_1',
  current_readiness: 'partial_projection',
  readiness_note: 'Assignment is not occupancy.',
} as const;

describe('Arena OCEL process landscape contract', () => {
  it('accepts the 93-model reference index and keeps observed claims false', () => {
    const models = Array.from({ length: 93 }, (_, index) => ({
      ...summary,
      process_id: `X${index + 1}`,
      process_number: index + 1,
    }));

    const parsed = arenaProcessLandscapeIndexSchema.parse({
      available: true,
      document: {
        id: 'ACUM-OPS-OCEL-001',
        version: '1.0',
        date: '2026-07-09',
        catalog_count: 93,
        requested_count_note: 'The catalog contains 93 rows.',
        data_basis: 'seeded_reference_models',
        observed_claim: false,
      },
      counts: { models: 93, domains: 8, priorities: { P1: 1 }, readiness: { partial_projection: 1 }, waves: { wave_1: 1 } },
      projection: {
        projected_events: 4193,
        projected_objects: 1156,
        source_systems: 4,
        declared_object_types: 12,
        emitted_object_types: 7,
        target_object_types: 52,
        catalog_activities: 48,
      },
      domains: [{ code: 'A', name: 'Access and demand', count: 10 }],
      models,
    });

    expect(parsed.models).toHaveLength(93);
    expect(parsed.document.observed_claim).toBe(false);
  });

  it('lays out a seeded reference flow as connected React Flow elements', () => {
    const detail = arenaProcessModelDetailSchema.parse({
      available: true,
      data_basis: 'seeded_reference_model',
      observed_claim: false,
      model: { ...summary, core_objects: ['Encounter', 'Bed Stay'], source_document: 'ACUM-OPS-OCEL-001', catalog_version: 1 },
      nodes: [
        { node_key: 'a8-01', activity: 'placement-requested', label: 'Placement Requested', node_kind: 'trigger', ordinal: 1, object_types: ['Encounter'], required: true, source_basis: 'reference', observed_count: 0 },
        { node_key: 'a8-02', activity: 'physical-occupancy-started', label: 'Physical Occupancy Started', node_kind: 'outcome', ordinal: 2, object_types: ['Encounter', 'Bed Stay'], required: true, source_basis: 'reference', observed_count: 0 },
      ],
      edges: [
        { edge_key: 'a8-edge-01', source_node_key: 'a8-01', target_node_key: 'a8-02', label: 'moves / occupies', relationship_type: 'movement-and-occupancy', ordinal: 1, is_exception: false },
      ],
    });

    const graph = buildReferenceProcessGraph(detail);
    expect(graph.nodes).toHaveLength(2);
    expect(graph.edges).toHaveLength(1);
    expect(graph.edges[0].source).toBe('a8-01');
    expect(graph.edges[0].target).toBe('a8-02');
  });
});
