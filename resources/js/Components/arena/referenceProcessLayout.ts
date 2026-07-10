import dagre from 'dagre';
import { MarkerType, type Edge, type Node } from 'reactflow';

import type { ArenaProcessModelDetail } from '@/features/arena/schema';
import { MIXED_EDGE_COLOR, objectTypeColor } from './objectTypePalette';

const NODE_WIDTH = 216;
const NODE_HEIGHT = 92;

export interface ReferenceProcessNodeData {
  activity: string;
  label: string;
  kind: 'trigger' | 'event' | 'decision' | 'exception' | 'outcome';
  ordinal: number;
  objectTypes: string[];
  orderedTypes: string[];
  observedCount: number;
  [key: string]: unknown;
}

export interface ReferenceProcessEdgeData {
  relationshipType: string;
  exception: boolean;
  [key: string]: unknown;
}

export function buildReferenceProcessGraph(detail: ArenaProcessModelDetail): {
  nodes: Node<ReferenceProcessNodeData>[];
  edges: Edge<ReferenceProcessEdgeData>[];
  orderedTypes: string[];
} {
  const orderedTypes = [...detail.model.core_objects];
  const graph = new dagre.graphlib.Graph();
  graph.setGraph({ rankdir: 'LR', nodesep: 36, ranksep: 82, marginx: 24, marginy: 24 });
  graph.setDefaultEdgeLabel(() => ({}));

  for (const node of detail.nodes) {
    graph.setNode(node.node_key, { width: NODE_WIDTH, height: NODE_HEIGHT });
  }
  for (const edge of detail.edges) {
    graph.setEdge(edge.source_node_key, edge.target_node_key);
  }
  dagre.layout(graph);

  const nodes: Node<ReferenceProcessNodeData>[] = detail.nodes.map((node) => {
    const positioned = graph.node(node.node_key);
    return {
      id: node.node_key,
      type: 'referenceActivity',
      position: {
        x: (positioned?.x ?? 0) - NODE_WIDTH / 2,
        y: (positioned?.y ?? 0) - NODE_HEIGHT / 2,
      },
      data: {
        activity: node.activity,
        label: node.label,
        kind: node.node_kind,
        ordinal: node.ordinal,
        objectTypes: node.object_types,
        orderedTypes,
        observedCount: node.observed_count,
      },
    };
  });

  const nodesByKey = new Map(detail.nodes.map((node) => [node.node_key, node]));
  const edges: Edge<ReferenceProcessEdgeData>[] = detail.edges.map((edge) => {
    const sourceTypes = nodesByKey.get(edge.source_node_key)?.object_types ?? [];
    const targetTypes = nodesByKey.get(edge.target_node_key)?.object_types ?? [];
    const commonTypes = sourceTypes.filter((type) => targetTypes.includes(type));
    const color = commonTypes.length === 1
      ? objectTypeColor(commonTypes[0], orderedTypes)
      : MIXED_EDGE_COLOR;

    return {
      id: edge.edge_key,
      source: edge.source_node_key,
      target: edge.target_node_key,
      type: 'smoothstep',
      label: edge.label,
      labelShowBg: true,
      labelBgPadding: [5, 3],
      labelBgBorderRadius: 3,
      labelStyle: { fill: color, fontSize: 10, fontWeight: 600 },
      labelBgStyle: { fill: 'var(--healthcare-surface, #ffffff)', fillOpacity: 0.94 },
      style: {
        stroke: edge.is_exception ? '#9B1B30' : color,
        strokeWidth: edge.is_exception ? 2.5 : 2,
        strokeDasharray: edge.is_exception ? '6 4' : undefined,
      },
      markerEnd: {
        type: MarkerType.ArrowClosed,
        color: edge.is_exception ? '#9B1B30' : color,
        width: 16,
        height: 16,
      },
      data: { relationshipType: edge.relationship_type, exception: edge.is_exception },
    };
  });

  return { nodes, edges, orderedTypes };
}
