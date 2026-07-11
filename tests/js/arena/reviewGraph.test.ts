// tests/js/arena/reviewGraph.test.ts
//
// Unit test for the performance overlay's pure core. buildReviewGraph is where
// the map stops being an object-type diagram and becomes a bottleneck heat-map:
// arcs coloured by the engine's barrier severity (not a FE threshold), labelled
// with median wait, and dimmed to the selected barrier's focus. Tested directly
// so the logic is pinned without rendering reactflow in jsdom.
import { describe, expect, it } from 'vitest';
import { REVIEW_FIXTURE } from '@/features/arena/reviewFixture';
import { BAND_COLOR, buildReviewGraph } from '@/Components/arena/review/reviewGraph';

if (!REVIEW_FIXTURE.available) throw new Error('fixture must be available');
const { map, performance_index: perf, barriers } = REVIEW_FIXTURE;
const flowBarrier = barriers.find((b) => b.id === 'flow-4w-assign-transport')!;

function edge(built: ReturnType<typeof buildReviewGraph>, id: string) {
  return built.edges.find((e) => e.id === id)!;
}
function node(built: ReturnType<typeof buildReviewGraph>, id: string) {
  return built.nodes.find((n) => n.id === id)!;
}

describe('buildReviewGraph', () => {
  it('colours only barrier transitions by severity; the rest rest neutral', () => {
    const built = buildReviewGraph(map, perf, barriers, null);

    // The critical flow barrier lives on assign_bed → transport.
    const flagged = edge(built, 'assign_bed transport');
    expect(flagged.data?.severity).toBe('critical');
    expect(flagged.style?.stroke).toBe(BAND_COLOR.critical);

    // The watch (human) barrier lives on bed_request → assign_bed.
    expect(edge(built, 'bed_request assign_bed').data?.severity).toBe('watch');

    // A transition no barrier touches stays neutral — no status hue.
    const calm = edge(built, 'ed_arrival bed_request');
    expect(calm.data?.severity).toBeNull();
    expect(calm.style?.stroke).not.toBe(BAND_COLOR.critical);
  });

  it('labels perf-tracked arcs with their median wait', () => {
    const built = buildReviewGraph(map, perf, barriers, null);
    // 16560s ≈ 4.6h
    expect(edge(built, 'assign_bed transport').label).toBe('4.6h');
    // 3600s = 60m
    expect(edge(built, 'ed_arrival bed_request').label).toBe('60m');
    // No perf row for direct_add → bed_request → no label.
    expect(edge(built, 'direct_add bed_request').label).toBe('');
  });

  it('carries the care barrier onto its focused node even with no edge', () => {
    const built = buildReviewGraph(map, perf, barriers, null);
    // care-ed-sepsis-abx focuses node ed_arrival (edge_ids empty).
    expect(node(built, 'ed_arrival').data?.severity).toBe('warning');
  });

  it('dims everything outside the selected barrier’s focus', () => {
    const built = buildReviewGraph(map, perf, barriers, flowBarrier);

    // In focus (the hand-off and its endpoints) stays bright.
    expect(node(built, 'assign_bed').data?.dimmed).toBe(false);
    expect(node(built, 'transport').data?.dimmed).toBe(false);
    expect(edge(built, 'assign_bed transport').data?.severity).toBe('critical');
    expect(edge(built, 'assign_bed transport').style?.strokeOpacity).toBe(1);

    // Out of focus dims.
    expect(node(built, 'ed_arrival').data?.dimmed).toBe(true);
    expect(edge(built, 'ed_arrival bed_request').style?.strokeOpacity).toBeLessThan(0.3);
  });

  it('no selection means nothing is dimmed', () => {
    const built = buildReviewGraph(map, perf, barriers, null);
    expect(built.nodes.every((n) => n.data?.dimmed === false)).toBe(true);
  });
});
