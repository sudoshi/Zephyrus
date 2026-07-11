// resources/js/Components/arena/review/reviewGraph.ts
//
// The performance overlay for the Flow Review map. Where the Study's
// buildOcdfgGraph colours arcs by object type (an analytical lens), the Review
// colours them by *barrier severity* — the operational lens. The band is the
// engine's own classification (a ranked barrier's severity), never a threshold
// this layer invents, so status colour stays earned: only transitions that ARE
// barriers glow; everything else rests in a calm neutral. Arc width still tracks
// volume; the label carries the median wait from the performance index, so the
// map reads as a duration heat-map. Selecting a barrier lights its map_focus and
// dims the rest.
import dagre from 'dagre';
import { MarkerType, type Edge, type Node } from 'reactflow';

import type { ArenaHandoff, ArenaOcdfg } from '@/features/arena/schema';
import type { BarrierSeverity, RankedBarrier } from '@/features/arena/reviewSchema';
import { SEVERITY_RANK } from './format';

const NODE_W = 184;
const NODE_H = 56;

// Band → colour, straight off the healthcare status vars (teal/amber/coral/sky),
// resolved globally on :root so both themes swap for free. Matches the rail's
// severity stripe so a barrier reads the same in the list and on the map.
export const BAND_COLOR: Record<BarrierSeverity, string> = {
  critical: 'var(--critical)',
  warning: 'var(--warning)',
  watch: 'var(--info)',
};

// A transition within band — calm slate, deliberately NOT green (colouring every
// healthy arc a status hue is the alarm-fatigue anti-pattern the canon forbids).
// A raw hex is fine here for the same reason objectTypePalette's are: a sanctioned
// data-viz value, not a status Tailwind class.
const NEUTRAL_EDGE = '#94A3B8';

export const PERF_BANDS: Array<{ severity: BarrierSeverity; label: string }> = [
  { severity: 'critical', label: 'Critical' },
  { severity: 'warning', label: 'Warning' },
  { severity: 'watch', label: 'Watch' },
];

export interface ReviewNodeData {
  activity: string;
  frequency: number;
  objectTypes: string[];
  severity: BarrierSeverity | null;
  dimmed: boolean;
  [key: string]: unknown;
}

export interface ReviewEdgeData {
  frequency: number;
  medianSec: number | null;
  severity: BarrierSeverity | null;
  barrierId: string | null;
  [key: string]: unknown;
}

interface AggregatedEdge {
  source: string;
  target: string;
  frequency: number;
  objectTypes: Set<string>;
}

// Worst (lowest-rank) of two severities; used to fold many barriers onto one
// element. `null` means "no barrier here".
function worst(a: BarrierSeverity | null, b: BarrierSeverity): BarrierSeverity {
  if (a === null) return b;
  return SEVERITY_RANK[b] < SEVERITY_RANK[a] ? b : a;
}

const edgeId = (source: string, target: string) => `${source} ${target}`;

export function buildReviewGraph(
  ocdfg: ArenaOcdfg,
  performanceIndex: readonly ArenaHandoff[],
  barriers: readonly RankedBarrier[],
  selectedBarrier: RankedBarrier | null,
): { nodes: Node<ReviewNodeData>[]; edges: Edge<ReviewEdgeData>[] } {
  // 1. Fold multi-object-type arcs between the same two activities into one.
  const aggregated = new Map<string, AggregatedEdge>();
  for (const edge of ocdfg.edges) {
    const key = edgeId(edge.source, edge.target);
    const existing = aggregated.get(key);
    if (existing) {
      existing.frequency += edge.frequency;
      existing.objectTypes.add(edge.object_type);
    } else {
      aggregated.set(key, {
        source: edge.source,
        target: edge.target,
        frequency: edge.frequency,
        objectTypes: new Set([edge.object_type]),
      });
    }
  }

  // 2. Median wait per transition — the worst (max) across object types on it, so
  // a synchronising hand-off reads as its slowest lifecycle.
  const perfByTransition = new Map<string, number>();
  for (const handoff of performanceIndex) {
    const key = edgeId(handoff.source, handoff.target);
    perfByTransition.set(key, Math.max(perfByTransition.get(key) ?? 0, handoff.median_sec));
  }

  // 3. Severity per element = the worst barrier that focuses it. Most elements
  // stay null (calm); only barriers glow.
  const edgeSeverity = new Map<string, BarrierSeverity>();
  const nodeSeverity = new Map<string, BarrierSeverity>();
  for (const barrier of barriers) {
    for (const id of barrier.map_focus.edge_ids) {
      edgeSeverity.set(id, worst(edgeSeverity.get(id) ?? null, barrier.severity));
    }
    for (const id of barrier.map_focus.node_ids) {
      nodeSeverity.set(id, worst(nodeSeverity.get(id) ?? null, barrier.severity));
    }
  }
  // First barrier owning each element, for click-to-select from the map.
  const barrierByEdge = new Map<string, string>();
  const barrierByNode = new Map<string, string>();
  for (const barrier of barriers) {
    for (const id of barrier.map_focus.edge_ids) if (!barrierByEdge.has(id)) barrierByEdge.set(id, barrier.id);
    for (const id of barrier.map_focus.node_ids) if (!barrierByNode.has(id)) barrierByNode.set(id, barrier.id);
  }

  // 4. Focus/dim set from the selection. Lit nodes include a focused edge's
  // endpoints so a hand-off barrier keeps both its activities bright.
  const focusEdges = new Set(selectedBarrier?.map_focus.edge_ids ?? []);
  const focusNodes = new Set(selectedBarrier?.map_focus.node_ids ?? []);
  for (const id of focusEdges) {
    const agg = aggregated.get(id);
    if (agg) {
      focusNodes.add(agg.source);
      focusNodes.add(agg.target);
    }
  }
  const hasFocus = focusEdges.size > 0 || focusNodes.size > 0;

  // 5. Deterministic dagre layout (identical geometry to the Study map).
  const graph = new dagre.graphlib.Graph();
  graph.setGraph({ rankdir: 'LR', nodesep: 28, ranksep: 80, marginx: 16, marginy: 16 });
  graph.setDefaultEdgeLabel(() => ({}));
  for (const node of ocdfg.nodes) graph.setNode(node.id, { width: NODE_W, height: NODE_H });
  for (const edge of aggregated.values()) {
    if (graph.hasNode(edge.source) && graph.hasNode(edge.target)) graph.setEdge(edge.source, edge.target);
  }
  dagre.layout(graph);

  const maxFreq = Math.max(1, ...ocdfg.edges.map((edge) => edge.frequency));

  const nodes: Node<ReviewNodeData>[] = ocdfg.nodes.map((node) => {
    const positioned = graph.node(node.id);
    return {
      id: node.id,
      type: 'reviewActivity',
      position: {
        x: (positioned?.x ?? 0) - NODE_W / 2,
        y: (positioned?.y ?? 0) - NODE_H / 2,
      },
      data: {
        activity: node.activity,
        frequency: node.frequency,
        objectTypes: node.object_types,
        severity: nodeSeverity.get(node.id) ?? null,
        dimmed: hasFocus && !focusNodes.has(node.id),
      },
    };
  });

  const edges: Edge<ReviewEdgeData>[] = [...aggregated.values()].map((edge) => {
    const id = edgeId(edge.source, edge.target);
    const severity = edgeSeverity.get(id) ?? null;
    const medianSec = perfByTransition.get(id) ?? null;
    const dimmed = hasFocus && !focusEdges.has(id);
    const color = severity ? BAND_COLOR[severity] : NEUTRAL_EDGE;

    const base = 1 + Math.round((edge.frequency / maxFreq) * 5);
    const strokeWidth = severity ? base + 2 : base;
    const strokeOpacity = dimmed ? 0.18 : severity ? 1 : 0.6;
    const label = medianSec !== null ? fmtWait(medianSec) : '';

    return {
      id,
      source: edge.source,
      target: edge.target,
      type: 'default',
      style: { stroke: color, strokeWidth, strokeOpacity },
      markerEnd: { type: MarkerType.ArrowClosed, color, width: 14, height: 14 },
      label,
      labelShowBg: false,
      labelStyle: { fill: color, fontSize: 10, fontVariantNumeric: 'tabular-nums', opacity: dimmed ? 0.4 : 1 },
      data: { frequency: edge.frequency, medianSec, severity, barrierId: barrierByEdge.get(id) ?? null },
    };
  });

  return { nodes, edges };
}

// Compact wait for an arc label. Mirrors format.fmtDuration's breakpoints but
// stays terse (no rounding to a bare "s" below 90s — arcs are hand-offs, minutes+).
function fmtWait(seconds: number): string {
  if (seconds < 5400) return `${Math.round(seconds / 60)}m`;
  return `${(seconds / 3600).toFixed(1)}h`;
}

// Resolve a click on a node/edge back to the barrier that owns it, if any.
export function barrierForNode(
  barriers: readonly RankedBarrier[],
  nodeId: string,
): string | null {
  return barriers.find((b) => b.map_focus.node_ids.includes(nodeId))?.id ?? null;
}

export function barrierForEdge(
  barriers: readonly RankedBarrier[],
  edgeIdValue: string,
): string | null {
  return barriers.find((b) => b.map_focus.edge_ids.includes(edgeIdValue))?.id ?? null;
}
